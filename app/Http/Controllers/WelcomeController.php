<?php

namespace App\Http\Controllers;

use App\Models\Advertisement;
use App\Models\Category;
use App\Models\PageSection;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class WelcomeController extends Controller
{
    public function index(): Response
    {
        $active = Advertisement::active()->get();

        // Lazy so the welcome page's polling partial reloads only rebuild the menu
        $categories = fn () => Category::with(['products' => function ($q) {
            $q->where('is_active', true)
              ->with('recipes.ingredient')
              ->orderBy('display_order')
              ->orderBy('name');
        }])
            ->where('is_active', true)
            ->whereHas('products', fn ($q) => $q->where('is_active', true))
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->map(fn ($cat) => [
                'name'     => $cat->name,
                'products' => $cat->products->map(function ($p) {
                    $tracked = $p->recipes->filter(fn ($r) => $r->ingredient?->track_inventory);

                    // Sold out: an ingredient is at zero or can't cover a single serving
                    $soldOut = $tracked->contains(fn ($r) => (float) $r->ingredient->current_quantity <= 0
                        || (float) $r->ingredient->current_quantity < (float) $r->quantity);

                    return [
                        'id'          => $p->id,
                        'name'        => $p->name,
                        'price'       => (float) $p->price,
                        'description' => $p->description,
                        'image'       => $p->image ? '/storage/' . $p->image : null,
                        'soldOut'     => $soldOut,
                        'lowStock'    => ! $soldOut && $tracked->contains(fn ($r) => $r->ingredient->isLowStock()),
                    ];
                })->values(),
            ]);

        $sections = PageSection::where('is_active', true)
            ->orderBy('display_order')
            ->get(['key', 'label', 'content', 'position']);

        return Inertia::render('Welcome', [
            'canRegister'      => Features::enabled(Features::registration()),
            'banners'          => $active->where('type', 'banner')->values(),
            'promos'           => $active->where('type', 'promo')->values(),
            'categories'       => $categories,
            'beforeSections'   => $sections->where('position', 'before_products')->values(),
            'afterSections'    => $sections->where('position', 'after_products')->values(),
        ]);
    }
}
