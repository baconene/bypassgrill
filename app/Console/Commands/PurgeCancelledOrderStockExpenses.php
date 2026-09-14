<?php

namespace App\Console\Commands;

use App\Enums\InventoryTransactionType;
use App\Models\FinancialTransaction;
use App\Models\InventoryTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeCancelledOrderStockExpenses extends Command
{
    protected $signature = 'ft:purge-cancel-expenses
                            {--dry-run : Preview changes without writing}';

    protected $description = 'Delete inventory expenses wrongly recorded when cancelled orders returned their stock';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Cancellations used to return stock as a normal stock-in, which also wrote an
        // "Inventory Stock In: <ingredient>" expense. The expense has no link back, so match
        // it to the cancellation's stock-in by user, ingredient name and creation time.
        $restocks = InventoryTransaction::with('ingredient')
            ->where('type', InventoryTransactionType::STOCK_IN->value)
            ->where('reference', 'like', 'order_%_cancel')
            ->orderBy('created_at')
            ->get();

        $matched = collect();

        foreach ($restocks as $restock) {
            if (! $restock->ingredient) {
                continue;
            }

            $expense = FinancialTransaction::where('type', 'expense')
                ->whereNull('order_id')
                ->where('user_id', $restock->user_id)
                ->where('description', 'Inventory Stock In: ' . $restock->ingredient->name)
                ->whereBetween('created_at', [$restock->created_at, $restock->created_at->copy()->addSeconds(2)])
                ->whereNotIn('id', $matched->pluck('ft.id'))
                ->orderBy('created_at')
                ->first();

            if ($expense) {
                $matched->push(['ft' => $expense, 'restock' => $restock]);
            }
        }

        if ($matched->isEmpty()) {
            $this->info('No cancellation stock expenses found. Nothing to do.');
            return 0;
        }

        $this->table(
            ['FT ID', 'Reference', 'Ingredient', 'Qty Returned', 'Expense', 'Created At'],
            $matched->map(fn ($m) => [
                $m['ft']->id,
                $m['restock']->reference,
                $m['restock']->ingredient->name,
                $m['restock']->quantity,
                number_format($m['ft']->amount, 2),
                $m['ft']->created_at->toDateTimeString(),
            ])
        );

        $this->line('');
        $this->line("{$matched->count()} matched expense(s). Total: " . number_format($matched->sum(fn ($m) => $m['ft']->amount), 2));

        if ($dryRun) {
            $this->warn('[dry-run] No changes written.');
            return 0;
        }

        if (! $this->confirm('Delete these expenses?', false)) {
            $this->line('Aborted.');
            return 0;
        }

        DB::transaction(fn () => FinancialTransaction::whereIn('id', $matched->pluck('ft.id'))->delete());

        $this->info("Done. {$matched->count()} FT record(s) deleted.");
        return 0;
    }
}
