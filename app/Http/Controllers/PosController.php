<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function index(): Response
    {
        $categories = Category::where('is_active', true)
            ->orderBy('display_order')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
            ]);

        // Lazy so the POS polling partial reloads only rebuild the product list
        $products = fn () => Product::where('is_active', true)
            ->with(['category', 'recipes.ingredient', 'modifiers' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('display_order')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'price' => (float) $p->price,
                'image' => $p->image ? '/storage/' . $p->image : null,
                'category_id' => $p->category_id,
                'category' => $p->category ? ['id' => $p->category->id, 'name' => $p->category->name] : null,
                'modifiers' => $p->modifiers->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'price' => (float) $m->price,
                ]),
                ...$p->stockStatus(),
            ]);

        return Inertia::render('CashierDashboard', [
            'categories' => $categories,
            'products' => $products,
        ]);
    }
}
