<?php

namespace App\Http\Middleware;

use App\Support\Brand;
use App\Support\SystemClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'brandName' => Brand::name(),
            'auth' => [
                'user' => $user,
                'roles' => $user ? $user->getRoleNames() : [],
                'permissions' => $user ? $user->getAllPermissions()->pluck('name') : [],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'logoUrl' => Storage::disk('public')->exists('logo/current.png')
                ? Storage::disk('public')->url('logo/current.png')
                : null,
            'systemClock' => [
                'active' => SystemClock::isActive(),
                'label'  => SystemClock::isActive() ? SystemClock::status()['label'] : null,
            ],
        ];
    }
}
