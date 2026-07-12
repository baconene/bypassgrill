<?php

namespace App\Console\Commands;

use App\Models\FinancialTransaction;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncOrderFinancialTransactions extends Command
{
    protected $signature = 'ft:sync-orders
                            {--dry-run : Preview changes without writing}
                            {--order= : Sync a specific order ID only}';

    protected $description = 'Align order-type financial transactions with their order totals';

    public function handle(): int
    {
        $dryRun  = $this->option('dry-run');
        $orderId = $this->option('order');

        $query = FinancialTransaction::where('type', 'order')
            ->whereNotNull('order_id')
            ->join('orders', 'orders.id', '=', 'financial_transactions.order_id')
            ->whereColumn('financial_transactions.amount', '!=', 'orders.total_amount')
            ->select([
                'financial_transactions.id as ft_id',
                'financial_transactions.order_id',
                'financial_transactions.amount as ft_amount',
                'orders.total_amount as order_total',
            ]);

        if ($orderId) {
            $query->where('financial_transactions.order_id', (int) $orderId);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('All order FTs are already in sync. Nothing to do.');
            return 0;
        }

        $this->table(
            ['FT ID', 'Order ID', 'FT Amount', 'Order Total', 'Diff'],
            $rows->map(fn ($r) => [
                $r->ft_id,
                $r->order_id,
                number_format($r->ft_amount, 2),
                number_format($r->order_total, 2),
                number_format($r->order_total - $r->ft_amount, 2),
            ])
        );

        $this->line('');
        $this->line("{$rows->count()} record(s) out of sync.");

        if ($dryRun) {
            $this->warn('[dry-run] No changes written.');
            return 0;
        }

        if (! $this->confirm('Apply these updates?', true)) {
            $this->line('Aborted.');
            return 0;
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                FinancialTransaction::where('id', $row->ft_id)
                    ->update(['amount' => $row->order_total]);
            }
        });

        $this->info("Done. {$rows->count()} FT record(s) updated.");
        return 0;
    }
}
