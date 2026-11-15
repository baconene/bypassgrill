<?php

namespace Database\Seeders;

use App\Models\DepositControl;
use App\Models\FinancialTransaction;
use App\Models\PaymentTender;
use App\Models\User;
use App\Services\DepositAdjustmentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time seeder: make the tender balances match the shift #50 closing count, then record the ₱15,000
 * Cash → GCash transfer made after it.
 *
 * Source of truth is the shift #50 count: lockbox (Cash) ₱16,622.00 and GCash wallet ₱8,300.31. The overall
 * shortage was already balanced by Nov14ShiftShortageAdjustmentSeeder, so the total is right and only the split
 * between tenders is wrong. Each tender is corrected to its count plus whatever moved through it after closing
 * (last night's adjustment excluded, since it is part of reconciling to the count). The corrections net to zero.
 *
 * Run once on production:
 *   php artisan db:seed --class=Nov15TenderRebalanceSeeder --force
 */
class Nov15TenderRebalanceSeeder extends Seeder
{
    protected int $shiftId = 50;

    protected float $countedCash = 16622.00;

    protected float $countedGcash = 8300.31;

    protected float $transfer = 15000.00;

    public function run(): void
    {
        DB::transaction(function () {
            $shift = DepositControl::lockForUpdate()->findOrFail($this->shiftId);
            $label = "Shift #{$shift->id}";
            $rec = $shift->reconciliation ?? [];

            if (round((float) ($rec['lockbox_total'] ?? -1), 2) !== $this->countedCash
                || round((float) ($rec['total_gcash'] ?? -1), 2) !== $this->countedGcash) {
                throw new RuntimeException("{$label} counts do not match ₱".number_format($this->countedCash, 2).' cash / ₱'.number_format($this->countedGcash, 2).' GCash. Nothing was changed.');
            }

            $adjustment = $shift->adjustments()->where('status', 'posted')->first()
                ?? throw new RuntimeException("{$label} has no posted overall adjustment. Run Nov14ShiftShortageAdjustmentSeeder first. Nothing was changed.");

            if (FinancialTransaction::where('description', 'like', "Tender rebalance – {$label}%")->exists()) {
                throw new RuntimeException("{$label} tenders were already rebalanced. Nothing was changed.");
            }

            $cash = PaymentTender::where('name', 'Cash')->firstOrFail();
            $gcash = PaymentTender::where('name', 'GCash')->firstOrFail();
            $owner = User::all()->first(fn (User $u) => DepositAdjustmentService::isSuperUser($u))
                ?? User::role('admin')->orderBy('id')->firstOrFail();

            $signed = "SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE -amount END)";
            $byTender = fn ($query) => $query->where('type', '!=', 'order')
                ->selectRaw("payment_tender_id, {$signed} as bal")
                ->groupBy('payment_tender_id')
                ->pluck('bal', 'payment_tender_id')
                ->map(fn ($v) => (int) round((float) $v * 100));

            // Cents throughout, keyed by tender id ('' = untagged).
            $current = $byTender(FinancialTransaction::query());
            $since = $byTender(FinancialTransaction::where('transacted_at', '>', $shift->closed_at)
                ->where('id', '!=', $adjustment->financial_transaction_id));

            $counted = [$cash->id => (int) round($this->countedCash * 100), $gcash->id => (int) round($this->countedGcash * 100)];
            $ids = collect(array_keys($counted))->merge($current->keys())->merge($since->keys())->unique();

            $corrections = [];
            foreach ($ids as $id) {
                $target = ($counted[$id] ?? 0) + ($since[$id] ?? 0);
                $diff = $target - ($current[$id] ?? 0);
                if ($diff !== 0) {
                    $corrections[$id] = $diff;
                }
            }

            if (array_sum($corrections) !== 0) {
                throw new RuntimeException(sprintf(
                    'The overall balance is off by ₱%s, so this is not just a split between tenders. Nothing was changed.',
                    number_format(array_sum($corrections) / 100, 2),
                ));
            }

            $names = PaymentTender::pluck('name', 'id');
            $post = function (?int $tenderId, int $cents, string $description, string $notes) use ($owner) {
                FinancialTransaction::create([
                    'type' => $cents > 0 ? 'income_adjustment' : 'expense',
                    'amount' => abs($cents) / 100,
                    'payment_tender_id' => $tenderId,
                    'user_id' => $owner->id,
                    'transacted_at' => now(),
                    'description' => $description,
                    'notes' => $notes,
                ]);
            };

            foreach ($corrections as $id => $cents) {
                $name = $id === '' || $id === null ? 'Untagged' : ($names[$id] ?? "Tender #{$id}");
                $post($id === '' ? null : (int) $id, $cents, "Tender rebalance – {$label} count ({$name})", sprintf(
                    '%s balance corrected by %s₱%s to match the %s closing count (lockbox ₱%s, GCash ₱%s). Posted by %s via one-time seeder.',
                    $name, $cents > 0 ? '+' : '−', number_format(abs($cents) / 100, 2), $label,
                    number_format($this->countedCash, 2), number_format($this->countedGcash, 2), $owner->name,
                ));
                $this->command?->info(sprintf('  %s: %s₱%s', $name, $cents > 0 ? '+' : '−', number_format(abs($cents) / 100, 2)));
            }

            $amount = (int) round($this->transfer * 100);
            $note = sprintf('Transfer of ₱%s from Cash to GCash after the %s count. Posted by %s via one-time seeder.', number_format($this->transfer, 2), $label, $owner->name);
            $post($cash->id, -$amount, 'Transfer to GCash – ₱'.number_format($this->transfer, 2), $note);
            $post($gcash->id, $amount, 'Transfer from Cash – ₱'.number_format($this->transfer, 2), $note);

            $final = $byTender(FinancialTransaction::query());
            $this->command?->info(sprintf(
                'Done. Cash ₱%s, GCash ₱%s.',
                number_format(($final[$cash->id] ?? 0) / 100, 2), number_format(($final[$gcash->id] ?? 0) / 100, 2),
            ));
        });
    }
}
