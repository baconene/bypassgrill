<?php

namespace Database\Seeders;

use App\Models\DepositAdjustment;
use App\Models\DepositControl;
use App\Models\FinancialTransaction;
use App\Models\PaymentTender;
use App\Models\User;
use App\Services\DepositAdjustmentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time seeder: balance the Nov 14, 2026 overall shortage of ₱1,107.00 without the usual three admin approvals.
 *
 * Posts the same records an approved adjustment would (a 'posted' deposit adjustment plus a Cash expense), with
 * no approvals recorded and the waiver written into the reason and the ledger notes, so the audit trail is honest.
 * It only touches the latest submitted shift, and only if that shift is short by exactly ₱1,107.00.
 *
 * Run once on production:
 *   php artisan db:seed --class=Nov14ShiftShortageAdjustmentSeeder --force
 */
class Nov14ShiftShortageAdjustmentSeeder extends Seeder
{
    private const EXPECTED_VARIANCE = -1107.00;

    private const TENDER = 'Cash';

    private const REASON = 'One-time balancing posted by seeder on owner instruction; the three admin approvals were waived for this shift only.';

    public function run(): void
    {
        DB::transaction(function () {
            $shiftId = DepositAdjustmentService::adjustableShiftId()
                ?? throw new RuntimeException('No submitted shift is open for balancing. Nothing was changed.');
            $shift = DepositControl::lockForUpdate()->findOrFail($shiftId);

            $variance = round((float) ($shift->reconciliation['overall_variance'] ?? 0), 2);
            if ($variance !== self::EXPECTED_VARIANCE) {
                throw new RuntimeException(sprintf(
                    'Shift #%d is off by ₱%s, not ₱%s. Nothing was changed.',
                    $shift->id, number_format($variance, 2), number_format(self::EXPECTED_VARIANCE, 2),
                ));
            }

            if ($shift->adjustments()->where('status', 'posted')->exists()) {
                throw new RuntimeException("Shift #{$shift->id} already has a posted adjustment. Nothing was changed.");
            }

            $owner = User::all()->first(fn (User $u) => DepositAdjustmentService::isSuperUser($u))
                ?? User::role('admin')->orderBy('id')->firstOrFail();
            $tender = PaymentTender::where('name', self::TENDER)->firstOrFail();
            $amount = abs(self::EXPECTED_VARIANCE);

            // A request already raised from the page is closed out rather than left waiting for approvals.
            $shift->adjustments()->whereIn('status', DepositAdjustment::OPEN_STATUSES)->update(['status' => 'superseded']);

            $transaction = FinancialTransaction::create([
                'type' => 'expense',
                'amount' => $amount,
                'payment_tender_id' => $tender->id,
                'user_id' => $owner->id,
                'transacted_at' => now(),
                'description' => sprintf('Deposit reconciliation – Shift #%d short ₱%s', $shift->id, number_format($amount, 2)),
                'notes' => sprintf(
                    'Overall balance adjustment for deposit control shift #%d (short by ₱%s). Posted directly by %s via one-time seeder; no admin approvals were collected. Reason: %s',
                    $shift->id, number_format($amount, 2), $owner->name, self::REASON,
                ),
            ]);

            $shift->adjustments()->create([
                'requested_by' => $owner->id,
                'amount' => self::EXPECTED_VARIANCE,
                'reason' => self::REASON,
                'status' => 'posted',
                'approved_at' => now(),
                'payment_tender_id' => $tender->id,
                'financial_transaction_id' => $transaction->id,
                'posted_by' => $owner->id,
                'posted_at' => now(),
            ]);

            $this->command?->info(sprintf(
                'Shift #%d balanced: posted a ₱%s %s expense (transaction #%d) as %s.',
                $shift->id, number_format($amount, 2), $tender->name, $transaction->id, $owner->name,
            ));
        });
    }
}
