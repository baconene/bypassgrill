<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class StoreWeather
{
    public const TIMEZONE = 'Asia/Manila';

    private const ENDPOINT = 'https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline';

    public function forecast(CarbonImmutable $now): array
    {
        $weather = Cache::remember('pos-weather:forecast:'.config('services.store_weather.coordinates'), 1200,
            fn () => $this->fetch('', 'current,hours'));
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
        $key = 'pos-weather:observed:'.config('services.store_weather.coordinates').':'.$date->toDateString();
        $hours = Cache::get($key);
        if ($hours !== null) {
            return $hours;
        }

        $data = $this->fetch('/'.$date->toDateString(), 'hours');
        $hours = collect($this->hours($data))
            ->filter(fn ($hour) => $hour['source'] === 'obs' && $hour['rain_mm'] !== null)
            ->keyBy('timestamp')->all();
        $ttl = $hours === [] ? 900 : ($date->lt($now->subDays(2)->startOfDay()) ? 30 * 86400 : 1800);
        Cache::put($key, $hours, $ttl);

        return $hours;
    }

    private function fetch(string $dates, string $include): array
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

        try {
            $response = Http::acceptJson()->withOptions(['decode_content' => 'gzip'])->connectTimeout(3)->timeout(6)
                ->get(self::ENDPOINT.'/'.config('services.store_weather.coordinates').$dates, $params);

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
