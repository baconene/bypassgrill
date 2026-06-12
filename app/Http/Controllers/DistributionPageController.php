<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class DistributionPageController extends Controller
{
    public function index(): Response
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        return Inertia::render('distribution/Dashboard', [
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'products'   => Product::where('is_active', true)->orderBy('name')->get(['id', 'name', 'category_id']),
            'users'      => User::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
