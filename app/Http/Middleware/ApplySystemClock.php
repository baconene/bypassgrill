<?php

namespace App\Http\Middleware;

use App\Support\SystemClock;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the admin has enabled a date/time override, set Carbon's "now" to the
 * effective time for this request. Scoped to admin users only so live cashier
 * sales are never backdated. Affects now() and Eloquent created_at/updated_at.
 */
class ApplySystemClock
{
    public function handle(Request $request, Closure $next): Response
    {
        if (SystemClock::isActive() && $request->user()?->hasRole('admin')) {
            Carbon::setTestNow(Carbon::createFromTimestamp(time() + SystemClock::offsetSeconds()));
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // Reset so the override never leaks across requests (e.g. under Octane).
        Carbon::setTestNow();
    }
}
