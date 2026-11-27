<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\User;
use App\Services\HolidayCalendar;
use App\Services\ProductionDemandModel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ForecastFactorsTest extends TestCase
{
    use RefreshDatabase;

    private function special(string $date): ?string
    {
        return app(HolidayCalendar::class)->specialDay(CarbonImmutable::parse($date, 'Asia/Manila'));
    }

    public function test_calendar_flags_long_weekends_their_eve_and_payday_peaks(): void
    {
        // Bonifacio Day (Mon Nov 30) on payday: Sat–Mon, plus the Friday before
        $this->assertSame('payday_long_weekend', $this->special('2026-11-27'));
        $this->assertSame('payday_long_weekend', $this->special('2026-11-28'));
        $this->assertSame('payday_long_weekend', $this->special('2026-11-30'));
        // All Saints'/Souls': Sat Oct 31 – Mon Nov 2, the Oct 30 payday is its eve
        $this->assertSame('payday_long_weekend', $this->special('2026-10-31'));
        // Independence Day Fri–Sun; the Jun 15 payday comes after it
        $this->assertSame('long_weekend', $this->special('2026-06-12'));
        $this->assertSame('long_weekend', $this->special('2026-06-14'));
        // A weekday holiday with no long weekend
        $this->assertSame('holiday', $this->special('2026-02-17'));
        // Ordinary weekends
        $this->assertSame('near_payday', $this->special('2026-07-17'));
        $this->assertNull($this->special('2026-07-25'));

        // A special working day is not a day off, so it never makes a long weekend
        Holiday::where('date', '2026-06-12')->update(['type' => 'special_working']);
        $this->assertSame('near_payday', $this->special('2026-06-13'));
    }

    /** Fridays for 15 weeks: regular days around ₱10,000, every third one a payday long weekend near ₱20,000. */
    private function peakHistory(): array
    {
        $history = [];
        for ($i = 0; $i < 15; $i++) {
            $date = CarbonImmutable::parse('2026-06-05')->addWeeks($i)->toDateString();
            $peak = $i % 3 === 2;
            $history[$date] = [
                'date' => $date, 'weekday' => 5, 'rainy' => null, 'near_payday' => $peak,
                'special' => $peak ? 'payday_long_weekend' : null,
                'net_sales' => ($peak ? 20000 : 10000) + ($i % 2 ? 300 : -300),
            ];
        }

        return $history;
    }

    public function test_model_learns_the_payday_long_weekend_uplift_and_is_chosen_when_it_wins(): void
    {
        $model = app(ProductionDemandModel::class);
        $history = $this->peakHistory();

        $uplifts = $model->uplifts($history);
        $this->assertSame(5, $uplifts['payday_long_weekend']['days']);
        $this->assertGreaterThan(1.5, $uplifts['payday_long_weekend']['uplift']);
        $this->assertSame(1.0, $uplifts['holiday']['uplift'], 'No examples, no change');

        $peak = $model->factorEstimate($history, '2026-09-25', 'payday_long_weekend', $uplifts, null);
        $regular = $model->factorEstimate($history, '2026-09-25', null, $uplifts, null);
        $this->assertGreaterThan(15000, $peak['estimate']);
        $this->assertEqualsWithDelta(10000, $regular['estimate'], 800);

        $validation = $model->validate($history);
        $this->assertSame('factors', $validation['mode']);
        $this->assertTrue($validation['use_context']);
        $this->assertLessThan($validation['weekday_mae'], $validation['factor_mae']);
    }

    public function test_recent_weeks_count_more_and_one_off_days_are_capped(): void
    {
        $model = app(ProductionDemandModel::class);
        $history = [];
        for ($i = 0; $i < 10; $i++) {
            $date = CarbonImmutable::parse('2026-06-06')->addWeeks($i)->toDateString();
            $history[$date] = ['date' => $date, 'weekday' => 6, 'rainy' => null, 'special' => null, 'net_sales' => 6000 + $i * 500];
        }
        $plainAverage = array_sum(array_column($history, 'net_sales')) / 10;
        $trend = $model->factorEstimate($history, '2026-08-15', null, $model->uplifts($history), null);
        $this->assertGreaterThan($plainAverage + 400, $trend['estimate'], 'Growth shows through');

        $history['2026-07-04']['net_sales'] = 90000; // a one-off catering day
        $capped = $model->factorEstimate($history, '2026-08-15', null, $model->uplifts($history), null);
        $this->assertLessThan($trend['estimate'] * 1.15, $capped['estimate']);
    }

    public function test_bias_corrects_a_forecast_that_keeps_running_low(): void
    {
        $model = app(ProductionDemandModel::class);
        $this->assertSame(1.0, $model->bias([1.2, 1.2]), 'Too few days to judge');
        $this->assertGreaterThan(1.05, $model->bias(array_fill(0, 12, 1.2)));
        $this->assertSame(1.15, $model->bias(array_fill(0, 12, 3.0)), 'Capped');
    }

    public function test_admins_manage_holidays(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('cashier', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        $this->actingAs($cashier)->get('/settings/holidays')->assertForbidden();
        $this->actingAs($admin)->get('/settings/holidays')->assertOk()
            ->assertInertia(fn ($page) => $page->component('settings/Holidays')->has('holidays', 39));

        $this->post('/settings/holidays', ['date' => '2026-09-08', 'name' => 'Town fiesta', 'type' => 'special'])
            ->assertSessionHasNoErrors();
        $this->assertSame('holiday', $this->special('2026-09-08'));
        $this->post('/settings/holidays', ['date' => '2026-09-08', 'name' => 'Duplicate', 'type' => 'special'])
            ->assertSessionHasErrors('date');

        $eid = Holiday::where('date', '2026-03-20')->firstOrFail();
        $this->assertFalse($eid->confirmed);
        $this->patch("/settings/holidays/{$eid->id}", ['date' => '2026-03-21', 'name' => "Eid'l Fitr", 'type' => 'regular', 'confirmed' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue($eid->fresh()->confirmed);
        $this->assertSame('2026-03-21', $eid->fresh()->date->toDateString());

        $this->delete("/settings/holidays/{$eid->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('holidays', ['id' => $eid->id]);
    }
}
