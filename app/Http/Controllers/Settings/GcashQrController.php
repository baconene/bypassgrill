<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\PrintServiceSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GcashQrController extends Controller
{
    private const PATH = 'payment/gcash_qr.png';

    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $request->validate([
            'gcash_qr' => 'required|image|max:2048|mimes:png,jpg,jpeg,webp',
        ]);

        Storage::disk('public')->delete(self::PATH);
        $request->file('gcash_qr')->storeAs('payment', 'gcash_qr.png', 'public');

        PrintServiceSetting::getSetting()->update(['gcash_qr_path' => self::PATH]);

        return back()->with('success', 'GCash QR code updated.');
    }

    public function destroy(): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        Storage::disk('public')->delete(self::PATH);

        PrintServiceSetting::getSetting()->update(['gcash_qr_path' => null]);

        return back()->with('success', 'GCash QR code removed.');
    }
}
