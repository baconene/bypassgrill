<?php

namespace App\Console\Commands;

use App\Models\FinancialTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeOrphanedOrderFinancialTransactions extends Command
{
    protected $signature = 'ft:purge-orphans
                            {--dry-run : Preview changes without writing}';

    protected $description = 'Delete order and payment financial transactions left behind by deleted orders';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Order and payment FTs are always created with an order_id; a null one means
        // the order was deleted before deletions removed their FTs too.
        $rows = FinancialTransaction::whereIn('type', ['order', 'payment'])
            ->whereNull('order_id')
            ->orderBy('transacted_at')
            ->get(['id', 'type', 'amount', 'description', 'transacted_at']);

        if ($rows->isEmpty()) {
            $this->info('No orphaned order or payment FTs found. Nothing to do.');
            return 0;
        }

        $this->table(
            ['FT ID', 'Type', 'Amount', 'Description', 'Transacted At'],
            $rows->map(fn ($r) => [
                $r->id,
                $r->type,
                number_format($r->amount, 2),
                $r->description,
                $r->transacted_at?->toDateTimeString(),
            ])
        );

        $paymentTotal = $rows->where('type', 'payment')->sum('amount');

        $this->line('');
        $this->line("{$rows->count()} orphaned record(s). Payments total: " . number_format($paymentTotal, 2));

        if ($dryRun) {
            $this->warn('[dry-run] No changes written.');
            return 0;
        }

        if (! $this->confirm('Delete these records?', false)) {
            $this->line('Aborted.');
            return 0;
        }

        DB::transaction(fn () => FinancialTransaction::whereIn('id', $rows->pluck('id'))->delete());

        $this->info("Done. {$rows->count()} FT record(s) deleted.");
        return 0;
    }
}
