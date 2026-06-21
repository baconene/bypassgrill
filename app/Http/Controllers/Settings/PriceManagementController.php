<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PriceManagementController extends Controller
{
    public function index(): Response
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $categories = Category::with(['products' => function ($q) {
            $q->orderBy('display_order')->orderBy('name');
        }])
            ->whereHas('products')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->map(fn ($cat) => [
                'id'       => $cat->id,
                'name'     => $cat->name,
                'products' => $cat->products->map(fn ($p) => [
                    'id'        => $p->id,
                    'name'      => $p->name,
                    'sku'       => $p->sku,
                    'price'     => (float) $p->price,
                    'cost'      => (float) $p->cost,
                    'is_active' => $p->is_active,
                ]),
            ]);

        return Inertia::render('settings/Prices', [
            'categories' => $categories,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $request->validate([
            'prices'              => 'required|array',
            'prices.*.id'         => 'required|integer|exists:products,id',
            'prices.*.price'      => 'required|numeric|min:0',
            'prices.*.cost'       => 'required|numeric|min:0',
        ]);

        foreach ($request->prices as $row) {
            Product::where('id', $row['id'])->update([
                'price' => $row['price'],
                'cost'  => $row['cost'],
            ]);
        }

        return back()->with('success', 'Prices updated successfully.');
    }
}
