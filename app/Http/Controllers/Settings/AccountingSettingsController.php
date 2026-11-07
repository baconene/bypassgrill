<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AccountingSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountingSettingsController extends Controller
{
    public function edit(): Response
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        return Inertia::render('settings/Accounting', [
            'settings' => [
                'period_start_day' => AccountingSetting::startDay(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $validated = $request->validate([
            'period_start_day' => 'required|integer|min:1|max:28',
        ]);

        AccountingSetting::getSetting()->update($validated);

        return back()->with('success', 'Accounting settings updated.');
    }
}
