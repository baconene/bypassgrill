<?php

namespace App\Http\Controllers\Settings;

use App\Models\PaymentTender;
use App\Models\PrintServiceSetting;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PaymentTenderSettingsController extends \App\Http\Controllers\Controller
{
    public function index(): Response
    {
        $setting    = PrintServiceSetting::getSetting();
        $gcashQrUrl = $setting->gcash_qr_path && Storage::disk('public')->exists($setting->gcash_qr_path)
            ? Storage::disk('public')->url($setting->gcash_qr_path)
            : null;

        return Inertia::render('settings/PaymentTenders', [
            'tenders'    => PaymentTender::orderBy('display_order')->orderBy('name')->get(),
            'gcashQrUrl' => $gcashQrUrl,
        ]);
    }
}
