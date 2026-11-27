<?php

use App\Services\OperatingInsights;
use App\Services\StoreWeather;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('forecast:backfill-weather', function (OperatingInsights $insights) {
    $insights->warmForecastWeather(CarbonImmutable::now(StoreWeather::TIMEZONE));
})->purpose('Cache trading-hour weather for historical production forecasts');

Schedule::command('forecast:backfill-weather')->hourly()->withoutOverlapping();
