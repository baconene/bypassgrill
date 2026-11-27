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

    public function test_forecast_uses_all_weekday_sales_and_falls_back_when_backtest_is_too_small(): void
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
            ->assertJsonPath('month.0.date', '2026-11-20')
            ->assertJsonPath('month.0.weather_source', 'forecast')
            ->assertJsonPath('month.0.rain_chance', 80)
            ->assertJsonPath('month.3.date', '2026-11-27')
            ->assertJsonPath('month.3.weather_source', 'history')
            ->assertJsonPath('month.3.rain_chance', 75)
            ->assertJsonPath('month.3.net_sales', 4800)
            ->assertJsonPath('month.4.weather_source', 'unavailable')
            ->assertJsonPath('month.4.net_sales', null)
            ->assertJsonPath('model.training_days', 5)->assertJsonPath('model.weather_days', 5)
            ->assertJsonPath('model.use_context', false)
            ->assertJsonPath('days.0.weekday_rank.days', 5)->assertJsonPath('days.0.weekday_rank.lowest', 1000)
            ->assertJsonPath('days.0.weekday_rank.typical_low', 7000)->assertJsonPath('days.0.weekday_rank.typical_high', 9000)
            ->assertJsonPath('days.0.units', 48)->assertJsonPath('days.0.weekday_units', 48)
            ->assertJsonPath('days.0.rain_chance', 80)->assertJsonPath('days.0.products.0.suggested', 48)
            ->assertJsonPath('days.0.products.0.busy', 70)
            ->assertJsonPath('days.0.net_sales', 4800)->assertJsonCount(5, 'days.0.net_sales_samples')
            ->assertJsonPath('days.0.net_sales_samples.0.date', '2026-07-24')
            ->assertJsonPath('days.0.net_sales_samples.0.condition', 'clear')
            ->assertJsonPath('days.0.net_sales_samples.2.condition', 'rain')
            ->assertJsonPath('days.0.net_sales_samples.2.rain_mm', 7)
            ->assertJsonPath('days.1.units', null)->assertJsonPath('days.1.net_sales_samples', []);
    }

    public function test_daily_batch_freezes_the_forecast_and_scores_only_completed_service_dates(): void
    {
        $this->fakeWeather();
        foreach (['2026-11-20' => 1000, '2026-11-13' => 3000, '2026-11-21' => 1000, '2026-11-14' => 1000] as $date => $sales) {
            $this->ledger($date, $sales);
        }

        $this->artisan('forecast:capture')->assertSuccessful();
        $this->assertDatabaseHas('production_forecast_snapshots', [
            'forecast_as_of' => '2026-11-27', 'service_date' => '2026-11-27',
            'estimated_net_sales' => 2000, 'weather_source' => 'forecast',
            'rain_chance' => 80, 'payday_distance' => 3, 'result' => 'pending',
        ]);
        $rows = \Illuminate\Support\Facades\DB::table('production_forecast_snapshots')->where('forecast_as_of', '2026-11-27')->count();
        $this->ledger('2026-11-20', 500);
        $this->ledger('2026-11-27', 2200);
        $this->ledger('2026-11-27', -200);
        $this->artisan('forecast:capture')->assertSuccessful();
        $this->assertDatabaseCount('production_forecast_snapshots', $rows);
        $this->assertDatabaseHas('production_forecast_snapshots', [
            'forecast_as_of' => '2026-11-27', 'service_date' => '2026-11-27',
            'estimated_net_sales' => 2000, 'actual_net_sales' => null, 'result' => 'pending',
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00', 'Asia/Manila'));
        $this->artisan('forecast:capture')->assertSuccessful();
        $this->assertDatabaseHas('production_forecast_snapshots', [
            'forecast_as_of' => '2026-11-27', 'service_date' => '2026-11-27',
            'estimated_net_sales' => 2000, 'actual_net_sales' => 2000, 'sales_error' => 0, 'result' => 'hit',
        ]);
        $this->ledger('2026-11-28', 1350);
        $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00', 'Asia/Manila'));
        $this->artisan('forecast:capture')->assertSuccessful();
        $this->assertDatabaseHas('production_forecast_snapshots', [
            'forecast_as_of' => '2026-11-28', 'service_date' => '2026-11-28', 'actual_net_sales' => 1350,
            'sales_error' => 350, 'baseline_error' => 350, 'result' => 'miss',
        ]);
        $this->getJson('/api/v1/reports/production-forecast')->assertOk()
            ->assertJsonPath('accuracy.hits', 1)->assertJsonPath('accuracy.scored', 2)
            ->assertJsonPath('accuracy.days.0.result', 'no_sales_record')
            ->assertJsonPath('accuracy.days.1.result', 'miss')
            ->assertJsonPath('accuracy.days.1.as_of', '2026-11-28')
            ->assertJsonPath('accuracy.days.2.result', 'hit');
    }

    public function test_june_replay_starts_empty_and_uses_only_records_known_before_each_run(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00', 'Asia/Manila'));
        foreach ([
            ['2026-05-29', '2026-05-29', 99999],
            ['2026-06-05', '2026-06-05', 1000],
            ['2026-06-12', '2026-06-12', 3000],
            ['2026-06-19', '2026-06-19', 2000],
            ['2026-06-26', '2026-06-26', 2200],
            ['2026-06-05', '2026-06-25', -400],
        ] as [$date, $entered, $amount]) {
            $transaction = new FinancialTransaction([
                'user_id' => $this->admin->id, 'type' => 'payment', 'amount' => $amount,
                'description' => 'Replay fixture', 'transacted_at' => $date.' 16:00:00',
            ]);
            $transaction->created_at = $entered.' 16:00:00';
            $transaction->save();
        }
        $this->fakeWeather();

        $this->artisan('forecast:replay')->assertSuccessful();
        $this->assertDatabaseHas('production_forecast_snapshots', [
            'run_type' => 'replay', 'forecast_as_of' => '2026-06-01', 'service_date' => '2026-06-05',
            'sample_days' => 0, 'estimated_net_sales' => null, 'actual_net_sales' => 600, 'result' => 'no_estimate',
        ]);
        $this->assertDatabaseHas('production_forecast_snapshots', [
            'run_type' => 'replay', 'forecast_as_of' => '2026-06-19', 'service_date' => '2026-06-19',
            'sample_days' => 2, 'estimated_net_sales' => 2000, 'actual_net_sales' => 2000, 'result' => 'hit',
        ]);
        $this->assertDatabaseHas('production_forecast_snapshots', [
            'run_type' => 'replay', 'forecast_as_of' => '2026-06-26', 'service_date' => '2026-06-26',
            'sample_days' => 3, 'estimated_net_sales' => 1866.67, 'actual_net_sales' => 2200, 'result' => 'miss',
        ]);
        $this->assertDatabaseHas('production_forecast_snapshots', [
            'run_type' => 'replay', 'forecast_as_of' => '2026-11-28', 'service_date' => '2026-11-28', 'result' => 'pending',
        ]);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'include=current'));
        $number = \Illuminate\Support\Facades\DB::table('production_forecast_snapshots')->count();
        $this->artisan('forecast:replay')->assertSuccessful();
        $this->assertDatabaseCount('production_forecast_snapshots', $number);
        $this->getJson('/api/v1/reports/predictions?type=replay')->assertOk()
            ->assertJsonPath('run_days', 181)->assertJsonPath('first_run', '2026-06-01')
            ->assertJsonPath('last_run', '2026-11-28')
            ->assertJsonPath('hits', 1)->assertJsonPath('misses', 1)
            ->assertJsonPath('no_estimate', 2);
        $this->getJson('/api/v1/reports/predictions?type=replay&as_of=2026-06-01')->assertOk()
            ->assertJsonPath('predictions.data.0.as_of', '2026-06-01');
        $this->getJson('/api/v1/reports/predictions?type=live')->assertOk()
            ->assertJsonPath('run_days', 0)->assertJsonPath('predictions.data', []);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/reports/predictions/replay')->assertForbidden();
    }

    public function test_weather_backfill_keeps_every_sale_with_or_without_weather(): void
    {
        $start = CarbonImmutable::parse('2026-11-20', 'Asia/Manila');
        for ($week = 0; $week < 14; $week++) {
            $this->ledger($start->subWeeks($week)->toDateString(), 6000);
        }
        $this->fakeWeather();
        $url = '/api/v1/reports/production-forecast';
        $this->getJson($url)->assertOk()->assertJsonPath('model.training_days', 14)
            ->assertJsonPath('model.weather_days', 12)->assertJsonPath('model.use_context', false)
            ->assertJsonPath('days.0.net_sales', 6000)
            ->assertJsonPath('days.0.net_sales_samples.0.rain_mm', null);
        $this->travel(11)->minutes();
        $this->getJson($url)->assertOk()->assertJsonPath('model.training_days', 14)
            ->assertJsonPath('model.weather_days', 14)->assertJsonPath('days.0.net_sales', 6000);
    }

    public function test_matching_is_used_only_after_it_beats_the_weekday_backtest(): void
    {
        $model = app(\App\Services\ProductionDemandModel::class);
        $history = [];
        for ($i = 0; $i < 10; $i++) {
            $rainy = $i % 2 === 0;
            $date = CarbonImmutable::parse('2026-07-04')->addWeeks($i)->toDateString();
            $history[$date] = [
                'date' => $date, 'weekday' => 6, 'rainy' => $rainy,
                'near_payday' => false, 'net_sales' => $rainy ? 1000 : 10000,
            ];
        }
        $result = $model->validate($history);
        $this->assertTrue($result['use_context']);
        $this->assertLessThan($result['weekday_mae'], $result['context_mae']);
        $dryWeights = $model->weights($history, ['rainy' => false, 'near_payday' => false], true);
        $this->assertCount(10, $dryWeights);
        $this->assertGreaterThan(5500, $model->estimate(array_column($history, 'net_sales', 'date'), $dryWeights));
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
                    'conditions' => 'Clear',
                ], range(15, 21))], $days)]);
        }]);
    }
}
