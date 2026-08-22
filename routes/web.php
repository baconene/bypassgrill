<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\QueueMonitorController;
use App\Http\Controllers\InventoryPageController;
use App\Http\Controllers\ProductPageController;
use App\Http\Controllers\ReportPageController;
use App\Http\Controllers\FinancialPageController;
use App\Http\Controllers\BillsPageController;
use App\Http\Controllers\OrderDetailPageController;
use App\Http\Controllers\HrisPageController;
use App\Http\Controllers\ParcelPageController;
use App\Http\Controllers\WelcomeController;

Route::get('/', [WelcomeController::class, 'index'])->name('home');

Route::get('/public/orders/{token}', [\App\Http\Controllers\PublicOrderController::class, 'show'])
    ->where('token', '[0-9a-f]{32}')
    ->name('public.orders.show');

Route::inertia('/printing-architecture', 'PrintingArchitecture')
    ->name('printing.architecture');

Route::inertia('/hi-tan', 'HiTan')
    ->name('hi.tan');

Route::inertia('/gumroadDescription', 'GumroadDescription')
    ->name('gumroad.description');

Route::get('menu/{id}', [MenuController::class, 'show'])
    ->where('id', '[0-9]+')
    ->name('menu.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('pos', [PosController::class, 'index'])
        ->name('pos.index')
        ->middleware('can:create orders');

    Route::get('kitchen', [QueueMonitorController::class, 'index'])
        ->name('kitchen.index')
        ->middleware('can:update orders');

    Route::get('inventory', [InventoryPageController::class, 'index'])
        ->name('inventory.index')
        ->middleware('can:view inventory');

    Route::get('products', [ProductPageController::class, 'index'])
        ->name('products.index')
        ->middleware('role:admin');

    Route::get('orders/{order}', [OrderDetailPageController::class, 'show'])
        ->name('orders.detail')
        ->middleware('can:view orders');

    Route::get('financial', [FinancialPageController::class, 'index'])
        ->name('financial.index')
        ->middleware('can:view reports');

    Route::get('bills', [BillsPageController::class, 'index'])
        ->name('bills.index')
        ->middleware('can:view reports');

    Route::get('reports', [ReportPageController::class, 'index'])
        ->name('reports.index')
        ->middleware('can:view reports');

    Route::get('hris', [HrisPageController::class, 'index'])
        ->name('hris.index')
        ->middleware('role:admin');

    Route::get('distribution', [\App\Http\Controllers\DistributionPageController::class, 'index'])
        ->name('distribution.index')
        ->middleware('role:admin');

    // Tools — read-only SQL console (admin only)
    Route::get('tools', [\App\Http\Controllers\ToolsPageController::class, 'index'])
        ->name('tools.index')
        ->middleware('role:admin');

    // System documentation
    Route::get('documentation', fn () => \Inertia\Inertia::render('Documentation'))
        ->name('documentation.index')
        ->middleware('role:admin');
    Route::get('documentation/{module}', fn (string $module) => \Inertia\Inertia::render('Documentation/Detail', ['module' => $module]))
        ->name('documentation.detail')
        ->middleware('role:admin')
        ->where('module', '[a-z-]+');

    // Parcel Tracking
    Route::get('parcels', [ParcelPageController::class, 'index'])
        ->name('parcels.index');
    Route::get('parcels/{parcel}', [ParcelPageController::class, 'show'])
        ->name('parcels.show');

    // Stall Mapping
    Route::get('mapping', fn () => \Inertia\Inertia::render('Mapping'))
        ->name('mapping')
        ->middleware('role:admin');
});

require __DIR__.'/settings.php';
