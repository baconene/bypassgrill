<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OperatingInsightsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::findOrCreate('admin', 'web'));
        $this->actingAs($this->admin);
        $this->travelTo(CarbonImmutable::parse('2026-11-27 10:00', 'Asia/Manila'));
    }

    public function test_forecast_uses_all_weekday_history_and_plans_lower_for_rain_far_from_payday(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Asia/Manila'));
        $category = Category::create(['name' => 'Grill', 'slug' => 'grill']);
        $product = Product::create(['name' => 'Ribs', 'category_id' => $category->id, 'price' => 100, 'is_active' => true]);
        foreach (['2026-11-13' => 50, '2026-11-06' => 10, '2026-10-23' => 20, '2026-10-16' => 70, '2026-07-24' => 90] as $day => $units) {
            $order = new Order(['user_id' => $this->admin->id, 'status' => 'completed', 'payment_status' => 'paid']);
            $order->created_at = $day.' 17:00:00';
            $order->save();
            $order->items()->create(['product_id' => $product->id, 'quantity' => $units, 'unit_price' => 100, 'subtotal' => $units * 100]);
            $order->items()->create(['product_id' => $product->id, 'quantity' => 90, 'unit_price' => 100, 'subtotal' => 9000, 'status' => 'cancelled']);
            $this->ledger($day, $units * 100);
        }
        $this->fakeWeather();

        $this->getJson('/api/v1/reports/production-forecast')->assertOk()
            ->assertJsonPath('days.0.date', '2026-11-20')->assertJsonPath('days.1.date', '2026-11-21')
            ->assertJsonPath('days.2.date', '2026-11-22')
            ->assertJsonPath('days.0.basis', 'rain_away_matched')
            ->assertJsonPath('days.0.lower_demand_signal', true)
            ->assertJsonPath('days.0.weekday_rank.days', 5)->assertJsonPath('days.0.weekday_rank.lowest', 1000)
            ->assertJsonPath('days.0.weekday_rank.typical_low', 7000)->assertJsonPath('days.0.weekday_rank.typical_high', 9000)
            ->assertJsonPath('days.0.units', 15)->assertJsonPath('days.0.weekday_units', 80)
            ->assertJsonPath('days.0.rain_chance', 80)->assertJsonPath('days.0.products.0.suggested', 15)
            ->assertJsonPath('days.0.products.0.busy', 20)
            ->assertJsonPath('days.0.net_sales', 1500)
            ->assertJsonPath('days.0.net_sales_samples', [
                ['date' => '2026-10-23', 'net_sales' => 2000],
                ['date' => '2026-11-06', 'net_sales' => 1000],
            ])
            ->assertJsonPath('days.1.units', null)->assertJsonPath('days.1.net_sales_samples', []);
    }

    public function test_review_reconciles_signed_payments_and_compares_staffing(): void
    {
        $this->ledger('2026-11-20', 500);
        $this->ledger('2026-11-20', -100);
        $this->ledger('2026-11-20', 800, 'income_adjustment');
        $this->ledger('2026-11-13', 200);
        $this->ledger('2026-11-06', 150);
        $this->ledger('2026-10-30', 100);
        $this->fakeWeather();

        $save = '/api/v1/reports/operating-staffing';
        $this->putJson($save, ['date' => '2026-11-20', 'headcount' => 3, 'staff_hours' => 12])->assertNoContent();
        $this->putJson($save, ['date' => '2026-11-20', 'headcount' => 2, 'staff_hours' => 8])->assertNoContent();
        $this->putJson($save, ['date' => '2026-11-06', 'headcount' => 1, 'staff_hours' => 4])->assertNoContent();
        $this->putJson($save, ['date' => '2026-11-20', 'headcount' => 1, 'staff_hours' => 25])->assertUnprocessable();
        $this->putJson($save, ['date' => '2026-11-27', 'headcount' => 1, 'staff_hours' => 2])->assertUnprocessable();

        $this->getJson('/api/v1/reports/operating-review?date=2026-11-20')->assertOk()
            ->assertJsonPath('net_sales', 400)->assertJsonPath('previous_week.net_sales', 200)
            ->assertJsonPath('typical.net', 400)->assertJsonPath('same_payday_status.net', 150)
            ->assertJsonPath('different_payday_status.net', 150)->assertJsonPath('same_payday_and_weather.net', 150)
            ->assertJsonPath('different_weather_same_payday.net', null)
            ->assertJsonPath('staffing.net_per_hour', 50)->assertJsonPath('staffing.matched_days', 1)
            ->assertJsonPath('staffing.typical_per_hour', null);
        $this->assertDatabaseCount('service_day_staffing', 2);
        $this->actingAs(User::factory()->create())->putJson($save, [])->assertForbidden();
    }

    public function test_sales_insights_marks_a_historical_low_including_later_and_older_fridays(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00', 'Asia/Manila'));
        foreach (['2026-11-20' => 5475, '2026-11-27' => 8000, '2026-10-30' => 10000,
            '2026-07-24' => 11000, '2026-07-17' => 12000, '2026-11-21' => 100] as $date => $net) {
            $this->ledger($date, $net);
        }

        $this->fakeWeather();
        $this->getJson('/api/v1/reports/sales-insights?date=2026-11-20')->assertOk()
            ->assertJsonPath('weekday_rank.is_lowest', true)->assertJsonPath('weekday_rank.rank', 1)
            ->assertJsonPath('weekday_rank.days', 5)->assertJsonPath('weekday_rank.lowest', 5475)
            ->assertJsonPath('weekday_rank.typical_low', 11000)->assertJsonPath('weekday_rank.typical_high', 12000)
            ->assertJsonPath('payday_distance', 5)->assertJsonPath('lower_demand_signal', true);
    }

    private function ledger(string $day, int $amount, string $type = 'payment'): void
    {
        FinancialTransaction::create(['user_id' => $this->admin->id, 'type' => $type,
            'amount' => $amount, 'description' => 'Fixture', 'transacted_at' => $day.' 16:00:00']);
    }

    private function fakeWeather(): void
    {
        Http::fake(['weather.visualcrossing.com/*' => function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $last = basename($path);
            $forecast = str_contains($last, ',');
            $today = CarbonImmutable::now('Asia/Manila');
            $days = $forecast ? [$today->toDateString(), $today->addDay()->toDateString(), $today->addDays(2)->toDateString()] : [$last];

            return Http::response(['days' => array_map(fn ($date) => ['hours' => array_map(
                fn ($hour) => [
                    'datetimeEpoch' => CarbonImmutable::parse($date, 'Asia/Manila')->startOfDay()->addHours($hour)->timestamp,
                    'precip' => in_array($date, ['2026-11-20', '2026-11-13', '2026-11-06', '2026-10-23']) ? 1 : 0,
                    'precipprob' => $forecast ? 80 : 0,
                    'source' => $forecast ? 'fcst' : 'obs',
                ], range(15, 21))], $days)]);
        }]);
    }
}
