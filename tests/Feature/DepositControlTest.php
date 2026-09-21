<?php

namespace Tests\Feature;

use App\Models\DepositControl;
use App\Models\FinancialTransaction;
use App\Models\PaymentTender;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DepositControlTest extends TestCase
{
    use RefreshDatabase;

    private function cashier(): User
    {
        Role::findOrCreate('cashier', 'web');
        $user = User::factory()->create();
        $user->assignRole('cashier');

        return $user;
    }

    private function transaction(User $user, ?PaymentTender $tender, string $type, float $amount, ?string $at = null): FinancialTransaction
    {
        return FinancialTransaction::create([
            'user_id' => $user->id, 'payment_tender_id' => $tender?->id,
            'type' => $type, 'amount' => $amount, 'description' => 'Test ledger entry',
            'transacted_at' => $at ?? now(),
        ]);
    }

    public function test_snapshots_are_frozen_and_counts_reconcile_without_double_counting_lockbox(): void
    {
        $this->travelTo(now()->setTime(9, 0));
        $user = $this->cashier();
        $cash = PaymentTender::create(['name' => 'Cash']);
        $gcash = PaymentTender::create(['name' => 'GCash']);
        $prior = $this->transaction($user, $cash, 'payment', 1000, now()->subDay()->toDateTimeString());
        $this->actingAs($user)->postJson('/api/v1/deposit-controls')->assertUnprocessable()
            ->assertJsonValidationErrors('opening_cash');
        $this->postJson('/api/v1/deposit-controls', ['opening_cash' => 250.5])->assertCreated()
            ->assertJsonPath('opening_snapshot.running_balance', 1000)
            ->assertJsonPath('opening_snapshot.net_balance', 0)
            ->assertJsonPath('opening_snapshot.opening_cash', 250.5);
        $shift = DepositControl::firstOrFail();
        $this->postJson('/api/v1/deposit-controls', ['opening_cash' => 0])->assertConflict();
        $this->travel(8)->hours();
        $this->transaction($user, $cash, 'payment', 500);
        $this->transaction($user, $gcash, 'payment', 200);
        $this->transaction($user, $cash, 'expense', 50);
        $this->transaction($user, $cash, 'income_adjustment', 20);
        $this->transaction($user, $cash, 'payroll', 100);
        $this->transaction($user, $cash, 'asset_deduction', 10);
        $this->transaction($user, $cash, 'payout_share', 30);
        $this->transaction($user, null, 'expense', 5);
        $this->transaction($user, $cash, 'order', 9999);
        $this->transaction($user, $cash, 'payment', 999, now()->addDay()->toDateTimeString());
        $this->postJson("/api/v1/deposit-controls/{$shift->id}/close")->assertOk()
            ->assertJsonPath('closing_snapshot.running_balance', 1525)
            ->assertJsonPath('closing_snapshot.net_balance', 525)
            ->assertJsonPath('closing_snapshot.payroll_deductions', 100);
        $prior->update(['amount' => 2000]);
        $this->assertEquals(1000, $shift->fresh()->opening_snapshot['running_balance']);
        $this->assertEquals(1525, $shift->fresh()->closing_snapshot['running_balance']);
        $this->postJson("/api/v1/deposit-controls/{$shift->id}/close")->assertConflict();
        $payload = ['drawer_cash' => 320, 'shift_gcash' => 200, 'lockbox_total' => 1320,
            'total_gcash' => 200, 'notes' => 'Drawer transferred into lockbox'];
        $this->postJson("/api/v1/deposit-controls/{$shift->id}/reconcile", $payload)->assertOk()
            ->assertJsonPath('reconciliation.shift_actual', 520)
            ->assertJsonPath('reconciliation.shift_variance', -5)
            ->assertJsonPath('reconciliation.overall_actual', 1520)
            ->assertJsonPath('reconciliation.overall_variance', -5)
            ->assertJsonPath('reconciliation.shift_net', 525);
        $this->postJson("/api/v1/deposit-controls/{$shift->id}/reconcile", $payload)->assertConflict();
        $this->postJson('/api/v1/deposit-controls', ['opening_cash' => 0])->assertCreated();
    }

    public function test_permissions_sequence_and_incomplete_counts_are_rejected(): void
    {
        $owner = $this->cashier();
        $other = $this->cashier();
        $cash = PaymentTender::create(['name' => 'Cash']);
        $this->actingAs($owner)->postJson('/api/v1/deposit-controls', ['opening_cash' => 0])->assertCreated();
        $id = DepositControl::firstOrFail()->id;
        $payload = ['drawer_cash' => 0, 'shift_gcash' => 0, 'lockbox_total' => 0, 'total_gcash' => 0];
        $this->postJson("/api/v1/deposit-controls/$id/reconcile", $payload)->assertConflict();
        $this->actingAs($other)->postJson("/api/v1/deposit-controls/$id/close")->assertForbidden();
        $this->actingAs($owner)->postJson("/api/v1/deposit-controls/$id/close")->assertOk();
        foreach (array_keys($payload) as $field) {
            $missing = $payload;
            unset($missing[$field]);
            $this->postJson("/api/v1/deposit-controls/$id/reconcile", $missing)->assertUnprocessable();
            foreach ([-1, 'invalid', '0.001', 10000000000] as $value) {
                $this->postJson("/api/v1/deposit-controls/$id/reconcile", array_replace($payload, [$field => $value]))->assertUnprocessable();
            }
        }
        $this->postJson("/api/v1/deposit-controls/$id/reconcile", array_replace($payload, ['drawer_cash' => 100, 'lockbox_total' => 50]))->assertUnprocessable();
        $this->actingAs($other)->postJson("/api/v1/deposit-controls/$id/reconcile", $payload)->assertForbidden();
        Role::findOrCreate('auditor', 'web');
        $auditor = User::factory()->create()->assignRole('auditor');
        $this->actingAs($auditor)->getJson('/api/v1/deposit-controls')->assertOk();
        $this->postJson('/api/v1/deposit-controls', ['opening_cash' => 0])->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/deposit-controls')->assertForbidden();
    }

    public function test_overnight_shift_uses_cumulative_difference_and_preserves_inactive_tenders(): void
    {
        $this->travelTo(now()->setTime(23, 0));
        $user = $this->cashier();
        $cash = PaymentTender::create(['name' => 'Cash', 'is_active' => false]);
        $this->transaction($user, $cash, 'payment', 100.25);
        $this->actingAs($user)->postJson('/api/v1/deposit-controls', ['opening_cash' => 0])->assertCreated();
        $id = DepositControl::firstOrFail()->id;
        $this->travel(2)->hours();
        $this->transaction($user, $cash, 'payment', 50.15);
        $this->postJson("/api/v1/deposit-controls/$id/close")->assertOk()
            ->assertJsonPath('closing_snapshot.net_balance', 50.15);
        $this->postJson("/api/v1/deposit-controls/$id/reconcile", [
            'drawer_cash' => 50.15, 'shift_gcash' => 0,
            'lockbox_total' => 150.40, 'total_gcash' => 0,
        ])->assertOk()->assertJsonPath('reconciliation.shift_net', 50.15)
            ->assertJsonPath('reconciliation.shift_variance', 0)
            ->assertJsonPath('reconciliation.overall_variance', 0);
    }

    public function test_cleanup_removes_only_legacy_deposit_controls_and_is_safe_to_repeat(): void
    {
        $user = $this->cashier();
        $cash = PaymentTender::create(['name' => 'Cash']);
        $entry = $this->transaction($user, $cash, 'payment', 100);
        $this->actingAs($user)->postJson('/api/v1/deposit-controls', ['opening_cash' => 0])->assertCreated();
        $current = DepositControl::firstOrFail();
        $legacy = $current->replicate();
        $legacy->active_slot = null;
        $snapshot = $legacy->opening_snapshot;
        unset($snapshot['version']);
        $legacy->opening_snapshot = $snapshot;
        $legacy->save();
        $migration = require database_path('migrations/2026_09_14_000002_clean_up_legacy_deposit_controls.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseMissing('deposit_controls', ['id' => $legacy->id]);
        $this->assertDatabaseHas('deposit_controls', ['id' => $current->id, 'active_slot' => 1]);
        $this->assertDatabaseHas('financial_transactions', ['id' => $entry->id, 'amount' => 100]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
