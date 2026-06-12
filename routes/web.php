<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
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

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

// Public order view — no auth required (linked from receipt QR code)
Route::get('/public/orders/{id}', [\App\Http\Controllers\PublicOrderController::class, 'show'])
    ->where('id', '[0-9]+')
    ->name('public.orders.show');

// Printing architecture — animated explainer of the receipt printing pipeline
Route::inertia('/printing-architecture', 'PrintingArchitecture')
    ->name('printing.architecture');

Route::get('menu/{id}', [MenuController::class, 'show'])
    ->where('id', '[0-9]+')
    ->name('menu.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // POS (cashier)
    Route::get('pos', [PosController::class, 'index'])
        ->name('pos.index')
        ->middleware('can:create orders');

    // Kitchen monitor
    Route::get('kitchen', [QueueMonitorController::class, 'index'])
        ->name('kitchen.index')
        ->middleware('can:update orders');

    // Inventory management
    Route::get('inventory', [InventoryPageController::class, 'index'])
        ->name('inventory.index')
        ->middleware('can:view inventory');

    // Product management (admin only)
    Route::get('products', [ProductPageController::class, 'index'])
        ->name('products.index')
        ->middleware('role:admin');

    // Order detail
    Route::get('orders/{order}', [OrderDetailPageController::class, 'show'])
        ->name('orders.detail')
        ->middleware('can:view orders');

    // Financial
    Route::get('financial', [FinancialPageController::class, 'index'])
        ->name('financial.index')
        ->middleware('can:view reports');

    // Bills
    Route::get('bills', [BillsPageController::class, 'index'])
        ->name('bills.index')
        ->middleware('can:view reports');

    // Reports
    Route::get('reports', [ReportPageController::class, 'index'])
        ->name('reports.index')
        ->middleware('can:view reports');

    // HRIS
    Route::get('hris', [HrisPageController::class, 'index'])
        ->name('hris.index')
        ->middleware('role:admin');

    // Profit Distribution / Shareholders (admin only)
    Route::get('distribution', [\App\Http\Controllers\DistributionPageController::class, 'index'])
        ->name('distribution.index')
        ->middleware('role:admin');

    // Tools — read-only SQL console (admin only)
    Route::get('tools', [\App\Http\Controllers\ToolsPageController::class, 'index'])
        ->name('tools.index')
        ->middleware('role:admin');

    // Parcel Tracking
    Route::get('parcels', [ParcelPageController::class, 'index'])
        ->name('parcels.index');
    Route::get('parcels/{parcel}', [ParcelPageController::class, 'show'])
        ->name('parcels.show');
});

require __DIR__.'/settings.php';
