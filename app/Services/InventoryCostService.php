<?php

namespace App\Services;

use App\Enums\InventoryTransactionType;
use App\Models\Ingredient;
use App\Models\InventoryCostEntry;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class InventoryCostService
{
    public function consume(OrderItem $item): bool
    {
        return DB::transaction(function () use ($item) {
            $order = Order::whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($order->status, ['cancelled', 'voided']), 422, 'Cannot consume stock for a cancelled order.');
            $item = OrderItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->inventory_costed_at) {
                return true;
            }
            $recipes = $item->product->recipes()->orderBy('ingredient_id')->get();
            // Lock all ingredients before checking: concurrent orders cannot consume the same stock.
            $ingredients = Ingredient::whereIn('id', $recipes->pluck('ingredient_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($recipes->groupBy('ingredient_id') as $id => $lines) {
                abort_if($lines->contains(fn ($line) => (float) $line->quantity <= 0), 422, 'Complete the product recipe quantities before ordering.');
                $ingredient = $ingredients->get($id);
                abort_unless($ingredient, 422, 'A recipe ingredient was removed. Update the recipe before ordering.');
                $required = round((float) $lines->sum('quantity') * $item->quantity, 3);
                abort_if($required < 0, 422, 'Recipe quantities cannot be negative.');
                abort_if($ingredient->track_inventory && (float) $ingredient->current_quantity < $required, 422, 'Not enough '.$ingredient->name.' in stock.');
            }
            $reference = 'order_'.$order->id;
            foreach ($recipes as $recipe) {
                $ingredient = $ingredients[$recipe->ingredient_id];
                $quantity = round((float) $recipe->quantity * $item->quantity, 3);
                $tx = $ingredient->track_inventory ? app(InventoryService::class)->recordTransaction(
                    $ingredient, $quantity, InventoryTransactionType::STOCK_OUT, $reference,
                    'Order #'.$order->id.' / '.$item->product->name,
                    recordCost: false, orderId: $order->id, orderItemId: $item->id,
                ) : null;
                // Food is tracked like any other stock but reports separately, so the
                // ledger can say how much of COGS was prepped rather than raw.
                $source = match (true) {
                    $ingredient->isFood() => 'food',
                    $ingredient->track_inventory => 'ingredient',
                    default => 'untracked_ingredient',
                };
                InventoryCostEntry::create([
                    'kind' => 'consumption', 'source' => $source,
                    'inventory_transaction_id' => $tx?->id, 'ingredient_id' => $ingredient->id, 'ingredient_name' => $ingredient->name,
                    'order_id' => $order->id, 'order_item_id' => $item->id, 'reference' => $reference,
                    'quantity' => $quantity, 'unit_cost' => $ingredient->cost_per_unit,
                    'total_cost' => round($quantity * (float) $ingredient->cost_per_unit, 2),
                    'user_id' => auth()->id(), 'recognized_at' => now(),
                ]);
            }
            if ($recipes->isEmpty()) {
                InventoryCostEntry::create([
                    'kind' => 'consumption', 'source' => 'product_fallback', 'order_id' => $order->id,
                    'order_item_id' => $item->id, 'reference' => $reference, 'quantity' => $item->quantity,
                    'unit_cost' => $item->product->cost ?? 0,
                    'total_cost' => round($item->quantity * (float) ($item->product->cost ?? 0), 2),
                    'user_id' => auth()->id(), 'recognized_at' => now(),
                ]);
            }
            // Shadow rollout: preserve existing reported item costs until the report cutover.
            $item->forceFill(['inventory_costed_at' => now()])->save();

            return true;
        }, 3);
    }

    public function restore(Order $order, string $action): void
    {
        $entries = InventoryCostEntry::where('order_id', $order->id)->where('kind', 'consumption')
            ->whereNotIn('id', InventoryCostEntry::whereNotNull('reversal_of_id')->select('reversal_of_id'))
            ->orderBy('ingredient_id')->lockForUpdate()->get();
        foreach ($entries as $entry) {
            $tx = null;
            // Restore what actually moved, including Food, even if tracking changed later.
            if ($entry->inventory_transaction_id !== null) {
                $ingredient = Ingredient::withTrashed()->whereKey($entry->ingredient_id)->lockForUpdate()->firstOrFail();
                $tx = app(InventoryService::class)->recordTransaction(
                    $ingredient, (float) $entry->quantity, InventoryTransactionType::STOCK_IN,
                    'order_'.$order->id.'_'.$action, ucfirst($action).' Order #'.$order->id,
                    recordCost: false, orderId: $order->id, orderItemId: $entry->order_item_id,
                    unitCost: (float) $entry->unit_cost,
                );
            }
            InventoryCostEntry::create([
                'kind' => 'consumption_reversal', 'source' => $entry->source,
                'inventory_transaction_id' => $tx?->id, 'ingredient_id' => $entry->ingredient_id, 'ingredient_name' => $entry->ingredient_name,
                'order_id' => $order->id, 'order_item_id' => $entry->order_item_id, 'reference' => 'order_'.$order->id.'_'.$action,
                'quantity' => -(float) $entry->quantity, 'unit_cost' => $entry->unit_cost,
                'total_cost' => -(float) $entry->total_cost, 'reversal_of_id' => $entry->id,
                'user_id' => auth()->id(), 'recognized_at' => now(),
            ]);
        }
    }
}
