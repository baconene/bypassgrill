<?php

namespace App\Services;

use App\Models\FinancialTransaction;
use Carbon\CarbonImmutable;

class SalesDayInsights
{
    public function __construct(private StoreWeather $weather) {}

    public function report(CarbonImmutable $day, CarbonImmutable $now): array
    {
        $dayEnd = $day->addDay();
        $completedHour = $now->startOfHour();
        $end = $dayEnd->lt($completedHour) ? $dayEnd : $completedHour;
        $partial = $end->lt($dayEnd);

        // Transaction timestamps are stored in Manila wall time.
        $ledger = FinancialTransaction::query()->where('type', 'payment')
            ->where('transacted_at', '>=', $day->subWeeks(12))
            ->where('transacted_at', '<', $dayEnd)
            ->selectRaw('SUBSTR(transacted_at, 1, 13) AS payment_hour, SUM(amount) AS total, COUNT(*) AS payments')
            ->groupByRaw('SUBSTR(transacted_at, 1, 13)')
            ->get()
            ->groupBy(fn ($row) => substr($row->payment_hour, 0, 10));
        $todayRows = $ledger->get($day->toDateString(), collect());
        $completed = $todayRows->filter(fn ($row) => $row->payment_hour < $end->format('Y-m-d H'));
        $collected = (float) $completed->sum('total');

        $comparison = [];
        for ($weeks = 1; $weeks <= 12; $weeks++) {
            $prior = $day->subWeeks($weeks);
            $rows = $ledger->get($prior->toDateString());
            if ($this->isPayday($prior) || !$rows) {
                continue;
            }

            $comparison[] = (float) $rows->sum(fn ($row) => !$partial ||
                (int) substr($row->payment_hour, -2) < $end->hour ? $row->total : 0);
        }

        $dates = count($comparison);
        $baseline = $dates > 0 ? array_sum($comparison) / $dates : null;
        $cutoff = $partial ? $end->format('H:i') : null;
        $hours = $this->tradingHours($day, $end, $now, $todayRows->keyBy('payment_hour')->all());
        $known = array_filter($hours, fn ($hour) => $hour['rain_mm'] !== null);
        $wet = array_filter($known, fn ($hour) => $hour['rain_mm'] >= 0.1);
        $dry = array_filter($known, fn ($hour) => $hour['rain_mm'] < 0.1);

        return [
            'date' => $day->toDateString(),
            'paid_sales' => round($collected, 2),
            'payments' => (int) $completed->sum('payments'),
            'through' => $cutoff,
            'payday' => [
                'is_payday' => $this->isPayday($day),
                'weekday' => $day->englishDayOfWeek,
                'baseline_days' => $dates,
                'baseline_sales' => $baseline === null ? null : round($baseline, 2),
                'change_pct' => $dates >= 3 && $baseline > 0 && $completed->isNotEmpty() ? round(($collected / $baseline - 1) * 100, 1) : null,
            ],
            'weather' => [
                'hours' => $hours,
                'wet_hours' => count($wet),
                'dry_hours' => count($dry),
                'wet_avg' => $this->average($wet),
                'dry_avg' => $this->average($dry),
            ],
        ];
    }

    private function tradingHours(CarbonImmutable $day, CarbonImmutable $end, CarbonImmutable $now, array $payments): array
    {
        $opening = (int) config('services.store_weather.opens_hour');
        $closing = (int) config('services.store_weather.closes_hour');
        $start = $day->addHours($opening);
        $end = $end->min($day->addHours($closing));
        if ($payments === [] || $start->gte($end)) {
            return [];
        }

        $observed = $this->weather->observedHours($day, $now);
        $hours = [];
        for ($hour = $start; $hour->lt($end); $hour = $hour->addHour()) {
            $weather = $observed[$hour->timestamp] ?? [];
            $hours[] = [
                'hour' => $hour->hour,
                'sales' => round((float) ($payments[$hour->format('Y-m-d H')]->total ?? 0), 2),
                'rain_mm' => $weather['rain_mm'] ?? null,
                'temp_c' => $weather['temp_c'] ?? null,
            ];
        }

        return $hours;
    }

    private function average(array $hours): ?float
    {
        return $hours === [] ? null : round(array_sum(array_column($hours, 'sales')) / count($hours), 2);
    }

    private function isPayday(CarbonImmutable $day): bool
    {
        return in_array($day->day, [15, 30], true);
    }
}
