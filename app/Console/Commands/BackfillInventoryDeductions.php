<?php

namespace App\Console\Commands;

use App\Enums\InventoryTransactionType;
use App\Enums\OrderStatus;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Services\InventoryService;
use Illuminate\Console\Command;

class BackfillInventoryDeductions extends Command
{
    protected $signature = 'inventory:backfill-deductions {--date= : Date to backfill (default: today, format Y-m-d)}';

    protected $description = 'Deduct ingredients for orders that were placed before auto-deduction was enabled';

    public function __construct(private InventoryService $inventoryService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->option('date') ?? now()->toDateString();

        $this->info("Backfilling inventory deductions for orders on {$date}...");

        $orders = Order::with(['items.product'])
            ->whereNotIn('status', [OrderStatus::CANCELLED->value])
            ->whereDate('created_at', $date)
            ->get();

        $this->info("Found {$orders->count()} non-cancelled orders.");

        $deducted = 0;
        $skipped  = 0;

        foreach ($orders as $order) {
            $alreadyDeducted = InventoryTransaction::where('reference', 'order_' . $order->id)
                ->where('type', InventoryTransactionType::STOCK_OUT->value)
                ->exists();

            if ($alreadyDeducted) {
                $this->line("  Order #{$order->id} — already deducted, skipping.");
                $skipped++;
                continue;
            }

            foreach ($order->items as $item) {
                $this->inventoryService->deductForOrder($item);
            }

            $this->line("  Order #{$order->id} — deducted ({$order->items->count()} item(s)).");
            $deducted++;
        }

        $this->newLine();
        $this->info("Done. Deducted: {$deducted} orders, skipped (already done): {$skipped} orders.");

        return self::SUCCESS;
    }
}
