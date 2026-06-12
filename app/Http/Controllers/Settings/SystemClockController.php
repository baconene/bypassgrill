<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\SystemClock;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemClockController extends Controller
{
    public function edit(): Response
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        return Inertia::render('settings/SystemClock', [
            'clock' => SystemClock::status(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $data = $request->validate([
            'datetime' => 'required|date',
        ]);

        SystemClock::set($data['datetime']);
        $this->log('system_clock.enabled', "Enabled date override → {$data['datetime']}");

        return back()->with('success', 'System date/time override enabled.');
    }

    public function disable(): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        SystemClock::disable();
        $this->log('system_clock.disabled', 'Disabled date override (back to real time)');

        return back()->with('success', 'Reverted to real system time.');
    }

    private function log(string $action, string $description): void
    {
        try {
            AuditLog::create([
                'user_id'     => auth()->id(),
                'action'      => $action,
                'new_values'  => SystemClock::status(),
                'ip_address'  => request()->ip(),
                'description' => $description,
            ]);
        } catch (\Throwable) { /* never block on audit */ }
    }
}
