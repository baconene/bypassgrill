<?php

namespace Tests\Feature;

use App\Models\DepositControl;
use App\Models\Employee;
use App\Models\FinancialTransaction;
use App\Models\Ingredient;
use App\Models\InventoryTransaction;
use App\Models\PayrollRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShiftChecklistTest extends TestCase
{
    use RefreshDatabase;

    private function cashier(): User
    {
        Role::findOrCreate('cashier', 'web');
        $user = User::factory()->create();
        $user->assignRole('cashier');

        return $user;
    }

    private function steps(array $payload): array
    {
        return collect($payload['steps'])->keyBy('key')->all();
    }

    public function test_every_step_starts_undone_on_a_quiet_day(): void
    {
        $payload = $this->actingAs($this->cashier())->getJson('/api/v1/shift-checklist')->assertOk()->json();

        $this->assertSame(0, $payload['done']);
        $this->assertSame(5, $payload['total']);
        $this->assertSame('Open the deposit control', $payload['next']);
        foreach ($payload['steps'] as $step) {
            $this->assertFalse($step['done']);
        }
        $this->assertSame([false, true, true, true, false], array_column($payload['steps'], 'manual'));
        $this->assertSame(['before', 'before', 'before', 'after', 'after'], array_column($payload['steps'], 'phase'));
        // Salary is released before the drawer is closed.
        $this->assertSame(
            ['open_deposit', 'stock', 'expenses', 'payroll', 'close_deposit'],
            array_column($payload['steps'], 'key'),
        );
    }

    public function test_steps_tick_themselves_from_what_the_shift_recorded(): void
    {
        $user = $this->cashier();
        $shift = DepositControl::create([
            'user_id' => $user->id, 'active_slot' => 1, 'opened_at' => now(),
            'opening_snapshot' => ['running_balance' => 0, 'opening_cash' => 500],
        ]);
        $item = Ingredient::create(['name' => 'Pork Ribs', 'unit' => 'kg', 'current_quantity' => 3]);
        InventoryTransaction::create([
            'ingredient_id' => $item->id, 'type' => 'adjustment', 'quantity' => 3,
            'old_quantity' => 0, 'new_quantity' => 3,
        ]);
        FinancialTransaction::create([
            'type' => 'expense', 'amount' => 250, 'description' => 'Ice',
            'user_id' => $user->id, 'transacted_at' => now(),
        ]);

        $steps = $this->steps($this->actingAs($user)->getJson('/api/v1/shift-checklist')->json());

        $this->assertTrue($steps['open_deposit']['done']);
        $this->assertTrue($steps['stock']['done']);
        $this->assertTrue($steps['expenses']['done']);
        $this->assertFalse($steps['close_deposit']['done']);
        $this->assertStringContainsString('1 stock movement', $steps['stock']['detail']);
        $this->assertStringContainsString('1 expense', $steps['expenses']['detail']);
        // Recorded activity proves these, so no hand tick is offered to undo.
        $this->assertFalse($steps['stock']['manual']);
        $this->assertFalse($steps['expenses']['manual']);

        $this->assertFalse($steps['payroll']['done']);

        $employee = Employee::create([
            'name' => 'Ana Cruz', 'position' => 'Cashier', 'base_rate' => 600,
            'is_active' => true, 'hired_at' => now()->subYear(),
        ]);
        PayrollRecord::create([
            'employee_id' => $employee->id, 'period_start' => now()->subWeek(), 'period_end' => now(),
            'days_worked' => 6, 'gross_pay' => 3600, 'deductions' => 0, 'net_pay' => 3600,
            'status' => 'paid', 'paid_at' => now(),
        ]);

        $shift->update(['closed_at' => now(), 'submitted_at' => now(), 'active_slot' => null]);
        $done = $this->getJson('/api/v1/shift-checklist')->json();
        $this->assertSame(5, $done['done']);
        $this->assertNull($done['next']);
        $this->assertStringContainsString('1 payroll record released', $this->steps($done)['payroll']['detail']);
        $this->assertFalse($this->steps($done)['payroll']['manual']);
    }

    public function test_a_step_with_nothing_to_record_can_be_ticked_and_untickeded_by_hand(): void
    {
        $user = $this->cashier();

        $payload = $this->actingAs($user)->postJson('/api/v1/shift-checklist', ['step' => 'expenses'])->assertOk()->json();
        $this->assertTrue($this->steps($payload)['expenses']['done']);
        $this->assertStringContainsString('nothing was spent', $this->steps($payload)['expenses']['detail']);
        $this->assertDatabaseHas('shift_checklist_marks', ['user_id' => $user->id, 'step' => 'expenses']);

        $payload = $this->postJson('/api/v1/shift-checklist', ['step' => 'expenses'])->assertOk()->json();
        $this->assertFalse($this->steps($payload)['expenses']['done']);
        $this->assertDatabaseCount('shift_checklist_marks', 0);
    }

    public function test_the_deposit_steps_cannot_be_ticked_by_hand(): void
    {
        $this->actingAs($this->cashier())
            ->postJson('/api/v1/shift-checklist', ['step' => 'close_deposit'])
            ->assertUnprocessable();
    }

    public function test_marks_belong_to_one_person_and_one_day(): void
    {
        $user = $this->cashier();
        $other = $this->cashier();

        $this->actingAs($user)->postJson('/api/v1/shift-checklist', ['step' => 'stock'])->assertOk();

        $this->assertFalse($this->steps($this->actingAs($other)->getJson('/api/v1/shift-checklist')->json())['stock']['done']);

        $this->travel(1)->day();
        $this->assertFalse($this->steps($this->actingAs($user)->getJson('/api/v1/shift-checklist')->json())['stock']['done']);
    }

    public function test_the_kitchen_cannot_see_the_checklist(): void
    {
        Role::findOrCreate('kitchen', 'web');
        $cook = User::factory()->create();
        $cook->assignRole('kitchen');

        $this->actingAs($cook)->getJson('/api/v1/shift-checklist')->assertForbidden();
    }

    public function test_payroll_waiting_to_be_released_is_called_out(): void
    {
        $employee = Employee::create([
            'name' => 'Ben Reyes', 'position' => 'Cook', 'base_rate' => 550,
            'is_active' => true, 'hired_at' => now()->subYear(),
        ]);
        PayrollRecord::create([
            'employee_id' => $employee->id, 'period_start' => now()->subWeek(), 'period_end' => now(),
            'days_worked' => 6, 'gross_pay' => 3300, 'deductions' => 0, 'net_pay' => 3300,
            'status' => 'approved',
        ]);

        $steps = $this->steps($this->actingAs($this->cashier())->getJson('/api/v1/shift-checklist')->json());

        $this->assertFalse($steps['payroll']['done']);
        $this->assertStringContainsString('1 payroll record waiting to be released', $steps['payroll']['detail']);
    }

    public function test_the_dashboard_carries_the_checklist(): void
    {
        $this->actingAs($this->cashier())->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('shiftChecklist.total', 5)->where('shiftChecklist.done', 0));
    }
}
