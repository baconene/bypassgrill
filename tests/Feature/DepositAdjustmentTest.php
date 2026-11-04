<?php

namespace Tests\Feature;

use App\Models\DepositAdjustment;
use App\Models\DepositControl;
use App\Models\FinancialTransaction;
use App\Models\PaymentTender;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DepositAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private PaymentTender $cash;

    private PaymentTender $gcash;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('cashier', 'web');
        Role::findOrCreate('admin', 'web');
        $this->cash = PaymentTender::create(['name' => 'Cash']);
        $this->gcash = PaymentTender::create(['name' => 'GCash']);
    }

    private function user(string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role);

        return $user;
    }

    /** A submitted shift whose counted money is $variance away from the books (negative = short). */
    private function submittedShift(User $cashier, float $variance): DepositControl
    {
        FinancialTransaction::create(['user_id' => $cashier->id, 'payment_tender_id' => $this->cash->id, 'type' => 'payment',
            'amount' => 1000, 'description' => 'Sales', 'transacted_at' => now()->subHour()]);
        $this->actingAs($cashier)->postJson('/api/v1/deposit-controls', ['opening_cash' => 0])->assertCreated();
        $shift = DepositControl::latest('id')->firstOrFail();
        $this->postJson("/api/v1/deposit-controls/{$shift->id}/close")->assertOk();
        $this->postJson("/api/v1/deposit-controls/{$shift->id}/reconcile", [
            'drawer_cash' => 0, 'shift_gcash' => 0, 'lockbox_total' => 1000 + $variance, 'total_gcash' => 0,
        ])->assertOk();
        $this->assertEquals($variance, $shift->fresh()->reconciliation['overall_variance']);

        return $shift->fresh();
    }

    private function balance(): float
    {
        return (float) FinancialTransaction::where('type', '!=', 'order')->get()
            ->sum(fn ($t) => in_array($t->type, ['payment', 'income_adjustment']) ? $t->amount : -$t->amount);
    }

    public function test_a_shortage_needs_the_requesting_admin_plus_two_other_admins_then_the_requester_posts_it(): void
    {
        $cashier = $this->user('cashier', 'Cashier Ana');
        $requester = $this->user('admin', 'Admin Ben');
        $second = $this->user('admin', 'Admin Cy');
        $third = $this->user('admin', 'Admin Dee');
        $shift = $this->submittedShift($cashier, -50);

        $adjustment = $this->actingAs($requester)->postJson("/api/v1/deposit-controls/{$shift->id}/adjustments", ['reason' => 'Counted twice, ₱50 missing'])
            ->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('amount', '-50.00')
            ->assertJsonCount(1, 'approvals')->assertJsonPath('approvals.0.user.name', 'Admin Ben')->json();
        $id = $adjustment['id'];

        // The requester cannot approve again, a cashier cannot approve, and nothing posts before three approvals
        $this->postJson("/api/v1/deposit-controls/adjustments/{$id}/approve")->assertForbidden();
        $this->actingAs($cashier)->postJson("/api/v1/deposit-controls/adjustments/{$id}/approve")->assertForbidden();
        $this->actingAs($second)->postJson("/api/v1/deposit-controls/adjustments/{$id}/approve")->assertOk()->assertJsonPath('status', 'pending');
        $this->postJson("/api/v1/deposit-controls/adjustments/{$id}/approve")->assertConflict();
        $this->actingAs($requester)->postJson("/api/v1/deposit-controls/adjustments/{$id}/post", ['payment_tender_id' => $this->cash->id])->assertConflict();

        $this->actingAs($third)->postJson("/api/v1/deposit-controls/adjustments/{$id}/approve")->assertOk()
            ->assertJsonPath('status', 'approved')->assertJsonCount(3, 'approvals')
            ->assertJsonPath('approvals.2.user.name', 'Admin Dee');

        // Only the requester picks the tender and posts
        $this->postJson("/api/v1/deposit-controls/adjustments/{$id}/post", ['payment_tender_id' => $this->cash->id])->assertForbidden();
        $before = $this->balance();
        $this->actingAs($requester)->postJson("/api/v1/deposit-controls/adjustments/{$id}/post", ['payment_tender_id' => $this->cash->id])
            ->assertOk()->assertJsonPath('status', 'posted')->assertJsonPath('tender.name', 'Cash');

        $entry = FinancialTransaction::findOrFail(DepositAdjustment::findOrFail($id)->financial_transaction_id);
        $this->assertSame('expense', $entry->type);
        $this->assertEquals(50, $entry->amount);
        $this->assertSame($this->cash->id, $entry->payment_tender_id);
        $this->assertStringContainsString('Deposit reconciliation – Shift #'.$shift->id.' short ₱50.00', $entry->description);
        $this->assertStringContainsString('Approved by Admin Ben, Admin Cy, Admin Dee', $entry->notes);
        // The books now agree with the counted money
        $this->assertEquals($before - 50, $this->balance());
        $this->assertEquals(950, $this->balance());

        // The posted entry is locked in the financial ledger
        $this->patchJson("/api/v1/financial-transactions/{$entry->id}", ['amount' => 1])->assertUnprocessable();
        $this->deleteJson("/api/v1/financial-transactions/{$entry->id}")->assertUnprocessable();
        // And the shift cannot be balanced twice
        $this->postJson("/api/v1/deposit-controls/{$shift->id}/adjustments", ['reason' => 'again'])->assertConflict();
    }

    public function test_an_overage_posts_as_an_income_adjustment_and_a_cashier_request_needs_three_admins(): void
    {
        $cashier = $this->user('cashier', 'Cashier Ana');
        [$a, $b, $c] = [$this->user('admin', 'A1'), $this->user('admin', 'A2'), $this->user('admin', 'A3')];
        $shift = $this->submittedShift($cashier, 20);

        $id = $this->actingAs($cashier)->postJson("/api/v1/deposit-controls/{$shift->id}/adjustments", ['reason' => 'Extra ₱20 in the lockbox'])
            ->assertCreated()->assertJsonCount(0, 'approvals')->json('id');
        $this->actingAs($a)->postJson("/api/v1/deposit-controls/adjustments/{$id}/approve")->assertJsonPath('status', 'pending');
        $this->actingAs($b)->postJson("/api/v1/deposit-controls/adjustments/{$id}/approve")->assertJsonPath('status', 'pending');
        $this->actingAs($c)->postJson("/api/v1/deposit-controls/adjustments/{$id}/approve")->assertJsonPath('status', 'approved');
        $this->actingAs($cashier)->postJson("/api/v1/deposit-controls/adjustments/{$id}/post", ['payment_tender_id' => $this->gcash->id])->assertOk();

        $entry = FinancialTransaction::findOrFail(DepositAdjustment::findOrFail($id)->financial_transaction_id);
        $this->assertSame('income_adjustment', $entry->type);
        $this->assertEquals(20, $entry->amount);
        $this->assertSame($this->gcash->id, $entry->payment_tender_id);
        $this->assertEquals(1020, $this->balance());
    }

    public function test_rejected_requests_can_be_raised_again_and_a_new_shift_supersedes_open_requests(): void
    {
        $cashier = $this->user('cashier', 'Cashier Ana');
        $admin = $this->user('admin', 'Admin Ben');
        $other = $this->user('admin', 'Admin Cy');
        $shift = $this->submittedShift($cashier, -10);

        $this->actingAs($cashier)->postJson("/api/v1/deposit-controls/{$shift->id}/adjustments", ['reason' => 'x'])->assertUnprocessable();
        $id = $this->postJson("/api/v1/deposit-controls/{$shift->id}/adjustments", ['reason' => 'Short ten'])->assertCreated()->json('id');
        $this->postJson("/api/v1/deposit-controls/{$shift->id}/adjustments", ['reason' => 'Duplicate'])->assertConflict();
        $this->actingAs($admin)->postJson("/api/v1/deposit-controls/adjustments/{$id}/reject", ['reason' => 'Recount first'])
            ->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('rejecter.name', 'Admin Ben');

        $again = $this->actingAs($cashier)->postJson("/api/v1/deposit-controls/{$shift->id}/adjustments", ['reason' => 'Recounted, still short'])->assertCreated()->json('id');
        $this->actingAs($admin)->postJson("/api/v1/deposit-controls/adjustments/{$again}/approve")->assertOk();

        // Starting the next shift carries the difference forward, so the open request lapses
        $this->actingAs($cashier)->postJson('/api/v1/deposit-controls', ['opening_cash' => 0])->assertCreated();
        $this->assertSame('superseded', DepositAdjustment::findOrFail($again)->status);
        $this->actingAs($other)->postJson("/api/v1/deposit-controls/adjustments/{$again}/approve")->assertConflict();
        $this->actingAs($cashier)->postJson("/api/v1/deposit-controls/{$shift->id}/adjustments", ['reason' => 'Too late'])->assertConflict();

        $this->getJson('/api/v1/deposit-controls')->assertOk()->assertJsonPath('adjustable_shift_id', null)
            ->assertJsonPath('required_approvals', 3);
    }

    public function test_a_balanced_shift_and_strangers_cannot_request(): void
    {
        $cashier = $this->user('cashier', 'Cashier Ana');
        $otherCashier = $this->user('cashier', 'Cashier Bo');
        $shift = $this->submittedShift($cashier, 0);
        $this->postJson("/api/v1/deposit-controls/{$shift->id}/adjustments", ['reason' => 'Nothing to fix'])->assertConflict();

        $short = DepositControl::create(['user_id' => $cashier->id, 'opened_at' => now(), 'closed_at' => now(), 'submitted_at' => now(),
            'opening_snapshot' => ['running_balance' => 0], 'closing_snapshot' => ['running_balance' => 0], 'reconciliation' => ['overall_variance' => -5]]);
        $this->actingAs($otherCashier)->postJson("/api/v1/deposit-controls/{$short->id}/adjustments", ['reason' => 'Not my shift'])->assertForbidden();
    }
}
