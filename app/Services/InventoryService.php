<?php

namespace App\Services;

use App\Enums\InventoryCostKind;
use App\Enums\InventoryCostSource;
use App\Enums\InventoryTransactionType;
use App\Models\Ingredient;
use App\Models\InventoryCostEntry;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function deductForOrder(OrderItem $orderItem): bool
    {
        return app(InventoryCostService::class)->consume($orderItem);
    }

    public function recordTransaction(
        Ingredient $ingredient,
        float $quantity,
        InventoryTransactionType $type,
        ?string $reference = null,
        ?string $notes = null,
        bool $recordCost = true,
        ?int $orderId = null,
        ?int $orderItemId = null,
        ?float $unitCost = null,
    ): InventoryTransaction {
        return DB::transaction(function () use ($ingredient, $quantity, $type, $reference, $notes, $recordCost, $orderId, $orderItemId, $unitCost) {
            abort_if($quantity < 0 || ($unitCost !== null && $unitCost < 0), 422, 'Quantity and cost cannot be negative.');
            $ingredient = Ingredient::withTrashed()->whereKey($ingredient->id)->lockForUpdate()->firstOrFail();
            $oldQuantity = (float) $ingredient->current_quantity;

            $movementCost = $type === InventoryTransactionType::STOCK_IN
                ? ($unitCost ?? (float) $ingredient->cost_per_unit)
                : (float) $ingredient->cost_per_unit;
            // Deliberately not gated on $recordCost: a production run brings food in at a
            // known cost per unit and must move the average, but writes its own pair of
            // ledger entries rather than a purchase. No caller passes a unit cost without
            // wanting the average moved.
            if ($type === InventoryTransactionType::STOCK_IN && $quantity > 0 && $unitCost !== null) {
                $weight = max(0, $oldQuantity);
                $ingredient->update(['cost_per_unit' => round(($weight * (float) $ingredient->cost_per_unit + $quantity * $unitCost) / ($weight + $quantity), 4)]);
            }
            match ($type) {
                InventoryTransactionType::STOCK_IN => $ingredient->increment('current_quantity', $quantity),
                InventoryTransactionType::STOCK_OUT => $ingredient->decrement('current_quantity', $quantity),
                InventoryTransactionType::ADJUSTMENT => $ingredient->update(['current_quantity' => $quantity]),
                InventoryTransactionType::WASTE => $ingredient->decrement('current_quantity', $quantity),
            };

            $ingredient->refresh();
            $newQuantity = (float) $ingredient->current_quantity;

            $tx = InventoryTransaction::create([
                'ingredient_id' => $ingredient->id,
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'user_id' => Auth::id(),
                'type' => $type,
                'quantity' => $quantity,
                'old_quantity' => $oldQuantity,
                'new_quantity' => $newQuantity,
                'reference' => $reference,
                'notes' => $notes,
            ]);

            // Stock records quantity and cost only. Cash payments are recorded separately.
            if ($recordCost) {
                $delta = $newQuantity - $oldQuantity;
                $kind = match ($type) {
                    InventoryTransactionType::STOCK_IN => 'purchase',
                    InventoryTransactionType::WASTE => 'waste',
                    InventoryTransactionType::STOCK_OUT => 'count_loss',
                    InventoryTransactionType::ADJUSTMENT => $delta >= 0 ? 'count_gain' : 'count_loss',
                };
                $costQuantity = $type === InventoryTransactionType::STOCK_IN ? $delta : -$delta;
                InventoryCostEntry::create([
                    'kind' => $kind, 'source' => 'ingredient', 'inventory_transaction_id' => $tx->id,
                    'ingredient_id' => $ingredient->id, 'ingredient_name' => $ingredient->name,
                    'quantity' => $costQuantity, 'unit_cost' => $movementCost, 'total_cost' => round($costQuantity * $movementCost, 2),
                    'user_id' => Auth::id(), 'recognized_at' => now(), 'reference' => $reference,
                ]);
            }

            return $tx;
        }, 3);
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

            $required = (float) $recipe->quantity * (int) $orderItem->quantity;
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
        DB::transaction(function () use ($order, $action) {
            Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            app(InventoryCostService::class)->restore($order, $action);
            $label = match ($action) {
                'cancel' => 'Cancelled',
                'delete' => 'Deleted',
                'edit' => 'Edited',
            };

            $sumByIngredient = fn (array $references, InventoryTransactionType $type) => InventoryTransaction::whereIn('reference', $references)
                ->where('type', $type->value)->whereNull('order_id')
                ->selectRaw('ingredient_id, SUM(quantity) as total')
                ->groupBy('ingredient_id')
                ->pluck('total', 'ingredient_id');

            $prefix = 'order_'.$order->id;
            $deducted = $sumByIngredient([$prefix], InventoryTransactionType::STOCK_OUT);
            $restored = $sumByIngredient([$prefix.'_cancel', $prefix.'_delete', $prefix.'_edit'], InventoryTransactionType::STOCK_IN);

            foreach ($deducted as $ingredientId => $total) {
                $quantity = round((float) $total - (float) ($restored[$ingredientId] ?? 0), 3);
                $ingredient = Ingredient::withTrashed()->find($ingredientId);

                if ($quantity <= 0 || ! $ingredient) {
                    continue;
                }

                $this->recordTransaction(
                    $ingredient,
                    $quantity,
                    InventoryTransactionType::STOCK_IN,
                    $prefix.'_'.$action,
                    "{$label} Order #{$order->id}",
                    recordCost: false,
                );
            }
        }, 3);
    }

    /**
     * Reverse a Stock In that should never have been recorded: the stock leaves again,
     * the receipt's share of the weighted average is unwound, and a signed
     * purchase_reversal cancels the original purchase entry. The unique reversal_of_id
     * makes a second undo impossible. Stock already consumed cannot be undone.
     */
    public function undoStockIn(InventoryTransaction $transaction): InventoryTransaction
    {
        return DB::transaction(function () use ($transaction) {
            $transaction = InventoryTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            abort_unless($transaction->type === InventoryTransactionType::STOCK_IN->value, 422, 'Only a Stock In can be undone.');

            $purchase = InventoryCostEntry::where('inventory_transaction_id', $transaction->id)
                ->where('kind', InventoryCostKind::PURCHASE->value)
                ->lockForUpdate()
                ->first();
            abort_unless($purchase, 422, 'This movement has no purchase entry to undo.');
            abort_if(
                InventoryCostEntry::where('reversal_of_id', $purchase->id)->exists(),
                422,
                'This Stock In has already been undone.'
            );

            $ingredient = Ingredient::withTrashed()->whereKey($transaction->ingredient_id)->lockForUpdate()->firstOrFail();
            $quantity = (float) $transaction->quantity;
            $available = (float) $ingredient->current_quantity;
            abort_if(
                $available < $quantity,
                422,
                'Only '.rtrim(rtrim(number_format($available, 3, '.', ''), '0'), '.').' '.$ingredient->unit.' of '
                    .$ingredient->name.' is left, so this Stock In has already been used. Record waste or a count instead.'
            );

            // Unwind this receipt's contribution to the weighted average, using the
            // quantity and cost still on hand before the stock leaves again.
            $unitCost = (float) $purchase->unit_cost;
            $remaining = round($available - $quantity, 3);
            $restoredCost = $remaining > 0
                ? max(0, round((($available * (float) $ingredient->cost_per_unit) - ($quantity * $unitCost)) / $remaining, 4))
                : (float) $ingredient->cost_per_unit;

            $reference = 'undo_stock_in_'.$transaction->id;
            $reversal = $this->recordTransaction(
                $ingredient,
                $quantity,
                InventoryTransactionType::STOCK_OUT,
                $reference,
                'Undo Stock In #'.$transaction->id,
                recordCost: false,
            );
            $ingredient->update(['cost_per_unit' => $restoredCost]);

            InventoryCostEntry::create([
                'kind' => InventoryCostKind::PURCHASE_REVERSAL->value,
                'source' => InventoryCostSource::INGREDIENT->value,
                'inventory_transaction_id' => $reversal->id,
                'ingredient_id' => $ingredient->id,
                'ingredient_name' => $ingredient->name,
                'reference' => $reference,
                'quantity' => -$quantity,
                'unit_cost' => $unitCost,
                'total_cost' => -(float) $purchase->total_cost,
                'reversal_of_id' => $purchase->id,
                'user_id' => Auth::id(),
                'recognized_at' => now(),
            ]);

            return $reversal;
        }, 3);
    }

    public function getLowStockItems()
    {
        return Ingredient::whereColumn('current_quantity', '<=', 'min_quantity')
            ->where('is_active', true)
            ->get();
    }
}
