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
    private function submittedShift(User $cashier, float $variance, float $sales = 1000): DepositControl
    {
        FinancialTransaction::create(['user_id' => $cashier->id, 'payment_tender_id' => $this->cash->id, 'type' => 'payment',
            'amount' => $sales, 'description' => 'Sales', 'transacted_at' => now()->subHour()]);
        $this->actingAs($cashier)->postJson('/api/v1/deposit-controls', ['opening_cash' => 0])->assertCreated();
        $shift = DepositControl::latest('id')->firstOrFail();
        $this->postJson("/api/v1/deposit-controls/{$shift->id}/close")->assertOk();
        $this->postJson("/api/v1/deposit-controls/{$shift->id}/reconcile", [
            'drawer_cash' => 0, 'shift_gcash' => 0, 'lockbox_total' => $sales + $variance, 'total_gcash' => 0,
        ])->assertOk();
        $this->assertEquals($variance, $shift->fresh()->reconciliation['overall_variance']);

        return $shift->fresh();
    }

    private function balance(): float
    {
        return (float) FinancialTransaction::where('type', '!=', 'order')->get()
            ->sum(fn ($t) => in_array($t->type, ['payment', 'income_adjustment']) ? $t->amount : -$t->amount);
    }

    public function test_one_time_seeder_balances_the_1107_shortage_without_approvals_and_only_once(): void
    {
        $cashier = $this->user('cashier', 'Cashier Ana');
        $this->user('admin', 'Admin Ben');
        $shift = $this->submittedShift($cashier, -1107, 3000);
        $before = $this->balance();

        $this->seed(\Database\Seeders\Nov14ShiftShortageAdjustmentSeeder::class);

        $adjustment = $shift->adjustments()->sole();
        $this->assertSame('posted', $adjustment->status);
        $this->assertEquals(-1107, $adjustment->amount);
        $this->assertCount(0, $adjustment->approvals, 'No approvals are invented for the waived request.');
        $this->assertSame('Cash', $adjustment->tender->name);
        $this->assertSame('expense', $adjustment->transaction->type);
        $this->assertStringContainsString('no admin approvals were collected', $adjustment->transaction->notes);
        $this->assertEquals($before - 1107, $this->balance());

        // A second run changes nothing
        try {
            $this->seed(\Database\Seeders\Nov14ShiftShortageAdjustmentSeeder::class);
            $this->fail('Expected the second run to refuse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }
        $this->assertSame(1, DepositAdjustment::count());
        $this->assertEquals($before - 1107, $this->balance());
    }

    public function test_one_time_seeder_refuses_a_shift_with_a_different_shortage(): void
    {
        $cashier = $this->user('cashier', 'Cashier Ana');
        $this->user('admin', 'Admin Ben');
        $this->submittedShift($cashier, -50);
        $before = $this->balance();

        $this->expectException(\RuntimeException::class);
        try {
            $this->seed(\Database\Seeders\Nov14ShiftShortageAdjustmentSeeder::class);
        } finally {
            $this->assertSame(0, DepositAdjustment::count());
            $this->assertEquals($before, $this->balance());
        }
    }

    private function tenderBalance(PaymentTender $tender): float
    {
        return round((float) FinancialTransaction::where('payment_tender_id', $tender->id)->where('type', '!=', 'order')->get()
            ->sum(fn ($t) => in_array($t->type, ['payment', 'income_adjustment']) ? $t->amount : -$t->amount), 2);
    }

    private function rebalanceSeeder(int $shiftId): \Database\Seeders\Nov15TenderRebalanceSeeder
    {
        return new class($shiftId) extends \Database\Seeders\Nov15TenderRebalanceSeeder
        {
            public function __construct(int $shiftId)
            {
                $this->shiftId = $shiftId;
                $this->countedCash = 1200.00;
                $this->countedGcash = 250.00;
                $this->transfer = 100.00;
            }
        };
    }

    public function test_tender_rebalance_seeder_matches_the_count_keeps_later_sales_and_records_the_transfer(): void
    {
        $cashier = $this->user('cashier', 'Cashier Ana');
        $admin = $this->user('admin', 'Admin Ben');
        foreach ([[$this->cash, 1000], [$this->gcash, 500]] as [$tender, $amount]) {
            FinancialTransaction::create(['user_id' => $cashier->id, 'payment_tender_id' => $tender->id, 'type' => 'payment',
                'amount' => $amount, 'description' => 'Sales', 'transacted_at' => now()->subHour()]);
        }
        $this->actingAs($cashier)->postJson('/api/v1/deposit-controls', ['opening_cash' => 0])->assertCreated();
        $shift = DepositControl::latest('id')->firstOrFail();
        $this->postJson("/api/v1/deposit-controls/{$shift->id}/close")->assertOk();
        $this->postJson("/api/v1/deposit-controls/{$shift->id}/reconcile", [
            'drawer_cash' => 0, 'shift_gcash' => 0, 'lockbox_total' => 1200, 'total_gcash' => 250,
        ])->assertOk();
        $this->assertEquals(-50, $shift->fresh()->reconciliation['overall_variance']);

        // Last night: the ₱50 shortage posted against Cash. This morning: a ₱30 cash sale.
        $this->travel(5)->minutes();
        $expense = FinancialTransaction::create(['user_id' => $admin->id, 'payment_tender_id' => $this->cash->id, 'type' => 'expense',
            'amount' => 50, 'description' => 'Deposit reconciliation', 'transacted_at' => now()]);
        $shift->adjustments()->create(['requested_by' => $admin->id, 'amount' => -50, 'reason' => 'Short', 'status' => 'posted',
            'payment_tender_id' => $this->cash->id, 'financial_transaction_id' => $expense->id, 'posted_by' => $admin->id, 'posted_at' => now()]);
        FinancialTransaction::create(['user_id' => $cashier->id, 'payment_tender_id' => $this->cash->id, 'type' => 'payment',
            'amount' => 30, 'description' => 'Morning sale', 'transacted_at' => now()]);
        $this->travel(1)->minutes();
        $total = $this->balance();

        $this->rebalanceSeeder($shift->id)->run();

        // Cash: 1200 counted + 30 since − 100 transfer; GCash: 250 counted + 100 transfer
        $this->assertEquals(1130, $this->tenderBalance($this->cash));
        $this->assertEquals(350, $this->tenderBalance($this->gcash));
        $this->assertEquals($total, $this->balance(), 'Rebalancing and the transfer move money between tenders only.');
        $this->assertSame(2, FinancialTransaction::where('description', 'like', 'Transfer %')->count());

        try {
            $this->rebalanceSeeder($shift->id)->run();
            $this->fail('Expected the second run to refuse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already rebalanced', $e->getMessage());
        }
        $this->assertEquals(1130, $this->tenderBalance($this->cash));
    }

    public function test_tender_rebalance_seeder_refuses_before_the_overall_shortage_is_posted(): void
    {
        $cashier = $this->user('cashier', 'Cashier Ana');
        $this->user('admin', 'Admin Ben');
        $shift = $this->submittedShift($cashier, -50, 1250);
        $shift->update(['reconciliation' => [...$shift->reconciliation, 'lockbox_total' => 1200, 'total_gcash' => 0]]);
        $before = FinancialTransaction::count();

        $seeder = new class($shift->id) extends \Database\Seeders\Nov15TenderRebalanceSeeder
        {
            public function __construct(int $shiftId)
            {
                $this->shiftId = $shiftId;
                $this->countedCash = 1200.00;
                $this->countedGcash = 0.00;
            }
        };

        try {
            $seeder->run();
            $this->fail('Expected the seeder to refuse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no posted overall adjustment', $e->getMessage());
        }
        $this->assertSame($before, FinancialTransaction::count());
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

    public function test_after_a_rejection_the_requester_asks_for_another_approval_keeping_the_approvals_given(): void
    {
        $cashier = $this->user('cashier', 'Cashier Ana');
        $ben = $this->user('admin', 'Admin Ben');
        $joe = $this->user('admin', 'Admin Joe');
        $tess = $this->user('admin', 'Test Admin 1');
        $shift = $this->submittedShift($cashier, 2351.44);

        // Ben asks (approval 1), Joe approves (2), Tess rejects
        $id = $this->actingAs($ben)->postJson("/api/v1/deposit-controls/{$shift->id}/adjustments", ['reason' => 'Unrecorded cash sales'])->assertCreated()->json('id');
        $this->actingAs($joe)->postJson("/api/v1/deposit-controls/adjustments/{$id}/approve")->assertOk();
        $this->actingAs($joe)->postJson("/api/v1/deposit-controls/adjustments/{$id}/reject", ['reason' => 'Changed my mind'])->assertConflict();
        $this->actingAs($tess)->postJson("/api/v1/deposit-controls/adjustments/{$id}/reject", ['reason' => 'Check the receipts'])
            ->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('rejecter.name', 'Test Admin 1')
            ->assertJsonPath('rejection_history.0.name', 'Test Admin 1')->assertJsonCount(2, 'approvals');

        // While rejected: nobody can approve, only the requester decides, and no second request can be raised
        $this->actingAs($tess)->postJson("/api/v1/deposit-controls/adjustments/{$id}/approve")->assertConflict();
        $this->actingAs($joe)->postJson("/api/v1/deposit-controls/adjustments/{$id}/reopen")->assertForbidden();
        $this->actingAs($ben)->postJson("/api/v1/deposit-controls/{$shift->id}/adjustments", ['reason' => 'Duplicate'])->assertConflict();

        // Ask for another approval: Ben and Joe's approvals are kept, so one more is enough
        $this->actingAs($ben)->postJson("/api/v1/deposit-controls/adjustments/{$id}/reopen")
            ->assertOk()->assertJsonPath('status', 'pending')->assertJsonCount(2, 'approvals')
            ->assertJsonPath('rejecter', null)->assertJsonCount(1, 'rejection_history');
        // The admin who rejected may change their mind
        $this->actingAs($tess)->postJson("/api/v1/deposit-controls/adjustments/{$id}/approve")
            ->assertOk()->assertJsonPath('status', 'approved')->assertJsonCount(3, 'approvals')
            ->assertJsonPath('approvals.2.user.name', 'Test Admin 1');
        $this->actingAs($ben)->postJson("/api/v1/deposit-controls/adjustments/{$id}/post", ['payment_tender_id' => $this->cash->id])
            ->assertOk()->assertJsonPath('status', 'posted');
        $this->assertEqualsWithDelta(3351.44, $this->balance(), 0.001);
    }

    public function test_keeping_a_rejection_closes_it_and_a_new_shift_supersedes_open_requests(): void
    {
        $cashier = $this->user('cashier', 'Cashier Ana');
        $admin = $this->user('admin', 'Admin Ben');
        $other = $this->user('admin', 'Admin Cy');
        $shift = $this->submittedShift($cashier, -10);

        $this->actingAs($cashier)->postJson("/api/v1/deposit-controls/{$shift->id}/adjustments", ['reason' => 'x'])->assertUnprocessable();
        $id = $this->postJson("/api/v1/deposit-controls/{$shift->id}/adjustments", ['reason' => 'Short ten'])->assertCreated()->json('id');
        $this->postJson("/api/v1/deposit-controls/{$shift->id}/adjustments", ['reason' => 'Duplicate'])->assertConflict();
        $this->actingAs($admin)->postJson("/api/v1/deposit-controls/adjustments/{$id}/reject", ['reason' => 'Recount first'])
            ->assertOk()->assertJsonPath('status', 'rejected');

        // Keep it rejected: closed for good, and the books are untouched
        $this->actingAs($cashier)->postJson("/api/v1/deposit-controls/adjustments/{$id}/keep")->assertOk()->assertJsonPath('status', 'closed');
        $this->postJson("/api/v1/deposit-controls/adjustments/{$id}/reopen")->assertConflict();
        $this->assertEquals(1000, $this->balance());

        // A fresh request can be raised after keeping the rejection
        $again = $this->postJson("/api/v1/deposit-controls/{$shift->id}/adjustments", ['reason' => 'Recounted, still short'])->assertCreated()->json('id');
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
