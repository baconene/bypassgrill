<?php

namespace App\Jobs;

use App\Events\OrderStatusChanged;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Services\InventoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessOrderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private Order $order) {}

    public function handle(InventoryService $inventoryService): void
    {
        // Orders already deduct stock synchronously in OrderService. This legacy job
        // must never repeat deductions (especially pre-ledger orders without item links).
        if (in_array($this->order->fresh()?->status, ['cancelled', 'completed', 'ready', 'preparing'])) {
            return;
        }
        $hasLegacyDeductions = InventoryTransaction::where('reference', 'order_'.$this->order->id)
            ->where('type', 'stock_out')->whereNull('order_id')->exists();
        if ($hasLegacyDeductions) {
            return;
        }
        DB::transaction(function () use ($inventoryService) {
            $order = Order::whereKey($this->order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'pending') {
                return;
            }
            foreach ($order->items as $item) {
                $inventoryService->deductForOrder($item);
            }
            $order->update(['status' => 'preparing']);
            event(new OrderStatusChanged($order));
        });
    }
}
