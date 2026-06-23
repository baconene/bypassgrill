<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\KitchenSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KitchenSettingsController extends Controller
{
    public function edit(): Response
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        return Inertia::render('settings/Kitchen', [
            'settings' => KitchenSetting::getSetting(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $validated = $request->validate([
            'serving_fast_minutes' => 'required|integer|min:1|max:60',
            'serving_slow_minutes' => 'required|integer|min:1|max:120',
        ]);

        if ($validated['serving_fast_minutes'] >= $validated['serving_slow_minutes']) {
            return back()->withErrors(['serving_slow_minutes' => 'Slow threshold must be greater than the fast threshold.']);
        }

        KitchenSetting::getSetting()->update($validated);

        return back()->with('success', 'Kitchen settings updated.');
    }
}
