<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\InventoryTransaction;
use Inertia\Inertia;
use Inertia\Response;

class InventoryPageController extends Controller
{
    public function index(): Response
    {
        $ingredients = Ingredient::where('is_active', true)
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
            ]);

        $recentTransactions = InventoryTransaction::with(['ingredient', 'user'])
            ->latest()
            ->limit(20)
            ->get()
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
                'created_at' => $t->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('InventoryManagement', [
            'ingredients' => $ingredients,
            'recentTransactions' => $recentTransactions,
        ]);
    }
}
