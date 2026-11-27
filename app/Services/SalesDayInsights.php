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
            ->where('transacted_at', '>=', $day)->where('transacted_at', '<', $dayEnd)
            ->selectRaw('SUBSTR(transacted_at, 1, 13) AS payment_hour, SUM(amount) AS total, COUNT(*) AS payments')
            ->groupByRaw('SUBSTR(transacted_at, 1, 13)')
            ->get()
            ->groupBy(fn ($row) => substr($row->payment_hour, 0, 10));
        $todayRows = $ledger->get($day->toDateString(), collect());
        $completed = $todayRows->filter(fn ($row) => $row->payment_hour < $end->format('Y-m-d H'));
        $collected = (float) $completed->sum('total');

        $history = FinancialTransaction::query()->where('type', 'payment')
            ->where('transacted_at', '<', $now->startOfDay());
        $column = 'transacted_at';
        $sqlite = $history->getConnection()->getDriverName() === 'sqlite';
        $history->whereRaw($sqlite ? "strftime('%w', $column) = ?" : "DAYOFWEEK($column) = ?",
            [$sqlite ? (string) $day->dayOfWeek : $day->dayOfWeek + 1]);
        $hour = $sqlite ? "CAST(strftime('%H', $column) AS INTEGER)" : "HOUR($column)";
        $history->selectRaw("DATE($column) AS day, SUM(CASE WHEN $hour < ? THEN amount ELSE 0 END) AS total", [$partial ? $end->hour : 24])
            ->groupByRaw("DATE($column)")
            ->havingRaw('SUM(CASE WHEN amount > 0 THEN 1 ELSE 0 END) > 0');
        $totals = $history->get()->mapWithKeys(fn ($row) => [$row->day => round((float) $row->total, 2)])->all();
        $rank = WeekdaySalesRank::summarize($totals, $day->toDateString());
        // Keep the original comparison fields for already installed Android clients.
        $oldest = $day->subWeeks(12)->toDateString();
        $legacy = array_filter($totals, fn ($total, $d) => $d >= $oldest && $d < $day->toDateString()
            && !in_array((int) substr($d, -2), [15, 30], true), ARRAY_FILTER_USE_BOTH);
        $dates = count($legacy);
        $baseline = $dates > 0 ? array_sum($legacy) / $dates : null;
        $rank['change_pct'] = !$partial && $rank['rank'] !== null && $rank['typical_low'] > 0
            ? round(($collected / $rank['typical_low'] - 1) * 100, 1) : null;
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
            'weekday_rank' => $rank,
            'payday_distance' => WeekdaySalesRank::paydayDistance($day),
            'near_payday' => WeekdaySalesRank::nearPayday($day),
            'lower_demand_signal' => !WeekdaySalesRank::nearPayday($day) && count($wet) > 0,
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
