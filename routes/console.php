<?php

use App\Services\ForecastSnapshots;
use App\Services\OperatingInsights;
use App\Services\StoreWeather;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('forecast:backfill-weather', function (OperatingInsights $insights) {
    $insights->warmForecastWeather(CarbonImmutable::now(StoreWeather::TIMEZONE));
})->purpose('Cache trading-hour weather for historical production forecasts');

Schedule::command('forecast:backfill-weather')->hourly()->withoutOverlapping();

Artisan::command('forecast:capture', function (ForecastSnapshots $snapshots) {
    $result = $snapshots->capture(CarbonImmutable::now(StoreWeather::TIMEZONE));
    $this->info("Saved {$result['saved']} forecasts and reconciled {$result['evaluated']} snapshots.");
})->purpose('Save the daily production forecast and reconcile completed service dates');

Schedule::command('forecast:capture')->dailyAt('10:00')->timezone(StoreWeather::TIMEZONE)->withoutOverlapping();

Artisan::command('forecast:replay', function (ForecastSnapshots $snapshots) {
    $result = $snapshots->replay(CarbonImmutable::now(StoreWeather::TIMEZONE));
    $this->info("Saved {$result['saved']} replay snapshots through {$result['through']}.");
})->purpose('Replay production forecasts day by day from June 2026');

Schedule::command('forecast:replay')->everyTenMinutes()->withoutOverlapping()
    ->when(fn () => !DB::table('production_forecast_snapshots')->where('run_type', 'replay')
        ->where('forecast_as_of', ForecastSnapshots::REPLAY_END)->exists());
