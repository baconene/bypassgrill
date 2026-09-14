<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Enums\InventoryTransactionType;
use Illuminate\Support\Facades\Auth;

class InventoryService
{
    public function deductForOrder(OrderItem $orderItem): bool
    {
        $product = $orderItem->product;
        $recipes = $product->recipes()->with('ingredient')->get();

        $order = Order::find($orderItem->order_id);
        $orderTypeLabel = match($order?->order_type) {
            'dine_in'  => 'Dine In',
            'takeout'  => 'Takeout',
            'delivery' => 'Delivery',
            default    => $order?->order_type ?? 'Order',
        };
        $tableInfo = $order?->table_number ? " · Table {$order->table_number}" : '';
        $notes = "Order #{$orderItem->order_id} · {$orderTypeLabel}{$tableInfo} · {$product->name} ×{$orderItem->quantity}";

        foreach ($recipes as $recipe) {
            $ingredient = $recipe->ingredient;

            if (! $ingredient || ! $ingredient->track_inventory) {
                continue;
            }

            $required  = (float) $recipe->quantity * (int) $orderItem->quantity;
            $available = (float) $ingredient->current_quantity;

            if ($available < $required) {
                return false;
            }

            $this->recordTransaction(
                $ingredient,
                $required,
                InventoryTransactionType::STOCK_OUT,
                'order_' . $orderItem->order_id,
                $notes,
            );
        }

        return true;
    }

    public function recordTransaction(
        Ingredient $ingredient,
        float $quantity,
        InventoryTransactionType $type,
        ?string $reference = null,
        ?string $notes = null,
        bool $recordExpense = true,
    ): InventoryTransaction {
        $oldQuantity = (float) $ingredient->current_quantity;

        match ($type) {
            InventoryTransactionType::STOCK_IN   => $ingredient->increment('current_quantity', $quantity),
            InventoryTransactionType::STOCK_OUT  => $ingredient->decrement('current_quantity', $quantity),
            InventoryTransactionType::ADJUSTMENT => $ingredient->update(['current_quantity' => $quantity]),
            InventoryTransactionType::WASTE      => $ingredient->decrement('current_quantity', $quantity),
        };

        $ingredient->refresh();
        $newQuantity = (float) $ingredient->current_quantity;

        $tx = InventoryTransaction::create([
            'ingredient_id' => $ingredient->id,
            'user_id'       => Auth::id(),
            'type'          => $type,
            'quantity'      => $quantity,
            'old_quantity'  => $oldQuantity,
            'new_quantity'  => $newQuantity,
            'reference'     => $reference,
            'notes'         => $notes,
        ]);

        // Record a financial expense for stock purchases and positive adjustments
        $costPerUnit = (float) $ingredient->cost_per_unit;
        if ($recordExpense && $costPerUnit > 0) {
            $costDelta = match ($type) {
                InventoryTransactionType::STOCK_IN   => $quantity * $costPerUnit,
                InventoryTransactionType::ADJUSTMENT => max(0.0, ($newQuantity - $oldQuantity)) * $costPerUnit,
                default                              => 0.0,
            };

            if ($costDelta > 0) {
                \App\Models\FinancialTransaction::create([
                    'type'          => 'expense',
                    'amount'        => round($costDelta, 2),
                    'description'   => "Inventory {$type->label()}: {$ingredient->name}",
                    'user_id'       => Auth::id(),
                    'transacted_at' => now(),
                ]);
            }
        }

        return $tx;
    }

    /**
     * Returns null if stock is sufficient, or a human-readable error string naming the short ingredient.
     */
    public function checkAvailability(OrderItem $orderItem): ?string
    {
        $product = $orderItem->product;
        $recipes = $product->recipes()->with('ingredient')->get();

        foreach ($recipes as $recipe) {
            $ingredient = $recipe->ingredient;

            if (! $ingredient || ! $ingredient->track_inventory) {
                continue;
            }

            $required  = (float) $recipe->quantity * (int) $orderItem->quantity;
            $available = (float) $ingredient->current_quantity;

            if ($available < $required) {
                return sprintf(
                    'Not enough %s for %s (need %.3f %s, have %.3f %s)',
                    $ingredient->name,
                    $product->name,
                    $required,
                    $ingredient->unit,
                    $available,
                    $ingredient->unit,
                );
            }
        }

        return null;
    }

    /**
     * Return the stock an order actually consumed when it is cancelled or deleted.
     * Uses the order's own inventory history, so stock is never returned twice and
     * never-deducted orders restore nothing. No expense is recorded, since the stock
     * was already paid for when it was purchased.
     */
    public function restoreOrderStock(Order $order, string $action): void
    {
        $label = match ($action) {
            'cancel' => 'Cancelled',
            'delete' => 'Deleted',
        };

        $sumByIngredient = fn (array $references, InventoryTransactionType $type) => InventoryTransaction::whereIn('reference', $references)
            ->where('type', $type->value)
            ->selectRaw('ingredient_id, SUM(quantity) as total')
            ->groupBy('ingredient_id')
            ->pluck('total', 'ingredient_id');

        $prefix   = 'order_' . $order->id;
        $deducted = $sumByIngredient([$prefix], InventoryTransactionType::STOCK_OUT);
        $restored = $sumByIngredient([$prefix . '_cancel', $prefix . '_delete'], InventoryTransactionType::STOCK_IN);

        foreach ($deducted as $ingredientId => $total) {
            $quantity = round((float) $total - (float) ($restored[$ingredientId] ?? 0), 3);
            $ingredient = Ingredient::find($ingredientId);

            if ($quantity <= 0 || ! $ingredient) {
                continue;
            }

            $this->recordTransaction(
                $ingredient,
                $quantity,
                InventoryTransactionType::STOCK_IN,
                $prefix . '_' . $action,
                "{$label} Order #{$order->id}",
                recordExpense: false,
            );
        }
    }

    public function getLowStockItems()
    {
        return Ingredient::whereColumn('current_quantity', '<=', 'min_quantity')
            ->where('is_active', true)
            ->get();
    }
}
