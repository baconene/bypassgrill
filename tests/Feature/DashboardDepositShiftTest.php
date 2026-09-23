<?php

namespace Tests\Feature;

use App\Models\DepositControl;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardDepositShiftTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('cashier', 'web');
        Role::findOrCreate('kitchen', 'web');
        $this->cashier = User::factory()->create(['name' => 'Rosa Santos']);
        $this->cashier->assignRole('cashier');
    }

    private function openShift(User $owner, ?Carbon $closedAt = null): DepositControl
    {
        return DepositControl::create([
            'user_id' => $owner->id,
            'active_slot' => 1,
            'opened_at' => Carbon::parse('2026-09-24 07:30:00'),
            'closed_at' => $closedAt,
            'opening_snapshot' => ['opening_cash' => 1500.0],
        ]);
    }

    private function shiftProp(User $as): ?array
    {
        return $this->actingAs($as)->get('/dashboard')->assertOk()
            ->viewData('page')['props']['depositShift'];
    }

    public function test_no_open_shift_reports_nothing(): void
    {
        $this->assertNull($this->shiftProp($this->cashier));
    }

    public function test_an_open_shift_names_who_opened_it(): void
    {
        $this->openShift($this->cashier);

        $shift = $this->shiftProp($this->cashier);

        $this->assertSame('Rosa Santos', $shift['user_name']);
        $this->assertSame('counting', $shift['stage']);
        $this->assertTrue($shift['is_mine']);
        $this->assertEquals(1500.0, $shift['opening_cash']);
    }

    public function test_another_cashiers_shift_is_not_reported_as_mine(): void
    {
        $other = User::factory()->create(['name' => 'Ben Cruz']);
        $other->assignRole('cashier');
        $this->openShift($other);

        $shift = $this->shiftProp($this->cashier);

        $this->assertSame('Ben Cruz', $shift['user_name']);
        $this->assertFalse($shift['is_mine'], 'Only the cashier who opened it may finish it.');
    }

    public function test_a_closed_but_unsubmitted_shift_is_awaiting_submission(): void
    {
        $this->openShift($this->cashier, Carbon::parse('2026-09-24 19:00:00'));

        $this->assertSame('awaiting_submission', $this->shiftProp($this->cashier)['stage']);
    }

    public function test_a_submitted_shift_is_no_longer_open(): void
    {
        // Submitting clears active_slot, which is what marks the one live shift.
        $this->openShift($this->cashier, Carbon::parse('2026-09-24 19:00:00'))
            ->update(['active_slot' => null, 'submitted_at' => now()]);

        $this->assertNull($this->shiftProp($this->cashier));
    }

    public function test_an_admin_sees_a_cashiers_open_shift(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->openShift($this->cashier);

        $this->assertSame('Rosa Santos', $this->shiftProp($admin)['user_name']);
    }

    public function test_staff_without_deposit_access_are_told_nothing(): void
    {
        $cook = User::factory()->create();
        $cook->assignRole('kitchen');
        $this->openShift($this->cashier);

        $this->assertNull($this->shiftProp($cook), 'The card is not shown to them either.');
    }

    public function test_a_missing_opening_cash_figure_does_not_break_the_dashboard(): void
    {
        DepositControl::create([
            'user_id' => $this->cashier->id,
            'active_slot' => 1,
            'opened_at' => now(),
            'opening_snapshot' => [],
        ]);

        $this->assertEquals(0.0, $this->shiftProp($this->cashier)['opening_cash']);
    }
}
