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
                'products' => $cat->products->map(fn ($p) => [
                    'id'          => $p->id,
                    'name'        => $p->name,
                    'price'       => (float) $p->price,
                    'description' => $p->description,
                    'image'       => $p->image ? '/storage/' . $p->image : null,
                    ...$p->stockStatus(),
                ])->values(),
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
            // Transparent dish cutouts for the homepage showcase, keyed by file name (e.g. pork-monster-ribs)
            'cutouts'          => collect([
                ...(glob(public_path('images/menu/cutouts/*.webp')) ?: []),
                ...(glob(public_path('images/menu/cutouts/*.png')) ?: []),
            ])->mapWithKeys(fn ($path) => [
                pathinfo($path, PATHINFO_FILENAME) => '/images/menu/cutouts/'.basename($path),
            ]),
            // 3D models (.glb) for a true 3D spin, keyed the same way (e.g. pork-monster-ribs)
            'models'           => collect(glob(public_path('images/menu/3d/*.glb')) ?: [])
                ->mapWithKeys(fn ($path) => [
                    pathinfo($path, PATHINFO_FILENAME) => '/images/menu/3d/'.basename($path),
                ]),
        ]);
    }
}
