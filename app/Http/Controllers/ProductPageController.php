<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use Inertia\Inertia;
use Inertia\Response;

class ProductPageController extends Controller
{
    public function index(): Response
    {
        $products = Product::with(['category', 'recipes.ingredient'])
            ->orderBy('category_id')
            ->orderBy('display_order')
            ->get()
            ->map(function ($p) {
                // What the recipe costs at today's ingredient prices. The stored cost only
                // moves when someone presses Calculate, so the two drift apart as stock is
                // bought at new prices. The page shows both and says which is which.
                $recipeCost = round($p->recipes->sum(
                    fn ($r) => (float) $r->quantity * (float) ($r->ingredient?->cost_per_unit ?? 0)
                ), 2);
                $storedCost = round((float) $p->cost, 2);
                $priced = $p->recipes->every(fn ($r) => $r->ingredient !== null);

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'sku' => $p->sku,
                    'description' => $p->description,
                    'price' => (float) $p->price,
                    'cost' => $storedCost,
                    'recipe_cost' => $recipeCost,
                    // Only meaningful when there is a recipe to compare against.
                    'cost_drift' => $p->recipes->isEmpty() ? 0.0 : round($recipeCost - $storedCost, 2),
                    'has_recipe' => $p->recipes->isNotEmpty(),
                    'recipe_priced' => $priced,
                    'is_active' => $p->is_active,
                    'display_order' => $p->display_order,
                    'image' => $p->image ? '/storage/'.$p->image : null,
                    'category_id' => $p->category_id,
                    'category_name' => $p->category?->name,
                    'recipes' => $p->recipes->map(fn ($r) => [
                        'ingredient_id' => $r->ingredient_id,
                        'ingredient_name' => $r->ingredient?->name,
                        'quantity' => (float) $r->quantity,
                        'unit' => $r->unit,
                        'cost_per_unit' => (float) ($r->ingredient?->cost_per_unit ?? 0),
                        'line_cost' => round((float) $r->quantity * (float) ($r->ingredient?->cost_per_unit ?? 0), 2),
                    ])->values(),
                ];
            });

        $categories = Category::where('is_active', true)->orderBy('display_order')->get(['id', 'name']);
        $ingredients = Ingredient::where('is_active', true)->orderBy('name')->get(['id', 'name', 'item_type', 'unit', 'cost_per_unit'])
            ->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'item_type' => $i->item_type ?? 'ingredient',
                'unit' => $i->unit,
                'cost_per_unit' => (float) ($i->cost_per_unit ?? 0),
            ]);

        return Inertia::render('ProductManagement', compact('products', 'categories', 'ingredients'));
    }
}
