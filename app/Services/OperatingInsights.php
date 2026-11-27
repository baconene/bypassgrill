<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OperatingInsights
{
    public function __construct(private StoreWeather $weather) {}

    public function forecast(CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $nextDays = [];
        for ($day = $today; count($nextDays) < 3; $day = $day->addDay()) {
            if ($day->dayOfWeekIso >= 5 && $day->addHours((int) config('services.store_weather.opens_hour'))->gt($now)) {
                $nextDays[] = $day->toDateString();
            }
        }

        $sales = $this->sales($today);
        $items = DB::table('order_items as i')->join('orders as o', 'i.order_id', '=', 'o.id')
            ->join('products as p', 'i.product_id', '=', 'p.id')
            ->where('o.created_at', '<', $today)
            ->where('o.payment_status', 'paid')->where('o.status', '!=', 'cancelled')
            ->where('i.status', '!=', 'cancelled')->where('p.is_active', true)
            ->selectRaw('DATE(o.created_at) as day, p.id, p.name, SUM(i.quantity) as units')
            ->groupByRaw('DATE(o.created_at), p.id, p.name')->get()->groupBy('day');
        $orders = DB::table('orders')->where('created_at', '<', $today)
            ->where('payment_status', 'paid')->where('status', '!=', 'cancelled')
            ->selectRaw('DATE(created_at) as day, COUNT(*) as orders')->groupByRaw('DATE(created_at)')
            ->pluck('orders', 'day')->all();
        $history = array_values(array_filter($items->keys()->all(), fn ($date) => $this->date($date)->dayOfWeekIso >= 5));
        rsort($history);
        $forecast = $this->weather->serviceWeather($nextDays, $now, forecast: true);
        $weatherHistory = $this->weather->serviceWeather(array_slice($history, 0, 12), $now);
        $unitHistory = $items->map(fn ($rows) => (int) $rows->sum('units'))->all();
        $salesHistory = array_map(fn ($day) => $day['net'], array_filter($sales, fn ($day) => $day['receipts'] > 0));


        $result = [];
        foreach ($nextDays as $date) {
            $target = $this->date($date);
            $payday = $this->payday($target);
            $weather = $forecast[$date];
            $weekday = array_values(array_filter($history, fn ($d) => $this->date($d)->dayOfWeekIso === $target->dayOfWeekIso));
            $nearPayday = WeekdaySalesRank::nearPayday($target);
            $lowerDemand = !$nearPayday && $weather['rainy'] === true;
            $weekdaySales = array_intersect_key($salesHistory, array_flip($weekday));
            $typical = WeekdaySalesRank::typicalDates($weekdaySales);
            $rank = WeekdaySalesRank::summarize($weekdaySales, $date);
            $ranked = array_keys($weekdaySales);
            usort($ranked, fn ($a, $b) => $weekdaySales[$a] <=> $weekdaySales[$b] ?: strcmp($b, $a));
            $matches = $typical;
            $basis = 'typical_range';

            if ($lowerDemand) {
                $lowerHalf = array_slice($ranked, 0, max(2, (int) ceil(count($ranked) / 2)));
                $rainPeers = array_values(array_filter($lowerHalf, fn ($d) =>
                    ($weatherHistory[$d]['rainy'] ?? null) === true && !WeekdaySalesRank::nearPayday($this->date($d))));
                $matches = count($rainPeers) >= 2 ? $rainPeers : $lowerHalf;
                $basis = count($rainPeers) >= 2 ? 'rain_away_matched' : 'lower_range';
            } elseif (count($matches) < 2) {
                $matches = $ranked;
                $basis = 'weekday_fallback';
            }

            $baselineUnits = $this->median(array_map(fn ($d) => $unitHistory[$d], $typical));
            $units = count($matches) >= 2 ? $this->median(array_map(fn ($d) => $unitHistory[$d], $matches)) : null;
            $products = [];
            if ($units !== null) {
                $lines = collect($matches)->flatMap(fn ($d) => $items[$d]->map(fn ($item) => [$d, $item]))->groupBy(fn ($line) => $line[1]->id);
                foreach ($lines as $group) {
                    $byDay = $group->mapWithKeys(fn ($line) => [$line[0] => (int) $line[1]->units]);
                    $daily = array_map(fn ($d) => $byDay->get($d, 0), $matches);
                    $suggested = (int) ceil($this->median($daily));
                    if ($suggested > 0) {
                        sort($daily);
                        $products[] = [
                            'id' => (int) $group->first()[1]->id,
                            'name' => $group->first()[1]->name,
                            'suggested' => $suggested,
                            'busy' => max($suggested, (int) ceil($daily[(int) ceil(count($daily) * 0.75) - 1])),
                        ];
                    }
                }
                usort($products, fn ($a, $b) => $b['suggested'] <=> $a['suggested'] ?: strcmp($a['name'], $b['name']));
            }

            $matchedSales = array_intersect_key($weekdaySales, array_flip($matches));
            ksort($matchedSales, SORT_STRING);
            $salesSamples = [];
            foreach ($matchedSales as $sampleDate => $netSales) {
                $salesSamples[] = ['date' => $sampleDate, 'net_sales' => $netSales];
            }

            $result[] = [
                'date' => $date, 'payday' => $payday, 'rain_chance' => $weather['rain_chance'],
                'basis' => $basis, 'sample_days' => count($matches), 'units' => $units,
                'weekday_units' => $baselineUnits,
                'weekday_rank' => $rank,
                'payday_distance' => WeekdaySalesRank::paydayDistance($target),
                'near_payday' => $nearPayday,
                'lower_demand_signal' => $lowerDemand,
                'orders' => $units === null ? null : $this->median(array_map(fn ($d) => (int) ($orders[$d] ?? 0), $matches)),
                'typical_orders' => $this->median(array_map(fn ($d) => (int) ($orders[$d] ?? 0), $typical)),
                'net_sales' => $units === null ? null : $this->median($matchedSales),
                'net_sales_samples' => $salesSamples,
                'products' => $products,
            ];
        }

        return ['days' => $result];
    }

    public function review(CarbonImmutable $date, CarbonImmutable $now): array
    {
        $selected = $date->toDateString();
        $lastWeek = $date->subWeek()->toDateString();
        $sales = $this->sales($now->startOfDay());
        $sameWeekday = array_filter($sales, fn ($row, $d) => $row['receipts'] > 0 &&
            $this->date($d)->dayOfWeekIso === $date->dayOfWeekIso, ARRAY_FILTER_USE_BOTH);
        $rank = WeekdaySalesRank::summarize(array_map(fn ($row) => $row['net'], $sameWeekday), $selected);
        $prior = array_values(array_filter(array_keys($sameWeekday), fn ($d) => $d < $selected));
        rsort($prior);
        $weather = $this->weather->serviceWeather(array_values(array_unique([$selected, $lastWeek, ...array_slice($prior, 0, 6)])), $now);
        $staffing = DB::table('service_day_staffing')->whereIn('service_date', array_keys($weather))
            ->get(['service_date', 'headcount', 'staff_hours'])->keyBy('service_date');
        $payday = $this->payday($date);
        $rainy = $weather[$selected]['rainy'];
        $payPeers = array_values(array_filter($prior, fn ($d) => WeekdaySalesRank::nearPayday($this->date($d)) === WeekdaySalesRank::nearPayday($date)));
        $rainPeers = $rainy === null ? [] : array_values(array_filter($payPeers,
            fn ($d) => ($weather[$d]['rainy'] ?? null) === $rainy));
        $matched = $this->summarize($rainPeers, $sales);
        $payMatched = $this->summarize($payPeers, $sales);
        $differentPayday = array_values(array_diff($prior, $payPeers));
        $differentWeather = $rainy === null ? [] : array_values(array_filter($payPeers,
            fn ($d) => ($weather[$d]['rainy'] ?? null) === !$rainy));
        $net = $sales[$selected]['net'] ?? 0;
        $hasReceipts = ($sales[$selected]['receipts'] ?? 0) > 0;
        $staff = $staffing->get($selected);
        $staffPeers = array_values(array_filter($rainPeers, fn ($d) => $staffing->has($d)));
        $staffCompare = array_map(fn ($d) => $sales[$d]['net'] / (float) $staffing[$d]->staff_hours, $staffPeers);
        $previous = $sales[$lastWeek] ?? null;

        return [
            'date' => $selected, 'weekday' => $date->englishDayOfWeek, 'net_sales' => $net,
            'has_receipts' => $hasReceipts, 'payday' => $payday, 'weather' => $weather[$selected],
            'previous_week' => [
                'date' => $lastWeek, 'net_sales' => $previous['net'] ?? null,
                'change_pct' => $hasReceipts ? $this->change($net, $previous['net'] ?? null) : null,
                'payday' => $this->payday($this->date($lastWeek)), 'weather' => $weather[$lastWeek],
                'headcount' => $staffing->has($lastWeek) ? (int) $staffing[$lastWeek]->headcount : null,
                'staff_hours' => $staffing->has($lastWeek) ? (float) $staffing[$lastWeek]->staff_hours : null,
            ],
            'weekday_rank' => $rank,
            'near_payday' => WeekdaySalesRank::nearPayday($date),
            'payday_distance' => WeekdaySalesRank::paydayDistance($date),
            'lower_demand_signal' => !WeekdaySalesRank::nearPayday($date) && $rainy === true,
            'typical' => ['days' => $rank['days'], 'net' => $rank['typical_low'],
                'change_pct' => $hasReceipts && $rank['days'] >= 2 ? $this->change($net, $rank['typical_low']) : null],
            'same_payday_status' => $payMatched,
            'same_payday_and_weather' => $matched,
            'different_payday_status' => $this->summarize($differentPayday, $sales),
            'different_weather_same_payday' => $this->summarize($differentWeather, $sales),
            'staffing' => [
                'headcount' => $staff ? (int) $staff->headcount : null,
                'staff_hours' => $staff ? (float) $staff->staff_hours : null,
                'net_per_hour' => $staff && $hasReceipts ? round($net / (float) $staff->staff_hours, 2) : null,
                'matched_days' => count($staffCompare),
                'typical_per_hour' => count($staffCompare) >= 2 ? $this->median($staffCompare) : null,
                'fewer_staff' => $this->summarize($staff ? array_values(array_filter($staffPeers,
                    fn ($d) => $staffing[$d]->headcount < $staff->headcount)) : [], $sales),
                'more_staff' => $this->summarize($staff ? array_values(array_filter($staffPeers,
                    fn ($d) => $staffing[$d]->headcount > $staff->headcount)) : [], $sales),
                'typical_headcount' => count($staffPeers) >= 2 ? $this->median(array_map(fn ($d) => (int) $staffing[$d]->headcount, $staffPeers)) : null,
                'typical_staff_hours' => count($staffPeers) >= 2 ? $this->median(array_map(fn ($d) => (float) $staffing[$d]->staff_hours, $staffPeers)) : null,
            ],
        ];
    }

    private function sales(CarbonImmutable $until): array
    {
        return DB::table('financial_transactions')->where('type', 'payment')
            ->where('transacted_at', '<', $until)
            ->selectRaw('DATE(transacted_at) as day, SUM(amount) as net, SUM(CASE WHEN amount > 0 THEN 1 ELSE 0 END) as receipts')
            ->groupByRaw('DATE(transacted_at)')->get()
            ->mapWithKeys(fn ($row) => [$row->day => ['net' => round((float) $row->net, 2), 'receipts' => (int) $row->receipts]])->all();
    }

    private function summarize(array $dates, array $sales): array
    {
        return ['days' => count($dates), 'net' => $this->median(array_map(fn ($date) => $sales[$date]['net'], $dates))];
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, StoreWeather::TIMEZONE);
    }

    private function payday(CarbonImmutable $date): bool
    {
        return in_array($date->day, [15, 30], true);
    }

    private function change(float $value, ?float $baseline): ?float
    {
        return $baseline > 0 ? round(($value / $baseline - 1) * 100, 1) : null;
    }

    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return round(count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2, 2);
    }
}
