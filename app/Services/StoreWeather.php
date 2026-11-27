<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class StoreWeather
{
    public const TIMEZONE = 'Asia/Manila';

    private const ENDPOINT = 'https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline';

    public function forecast(CarbonImmutable $now): array
    {
        $weather = $this->cachedForecast();
        $nextHour = $now->startOfHour()->addHour()->timestamp;
        $hours = collect($this->hours($weather))
            ->filter(fn ($hour) => $hour['timestamp'] >= $nextHour && $hour['timestamp'] < $nextHour + 12 * 3600)
            ->values()->all();
        $current = $this->hour($weather['currentConditions'] ?? []);

        return [
            'available' => $current !== null || $hours !== [],
            'location' => config('services.store_weather.location'),
            'current' => $current,
            'hours' => $hours,
        ];
    }

    public function observedHours(CarbonImmutable $date, CarbonImmutable $now): array
    {
        $key = $date->toDateString();
        $hours = Cache::get($this->observedKey($key));
        if ($hours !== null) {
            return $hours;
        }

        $hours = $this->observations($this->fetch('/'.$key, 'hours'));
        $this->cacheObservations($key, $hours, $now);

        return $hours;
    }

    private function cachedForecast(): array
    {
        return Cache::remember('pos-weather:forecast:'.config('services.store_weather.coordinates'), 1200,
            fn () => $this->fetch('', 'current,hours'));
    }

    public function trainingWeather(array $dates, CarbonImmutable $now): array
    {
        $key = 'pos-weather:training:'.config('services.store_weather.coordinates');
        $fetchLimit = $dates !== [] && Cache::add($key, true, 600) ? 12 : 0;

        return $this->serviceWeather($dates, $now, fetchLimit: $fetchLimit);
    }

    public function serviceWeather(array $dates, CarbonImmutable $now, bool $forecast = false, ?int $fetchLimit = null): array
    {
        $hours = [];
        $missing = [];
        if ($forecast) {
            $forecastHours = collect($this->hours($this->cachedForecast()))->keyBy('timestamp')->all();
            $hours = array_fill_keys($dates, $forecastHours);
        } else {
            foreach ($dates as $date) {
                $cached = Cache::get($this->observedKey($date));
                if ($cached === null) {
                    $missing[] = $date;
                } else {
                    $hours[$date] = $cached;
                }
            }
        }

        if ($fetchLimit !== null) {
            $missing = array_slice($missing, 0, $fetchLimit);
        }
        if ($missing !== []) {
            $responses = Http::pool(fn (Pool $pool) => array_map(fn ($date) => $pool->as($date)
                ->acceptJson()->withOptions(['decode_content' => 'gzip'])->connectTimeout(3)->timeout(6)
                ->get($this->url('/'.$date), $this->parameters('hours')), $missing), 6);
            foreach ($missing as $date) {
                $response = $responses[$date] ?? null;
                $json = $response instanceof Response && $response->successful() ? $response->json() : null;
                $hours[$date] = $this->observations(is_array($json) ? $json : []);
                $this->cacheObservations($date, $hours[$date], $now);
            }
        }

        $result = [];
        foreach ($dates as $date) {
            $day = CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay();
            $open = (int) config('services.store_weather.opens_hour');
            $close = (int) config('services.store_weather.closes_hour');
            $values = [];
            $clearHours = $conditionHours = 0;
            for ($h = $open; $h < $close; $h++) {
                $hour = $hours[$date][$day->addHours($h)->timestamp] ?? [];
                $value = $hour[$forecast ? 'rain_chance' : 'rain_mm'] ?? null;
                if ($value !== null) {
                    $values[] = $value;
                    $condition = strtolower(trim($hour['conditions'] ?? ''));
                    $conditionHours += $condition !== '' ? 1 : 0;
                    $clearHours += $condition === 'clear' ? 1 : 0;
                }
            }

            // Enough coverage to label a whole shift, rather than one isolated hour.
            $known = count($values) >= max(1, (int) ceil(($close - $open) / 2));
            $rainy = $known ? max($values) >= ($forecast ? 60 : 0.1) : null;
            $condition = $conditionHours > 0 ? ($clearHours > $conditionHours / 2 ? 'clear' : 'cloud') : null;
            $result[$date] = [
                'rainy' => $rainy,
                'condition' => $known ? ($rainy ? 'rain' : $condition) : null,
                'rainy_hours' => !$forecast && $known ? count(array_filter($values, fn ($value) => $value >= 0.1)) : null,
                'rain_chance' => $forecast && $values !== [] ? max($values) : null,
                'rain_mm' => !$forecast && $known ? round(array_sum($values), 2) : null,
            ];
        }

        return $result;
    }

    private function observedKey(string $date): string
    {
        return 'pos-weather:observed:'.config('services.store_weather.coordinates').':'.$date;
    }

    private function observations(array $data): array
    {
        return collect($this->hours($data))
            ->filter(fn ($hour) => $hour['source'] === 'obs' && $hour['rain_mm'] !== null)
            ->keyBy('timestamp')->all();
    }

    private function cacheObservations(string $date, array $hours, CarbonImmutable $now): void
    {
        $ttl = $hours === [] ? 900 : ($date < $now->subDays(2)->toDateString() ? 365 * 86400 : 1800);
        Cache::put($this->observedKey($date), $hours, $ttl);
    }

    private function url(string $dates): string
    {
        return self::ENDPOINT.'/'.config('services.store_weather.coordinates').$dates;
    }

    private function parameters(string $include): array
    {
        $params = [
            'unitGroup' => 'metric',
            'include' => $include,
            'elements' => 'datetime,datetimeEpoch,temp,precip,precipprob,conditions,source',
            'contentType' => 'json',
            'appid' => 'bypass-grill-pos',
        ];
        if ($key = config('services.store_weather.key')) {
            $params['key'] = $key;
        }

        return $params;
    }

    private function fetch(string $dates, string $include): array
    {
        try {
            $response = Http::acceptJson()->withOptions(['decode_content' => 'gzip'])->connectTimeout(3)->timeout(6)
                ->get($this->url($dates), $this->parameters($include));

            return $response->successful() && is_array($response->json()) ? $response->json() : [];
        } catch (ConnectionException) {
            return [];
        }
    }

    private function hours(array $weather): array
    {
        return collect($weather['days'] ?? [])->flatMap(fn ($day) => $day['hours'] ?? [])
            ->map(fn ($hour) => $this->hour($hour))->filter()->unique('timestamp')
            ->sortBy('timestamp')->values()->all();
    }

    private function hour(array $row): ?array
    {
        if (!is_numeric($row['datetimeEpoch'] ?? null)) {
            return null;
        }

        return [
            'timestamp' => (int) $row['datetimeEpoch'],
            'temp_c' => is_numeric($row['temp'] ?? null) ? (float) $row['temp'] : null,
            'rain_chance' => is_numeric($row['precipprob'] ?? null) ? (float) $row['precipprob'] : null,
            'rain_mm' => is_numeric($row['precip'] ?? null) ? (float) $row['precip'] : null,
            'conditions' => $row['conditions'] ?? null,
            'source' => $row['source'] ?? null,
        ];
    }
}
