<?php

use App\Http\Controllers\Settings\AdvertisementController;
use App\Http\Controllers\Settings\GcashQrController;
use App\Http\Controllers\Settings\LogoController;
use App\Http\Controllers\Settings\MediaController;
use App\Http\Controllers\Settings\PageContentController;
use App\Http\Controllers\Settings\PriceManagementController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\PrintServiceSettingsController;
use App\Http\Controllers\Settings\SystemController;
use App\Http\Controllers\Settings\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/payment-tenders', [\App\Http\Controllers\Settings\PaymentTenderSettingsController::class, 'index'])
        ->name('settings.payment-tenders')
        ->middleware('role:admin');

    Route::post('settings/gcash-qr', [GcashQrController::class, 'update'])
        ->name('settings.gcash-qr.update')
        ->middleware('role:admin');
    Route::delete('settings/gcash-qr', [GcashQrController::class, 'destroy'])
        ->name('settings.gcash-qr.destroy')
        ->middleware('role:admin');

    Route::get('settings/users', [UserManagementController::class, 'index'])
        ->name('settings.users')
        ->middleware('role:admin');
    Route::post('settings/users', [UserManagementController::class, 'store'])
        ->name('settings.users.store')
        ->middleware('role:admin');
    Route::patch('settings/users/{user}', [UserManagementController::class, 'update'])
        ->name('settings.users.update')
        ->middleware('role:admin');
    Route::delete('settings/users/{user}', [UserManagementController::class, 'destroy'])
        ->name('settings.users.destroy')
        ->middleware('role:admin');

    Route::get('settings/print-service', [PrintServiceSettingsController::class, 'edit'])
        ->name('settings.print-service')
        ->middleware('role:admin');

    Route::get('settings/logo', [LogoController::class, 'edit'])
        ->name('settings.logo')
        ->middleware('role:admin');

    // Date & Time override (admin only)
    Route::get('settings/clock', [\App\Http\Controllers\Settings\SystemClockController::class, 'edit'])
        ->name('settings.clock')->middleware('role:admin');
    Route::post('settings/clock', [\App\Http\Controllers\Settings\SystemClockController::class, 'update'])
        ->name('settings.clock.update')->middleware('role:admin');
    Route::delete('settings/clock', [\App\Http\Controllers\Settings\SystemClockController::class, 'disable'])
        ->name('settings.clock.disable')->middleware('role:admin');

    Route::get('settings/kitchen', [\App\Http\Controllers\Settings\KitchenSettingsController::class, 'edit'])
        ->name('settings.kitchen')
        ->middleware('role:admin');
    Route::post('settings/kitchen', [\App\Http\Controllers\Settings\KitchenSettingsController::class, 'update'])
        ->name('settings.kitchen.update')
        ->middleware('role:admin');

    Route::get('settings/hris', [\App\Http\Controllers\Settings\HrisSettingsController::class, 'edit'])
        ->name('settings.hris')
        ->middleware('role:admin');
    Route::post('settings/hris', [\App\Http\Controllers\Settings\HrisSettingsController::class, 'update'])
        ->name('settings.hris.update')
        ->middleware('role:admin');

    // System (admin only)
    Route::get('settings/system', [SystemController::class, 'index'])
        ->name('settings.system')
        ->middleware('role:admin');
    Route::post('settings/system/reset', [SystemController::class, 'reset'])
        ->name('settings.system.reset')
        ->middleware('role:admin');
    Route::post('settings/system/factory-reset', [SystemController::class, 'factoryReset'])
        ->name('settings.system.factory-reset')
        ->middleware('role:admin');
    Route::post('settings/logo', [LogoController::class, 'update'])
        ->name('settings.logo.update')
        ->middleware('role:admin');
    Route::post('settings/logo/name', [LogoController::class, 'updateName'])
        ->name('settings.logo.name')
        ->middleware('role:admin');
    Route::delete('settings/logo', [LogoController::class, 'destroy'])
        ->name('settings.logo.destroy')
        ->middleware('role:admin');

    Route::get('settings/prices', [PriceManagementController::class, 'index'])
        ->name('settings.prices')
        ->middleware('role:admin');
    Route::patch('settings/prices', [PriceManagementController::class, 'update'])
        ->name('settings.prices.update')
        ->middleware('role:admin');

    Route::get('settings/advertisements', [AdvertisementController::class, 'index'])
        ->name('settings.advertisements')
        ->middleware('role:admin');
    Route::post('settings/advertisements', [AdvertisementController::class, 'store'])
        ->name('settings.advertisements.store')
        ->middleware('role:admin');
    Route::patch('settings/advertisements/{advertisement}', [AdvertisementController::class, 'update'])
        ->name('settings.advertisements.update')
        ->middleware('role:admin');
    Route::delete('settings/advertisements/{advertisement}', [AdvertisementController::class, 'destroy'])
        ->name('settings.advertisements.destroy')
        ->middleware('role:admin');
    Route::post('settings/advertisements/{advertisement}/toggle', [AdvertisementController::class, 'toggle'])
        ->name('settings.advertisements.toggle')
        ->middleware('role:admin');

    Route::get('settings/page-content', [PageContentController::class, 'index'])
        ->name('settings.page-content')
        ->middleware('role:admin');
    Route::post('settings/page-content', [PageContentController::class, 'store'])
        ->name('settings.page-content.store')
        ->middleware('role:admin');
    Route::patch('settings/page-content/{section}', [PageContentController::class, 'update'])
        ->name('settings.page-content.update')
        ->middleware('role:admin');
    Route::delete('settings/page-content/{section}', [PageContentController::class, 'destroy'])
        ->name('settings.page-content.destroy')
        ->middleware('role:admin');
    Route::post('settings/page-content/reorder', [PageContentController::class, 'reorder'])
        ->name('settings.page-content.reorder')
        ->middleware('role:admin');
    Route::post('settings/page-content/{section}/toggle', [PageContentController::class, 'toggle'])
        ->name('settings.page-content.toggle')
        ->middleware('role:admin');

    Route::get('settings/media', [MediaController::class, 'index'])
        ->name('settings.media')
        ->middleware('role:admin');
    Route::post('settings/media', [MediaController::class, 'store'])
        ->name('settings.media.store')
        ->middleware('role:admin');
    Route::get('settings/media/json', function () {
        abort_unless(auth()->user()?->hasRole('admin'), 403);
        return response()->json(
            \App\Models\MediaFile::latest()->get()->map(fn ($f) => [
                'id'            => $f->id,
                'original_name' => $f->original_name,
                'filename'      => $f->filename,
                'mime_type'     => $f->mime_type,
                'size'          => $f->size,
                'url'           => $f->url,
                'is_image'      => $f->is_image,
            ])
        );
    })->name('settings.media.json')->middleware('role:admin');
    Route::delete('settings/media/{media}', [MediaController::class, 'destroy'])
        ->name('settings.media.destroy')
        ->middleware('role:admin');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
    Route::inertia('settings/printing', 'settings/Printing')->name('settings.printing');
});
