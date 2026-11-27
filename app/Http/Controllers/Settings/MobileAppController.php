<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MobileAppController extends Controller
{
    private const APK_URL = 'https://github.com/baconene/bypassgrill/releases/download/android-pos/bypass-grill-pos.apk';

    public function show(): Response
    {
        return Inertia::render('settings/MobileApp');
    }

    public function download(): RedirectResponse
    {
        return redirect()->away(self::APK_URL);
    }
}
