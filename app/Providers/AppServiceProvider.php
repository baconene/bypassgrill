<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();
        $this->configureDistributionCache();
    }

    /**
     * Invalidate the profit-distribution cache whenever P&L inputs change, so the
     * profit-sharing summary stays in sync with the live financial report.
     */
    protected function configureDistributionCache(): void
    {
        $bump = fn () => \App\Services\Distribution\ProfitDistributionService::bumpCacheVersion();

        foreach ([\App\Models\Order::class, \App\Models\OrderItem::class, \App\Models\FinancialTransaction::class] as $model) {
            $model::saved($bump);
            $model::deleted($bump);
        }
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(app()->isProduction());

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)->mixedCase()->letters()->numbers()->symbols()->uncompromised()
            : null,
        );
    }

    protected function configureGates(): void
    {
        // Admin role bypasses all gate and policy checks
        Gate::before(function ($user, $ability) {
            if ($user->hasRole('admin')) {
                return true;
            }
        });
    }
}
