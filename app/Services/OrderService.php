<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PrintServiceSetting;
use App\Models\QueueNumber;
use App\Enums\OrderStatus;
use App\Services\PrintReceiptService;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private InventoryService $inventoryService,
        private PrintReceiptService $printReceiptService,
    ) {}

    public function createOrder(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $order = Order::create([
                'user_id' => auth()->id(),
                'order_type' => $data['order_type'],
                'table_number' => $data['table_number'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_contact' => $data['customer_contact'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'notes' => $data['notes'] ?? null,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'status' => OrderStatus::PENDING->value,
                'payment_status' => 'pending',
            ]);

            // Generate queue number for dine_in and takeout
            if (in_array($order->order_type, ['dine_in', 'takeout'])) {
                $queueNumber = QueueNumber::create(['number' => $this->generateQueueNumber()]);
                $order->update(['queue_number_id' => $queueNumber->id]);
            }

            // Add items
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    $this->addOrderItem($order, $item);
                }
            }

            $order->calculateTotals();

            \App\Models\FinancialTransaction::create([
                'type'          => 'order',
                'amount'        => $order->fresh()->total_amount,
                'description'   => "Order #{$order->id} ({$order->order_type})",
                'order_id'      => $order->id,
                'user_id'       => auth()->id(),
                'transacted_at' => now(),
            ]);

            return $order;
        });
    }

    public function addOrderItem(Order $order, array $itemData): OrderItem
    {
        return DB::transaction(function () use ($order, $itemData) {
            $product = \App\Models\Product::findOrFail($itemData['product_id']);
            $quantity = $itemData['quantity'] ?? 1;

            $orderItem = new OrderItem([
                'product_id' => $product->id,
                'quantity'   => $quantity,
                'unit_price' => $product->price,
                'unit_cost'  => (float) ($product->cost ?? 0),
            ]);

            $stockError = $this->inventoryService->checkAvailability($orderItem);
            if ($stockError !== null) {
                abort(422, $stockError);
            }

            $orderItem->order_id = $order->id;
            $orderItem->calculateSubtotal();
            $orderItem->save();

            $this->inventoryService->deductForOrder($orderItem);

            if (isset($itemData['modifiers']) && is_array($itemData['modifiers'])) {
                foreach ($itemData['modifiers'] as $modifierId) {
                    \App\Models\OrderItemModifier::create([
                        'order_item_id' => $orderItem->id,
                        'modifier_id' => $modifierId,
                        'price' => \App\Models\Modifier::find($modifierId)->price ?? 0,
                    ]);
                }
            }

            return $orderItem->fresh();
        });
    }

    public function updateOrderStatus(Order $order, OrderStatus $status): Order
    {
        $previousStatus = $order->status;

        $order->update(['status' => $status->value]);

        if ($status === OrderStatus::COMPLETED) {
            $order->update(['completed_at' => now()]);

            if ($previousStatus !== OrderStatus::COMPLETED->value) {
                // Auto-print via HTTP bridge if configured
                $printSettings = PrintServiceSetting::getSetting();
                if ($printSettings->print_enabled && $printSettings->print_auto_print) {
                    $this->printReceiptService->print($order);
                }
            }
        }

        return $order;
    }

    public function generateQueueNumber(): int
    {
        $latest = QueueNumber::orderByDesc('number')->first();

        return ($latest?->number ?? 0) + 1;
    }

    public function cancelOrder(Order $order, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            $order->load('items');
            foreach ($order->items as $item) {
                $this->inventoryService->restoreForOrder($item);
            }

            $order->items()->update(['status' => 'cancelled']);
            $order->update([
                'status' => OrderStatus::CANCELLED->value,
                'payment_status' => 'voided',
                'notes' => ($order->notes ? $order->notes . ' | ' : '') . 'Cancelled: ' . $reason,
            ]);

            return $order;
        });
    }
}
