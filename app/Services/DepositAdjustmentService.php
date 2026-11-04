<?php

namespace App\Services;

use App\Models\DepositAdjustment;
use App\Models\DepositAdjustmentApproval;
use App\Models\DepositControl;
use App\Models\FinancialTransaction;
use App\Models\PaymentTender;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Balancing a shift's overall over/short.
 *
 * The cashier (or an admin) asks to balance the overall variance; three admins must approve; then the requester
 * picks the tender and the adjustment is posted using existing ledger types (over = income_adjustment,
 * short = expense) so every total in the system already counts it.
 *
 * Only the latest submitted shift can be balanced, and only before the next shift starts: the overall variance is
 * cumulative, so once a new shift opens, the same difference reappears in that shift's overall balance and an
 * open request here would post it twice. Open requests are marked superseded at that point.
 */
class DepositAdjustmentService
{
    public static function isAdmin(?User $user): bool
    {
        return (bool) $user?->hasRole('admin');
    }

    public static function isSuperUser(?User $user): bool
    {
        return strtolower((string) $user?->email) === 'john.adrian.bacon2@gmail.com';
    }

    /** The one shift that can still be balanced: the latest submitted shift, while no newer shift exists. */
    public static function adjustableShiftId(): ?int
    {
        $latest = DepositControl::orderByDesc('id')->first();

        return $latest && $latest->submitted_at !== null ? $latest->id : null;
    }

    public function request(DepositControl $shift, User $user, string $reason): DepositAdjustment
    {
        return DB::transaction(function () use ($shift, $user, $reason) {
            $shift = DepositControl::lockForUpdate()->findOrFail($shift->id);
            abort_unless($shift->user_id === $user->id || self::isAdmin($user) || self::isSuperUser($user), 403, 'Only the cashier who ran this shift or an admin can ask to balance it.');
            abort_unless($shift->submitted_at !== null, 409, 'Submit the counts before balancing the shift.');
            abort_unless(self::adjustableShiftId() === $shift->id, 409, 'Only the latest shift can be balanced, before the next shift starts. Its difference now shows in the newer shift.');
            $variance = round((float) ($shift->reconciliation['overall_variance'] ?? 0), 2);
            abort_if($variance == 0.0, 409, 'The overall balance already matches. There is nothing to adjust.');
            abort_if($shift->adjustments()->whereIn('status', [...DepositAdjustment::OPEN_STATUSES, 'posted'])->exists(), 409, 'This shift already has an adjustment in progress or posted. If it was rejected, ask for another approval or keep it rejected first.');

            $adjustment = $shift->adjustments()->create([
                'requested_by' => $user->id,
                'amount' => $variance,
                'reason' => $reason,
                'status' => 'pending',
            ]);

            // An admin raising the request is the first of the three approvals
            if (self::isAdmin($user)) {
                $adjustment->approvals()->create(['user_id' => $user->id, 'approved_at' => now()]);
            }

            return $this->fresh($adjustment);
        });
    }

    public function approve(DepositAdjustment $adjustment, User $user): DepositAdjustment
    {
        return DB::transaction(function () use ($adjustment, $user) {
            $adjustment = $this->lockOpen($adjustment);
            abort_unless(self::isAdmin($user), 403, 'Only admins can approve an adjustment.');
            abort_if($adjustment->requested_by === $user->id, 403, 'You raised this request, so your approval is already counted if you are an admin. Two other admins must approve it.');
            abort_if($adjustment->status === 'rejected', 409, 'This request was rejected. The requester can ask for another approval.');
            abort_if($adjustment->status !== 'pending', 409, 'This adjustment already has all its approvals.');
            abort_if($adjustment->approvals()->where('user_id', $user->id)->exists(), 409, 'You have already approved this adjustment.');

            DepositAdjustmentApproval::create(['deposit_adjustment_id' => $adjustment->id, 'user_id' => $user->id, 'approved_at' => now()]);

            if ($adjustment->approvals()->count() >= DepositAdjustment::REQUIRED_APPROVALS) {
                $adjustment->update(['status' => 'approved', 'approved_at' => now()]);
            }

            return $this->fresh($adjustment);
        });
    }

    public function reject(DepositAdjustment $adjustment, User $user, string $reason): DepositAdjustment
    {
        return DB::transaction(function () use ($adjustment, $user, $reason) {
            $adjustment = $this->lockOpen($adjustment);
            abort_unless(self::isAdmin($user), 403, 'Only admins can reject an adjustment.');
            abort_if($adjustment->requested_by === $user->id, 403, 'Cancel your own request instead of rejecting it.');
            abort_unless($adjustment->status === 'pending', 409, 'Only a request that is waiting for approvals can be rejected.');
            abort_if($adjustment->approvals()->where('user_id', $user->id)->exists(), 409, 'You have already approved this adjustment.');

            $history = $adjustment->rejection_history ?? [];
            $history[] = ['user_id' => $user->id, 'name' => $user->name, 'reason' => $reason, 'at' => now()->toIso8601String()];
            $adjustment->update([
                'status' => 'rejected', 'rejected_by' => $user->id, 'rejected_at' => now(),
                'rejection_reason' => $reason, 'rejection_history' => $history,
            ]);

            return $this->fresh($adjustment);
        });
    }

    /** After a rejection the requester asks for another approval: the approvals already given are kept. */
    public function reopen(DepositAdjustment $adjustment, User $user): DepositAdjustment
    {
        return DB::transaction(function () use ($adjustment, $user) {
            $adjustment = $this->lockOpen($adjustment);
            $this->authorizeRequester($adjustment, $user);
            abort_unless($adjustment->status === 'rejected', 409, 'Only a rejected request can be sent for another approval.');
            $approved = $adjustment->approvals()->count() >= DepositAdjustment::REQUIRED_APPROVALS;
            $adjustment->update([
                'status' => $approved ? 'approved' : 'pending', 'approved_at' => $approved ? now() : null,
                'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null,
            ]);

            return $this->fresh($adjustment);
        });
    }

    /** After a rejection the requester accepts it: the request is closed and the books stay as they are. */
    public function keepRejected(DepositAdjustment $adjustment, User $user): DepositAdjustment
    {
        return DB::transaction(function () use ($adjustment, $user) {
            $adjustment = $this->lockOpen($adjustment);
            $this->authorizeRequester($adjustment, $user);
            abort_unless($adjustment->status === 'rejected', 409, 'Only a rejected request can be kept rejected.');
            $adjustment->update(['status' => 'closed']);

            return $this->fresh($adjustment);
        });
    }

    public function cancel(DepositAdjustment $adjustment, User $user): DepositAdjustment
    {
        return DB::transaction(function () use ($adjustment, $user) {
            $adjustment = $this->lockOpen($adjustment);
            $this->authorizeRequester($adjustment, $user);
            $adjustment->update(['status' => 'cancelled']);

            return $this->fresh($adjustment);
        });
    }

    private function authorizeRequester(DepositAdjustment $adjustment, User $user): void
    {
        abort_unless($adjustment->requested_by === $user->id || self::isSuperUser($user), 403, 'Only the person who raised this request can do that.');
    }

    /** The requester chooses the tender once three admins have approved, which posts the adjustment. */
    public function post(DepositAdjustment $adjustment, User $user, int $tenderId): DepositAdjustment
    {
        return DB::transaction(function () use ($adjustment, $user, $tenderId) {
            $adjustment = $this->lockOpen($adjustment);
            abort_unless($adjustment->requested_by === $user->id || self::isSuperUser($user), 403, 'Only the person who raised this request can choose the tender and post it.');
            abort_unless($adjustment->status === 'approved', 409, 'This adjustment needs '.DepositAdjustment::REQUIRED_APPROVALS.' admin approvals before it can be posted.');
            $tender = PaymentTender::findOrFail($tenderId);

            $amount = (float) $adjustment->amount;
            $over = $amount > 0;
            $approvers = $adjustment->approvals()->with('user:id,name')->get()->pluck('user.name')->filter()->implode(', ');
            $shiftId = $adjustment->deposit_control_id;
            $label = $over ? 'over' : 'short';

            $transaction = FinancialTransaction::create([
                'type' => $over ? 'income_adjustment' : 'expense',
                'amount' => abs($amount),
                'payment_tender_id' => $tender->id,
                'user_id' => $user->id,
                'transacted_at' => now(),
                'description' => sprintf('Deposit reconciliation – Shift #%d %s ₱%s', $shiftId, $label, number_format(abs($amount), 2)),
                'notes' => sprintf(
                    'Overall balance adjustment for deposit control shift #%d (%s by ₱%s). Requested by %s. Approved by %s. Reason: %s',
                    $shiftId, $label, number_format(abs($amount), 2), $adjustment->requester?->name ?? 'unknown', $approvers, $adjustment->reason,
                ),
            ]);

            $adjustment->update([
                'status' => 'posted',
                'payment_tender_id' => $tender->id,
                'financial_transaction_id' => $transaction->id,
                'posted_by' => $user->id,
                'posted_at' => now(),
            ]);

            return $this->fresh($adjustment);
        });
    }

    /** When a new shift starts, open requests on earlier shifts can no longer be posted safely. */
    public function supersedeOpenRequests(): void
    {
        DepositAdjustment::whereIn('status', DepositAdjustment::OPEN_STATUSES)->update(['status' => 'superseded']);
    }

    private function lockOpen(DepositAdjustment $adjustment): DepositAdjustment
    {
        $adjustment = DepositAdjustment::lockForUpdate()->findOrFail($adjustment->id);
        abort_unless(in_array($adjustment->status, DepositAdjustment::OPEN_STATUSES, true), 409, 'This adjustment is already '.$adjustment->status.'.');

        // Starting a new shift marks these superseded; this is the backstop for a request caught mid-flight
        abort_unless(self::adjustableShiftId() === $adjustment->deposit_control_id, 409, 'A newer shift has started, so this request lapsed. The difference now shows in the newer shift\'s overall balance.');

        return $adjustment;
    }

    private function fresh(DepositAdjustment $adjustment): DepositAdjustment
    {
        return DepositAdjustment::with(self::relations())->findOrFail($adjustment->id);
    }

    public static function relations(): array
    {
        return ['requester:id,name', 'rejecter:id,name', 'poster:id,name', 'tender:id,name', 'approvals.user:id,name', 'transaction:id,type,amount,description'];
    }
}
