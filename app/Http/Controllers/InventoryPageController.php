<?php

namespace App\Http\Controllers;

use App\Enums\InventoryCostKind;
use App\Models\Ingredient;
use App\Models\InventoryCostEntry;
use App\Models\InventoryTransaction;
use Inertia\Inertia;
use Inertia\Response;

class InventoryPageController extends Controller
{
    public function index(): Response
    {
        $ingredients = Ingredient::with('components.ingredient')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'item_type' => $i->item_type ?? 'ingredient',
                'unit' => $i->unit,
                'current_quantity' => (float) $i->current_quantity,
                'min_quantity' => (float) $i->min_quantity,
                'cost_per_unit' => (float) ($i->cost_per_unit ?? 0),
                'is_low_stock' => $i->current_quantity <= $i->min_quantity,
                // Only a Food has components. The cost is what one unit would cost to
                // make now, against cost_per_unit, which is the average of batches
                // already made.
                'component_cost' => $i->isFood() ? $i->componentCost() : null,
                'components' => $i->components->map(fn ($c) => [
                    'ingredient_id' => $c->ingredient_id,
                    'ingredient_name' => $c->ingredient?->name,
                    'quantity' => (float) $c->quantity,
                    'unit' => $c->unit,
                    'cost_per_unit' => (float) ($c->ingredient?->cost_per_unit ?? 0),
                ])->values(),
            ]);

        $transactions = InventoryTransaction::with(['ingredient', 'user'])
            ->latest()
            ->limit(20)
            ->get();

        // A Stock In can be undone while its purchase entry has no reversal yet.
        // Resolved in one query so the history list stays free of per-row lookups.
        $undoable = InventoryCostEntry::whereIn('kind', [InventoryCostKind::PURCHASE->value, InventoryCostKind::PRODUCTION_OUTPUT->value])
            ->whereNull('reversal_of_id')
            ->whereIn('inventory_transaction_id', $transactions->pluck('id'))
            ->whereNotIn('id', InventoryCostEntry::whereNotNull('reversal_of_id')->select('reversal_of_id'))
            ->pluck('kind', 'inventory_transaction_id');

        $recentTransactions = $transactions
            ->map(fn ($t) => [
                'id' => $t->id,
                'ingredient_name' => $t->ingredient?->name,
                'type' => $t->type,
                'quantity' => (float) $t->quantity,
                'old_quantity' => (float) $t->old_quantity,
                'new_quantity' => (float) $t->new_quantity,
                'user_name' => $t->user?->name,
                'reference' => $t->reference,
                'order_id' => $t->order_id ?? (str_starts_with((string) $t->reference, 'order_')
                    ? (int) substr($t->reference, 6)
                    : null),
                'notes' => $t->notes,
                'can_undo' => isset($undoable[$t->id]),
                'undo_production' => ($undoable[$t->id] ?? null) === InventoryCostKind::PRODUCTION_OUTPUT,
                'created_at' => $t->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('InventoryManagement', [
            'ingredients' => $ingredients,
            'recentTransactions' => $recentTransactions,
        ]);
    }
}
