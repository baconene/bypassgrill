<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\HrisSetting;
use App\Models\PaymentTender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HrisSettingsController extends Controller
{
    public function edit(): Response
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $setting = HrisSetting::getSetting()->load('payrollTender');

        return Inertia::render('settings/Hris', [
            'settings' => [
                'payroll_tender_id' => $setting->payroll_tender_id,
            ],
            'tenders' => PaymentTender::where('is_active', true)
                ->orderBy('display_order')
                ->get(['id', 'name']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $validated = $request->validate([
            'payroll_tender_id' => 'nullable|integer|exists:payment_tenders,id',
        ]);

        HrisSetting::getSetting()->update($validated);

        return back()->with('success', 'HRIS settings updated.');
    }
}
