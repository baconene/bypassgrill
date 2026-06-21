<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the stored running_balance column. It was computed incrementally at
 * insert time, which scrambles for backdated / out-of-order entries. The
 * balance is now derived on read (FinancialTransactionController computes
 * `financial_balance` in chronological order), so the column is dead weight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->dropColumn('running_balance');
        });
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->decimal('running_balance', 14, 2)->default(0)->after('transacted_at');
        });

        // Best-effort backfill in chronological order (matches the derived balance).
        $balance = 0.0;
        DB::table('financial_transactions')
            ->orderBy('transacted_at')
            ->orderBy('id')
            ->get(['id', 'type', 'amount'])
            ->each(function ($tx) use (&$balance) {
                $balance = round($balance + match ($tx->type) {
                    'payment', 'income_adjustment' => (float) $tx->amount,
                    'expense', 'order', 'payroll'  => -(float) $tx->amount,
                    default                        => 0.0,
                }, 2);
                DB::table('financial_transactions')->where('id', $tx->id)
                    ->update(['running_balance' => $balance]);
            });
    }
};
