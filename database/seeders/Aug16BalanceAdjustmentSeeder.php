<?php

namespace Database\Seeders;

use App\Models\FinancialTransaction;
use App\Models\PaymentTender;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * One-time seeder to tally the Aug 16, 2026 physical count.
 *
 * 1. Re-tags all untagged transactions (payment_tender_id = NULL) → Cash
 * 2. Posts a Cash variance adjustment to match actual ₱30,995.00
 * 3. Posts a GCash variance adjustment to match actual ₱0.00
 *
 * Run once on production:
 *   php artisan db:seed --class=Aug16BalanceAdjustmentSeeder
 */
class Aug16BalanceAdjustmentSeeder extends Seeder
{
    private const ACTUAL_CASH  = 30995.00;
    private const ACTUAL_GCASH = 0.00;

    public function run(): void
    {
        $cash  = PaymentTender::where('name', 'Cash')->firstOrFail();
        $gcash = PaymentTender::where('name', 'GCash')->firstOrFail();
        $admin = User::role('admin')->first();

        DB::transaction(function () use ($cash, $gcash, $admin) {

            // ── Step 1: Re-tag all Untagged → Cash ────────────────────────
            $retagged = FinancialTransaction::whereNull('payment_tender_id')
                ->update(['payment_tender_id' => $cash->id]);

            $this->command->info("Step 1 — Re-tagged {$retagged} untagged transactions → Cash");

            // ── Step 2: Cash variance adjustment ──────────────────────────
            $cashBalance = (float) FinancialTransaction::where('payment_tender_id', $cash->id)
                ->where('type', '!=', 'order')
                ->selectRaw("SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE -amount END) as bal")
                ->value('bal');

            $cashVariance = round($cashBalance - self::ACTUAL_CASH, 2);
            $this->command->info("Step 2 — Cash system balance: ₱{$cashBalance}, actual: ₱" . self::ACTUAL_CASH . ", variance: ₱{$cashVariance}");

            if ($cashVariance > 0) {
                FinancialTransaction::create([
                    'type'              => 'expense',
                    'amount'            => $cashVariance,
                    'description'       => 'Cash variance adjustment — physical count Aug 16, 2026',
                    'notes'             => "System cash ₱{$cashBalance} vs actual ₱" . self::ACTUAL_CASH . ". Deducting ₱{$cashVariance}.",
                    'payment_tender_id' => $cash->id,
                    'user_id'           => $admin?->id,
                    'transacted_at'     => now(),
                ]);
                $this->command->info("  → Posted expense of ₱{$cashVariance} against Cash");
            } elseif ($cashVariance < 0) {
                $add = abs($cashVariance);
                FinancialTransaction::create([
                    'type'              => 'income_adjustment',
                    'amount'            => $add,
                    'description'       => 'Cash variance adjustment — physical count Aug 16, 2026',
                    'notes'             => "System cash ₱{$cashBalance} vs actual ₱" . self::ACTUAL_CASH . ". Adding ₱{$add}.",
                    'payment_tender_id' => $cash->id,
                    'user_id'           => $admin?->id,
                    'transacted_at'     => now(),
                ]);
                $this->command->info("  → Posted income_adjustment of ₱{$add} against Cash");
            } else {
                $this->command->info('  → Cash already matches actual. No adjustment needed.');
            }

            // ── Step 3: GCash variance adjustment ─────────────────────────
            $gcashBalance = (float) FinancialTransaction::where('payment_tender_id', $gcash->id)
                ->where('type', '!=', 'order')
                ->selectRaw("SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE -amount END) as bal")
                ->value('bal');

            $gcashVariance = round($gcashBalance - self::ACTUAL_GCASH, 2);
            $this->command->info("Step 3 — GCash system balance: ₱{$gcashBalance}, actual: ₱" . self::ACTUAL_GCASH . ", variance: ₱{$gcashVariance}");

            if ($gcashVariance > 0) {
                FinancialTransaction::create([
                    'type'              => 'expense',
                    'amount'            => $gcashVariance,
                    'description'       => 'GCash variance adjustment — physical count Aug 16, 2026',
                    'notes'             => "System GCash ₱{$gcashBalance} vs actual ₱" . self::ACTUAL_GCASH . ". Deducting ₱{$gcashVariance}.",
                    'payment_tender_id' => $gcash->id,
                    'user_id'           => $admin?->id,
                    'transacted_at'     => now(),
                ]);
                $this->command->info("  → Posted expense of ₱{$gcashVariance} against GCash");
            } elseif ($gcashVariance < 0) {
                $add = abs($gcashVariance);
                FinancialTransaction::create([
                    'type'              => 'income_adjustment',
                    'amount'            => $add,
                    'description'       => 'GCash variance adjustment — physical count Aug 16, 2026',
                    'notes'             => "System GCash ₱{$gcashBalance} vs actual ₱" . self::ACTUAL_GCASH . ". Adding ₱{$add}.",
                    'payment_tender_id' => $gcash->id,
                    'user_id'           => $admin?->id,
                    'transacted_at'     => now(),
                ]);
                $this->command->info("  → Posted income_adjustment of ₱{$add} against GCash");
            } else {
                $this->command->info('  → GCash already matches actual. No adjustment needed.');
            }
        });

        $this->command->info('Done. System balance should now reflect ₱' . self::ACTUAL_CASH . ' (Cash) + ₱' . self::ACTUAL_GCASH . ' (GCash) = ₱' . (self::ACTUAL_CASH + self::ACTUAL_GCASH));
    }
}
