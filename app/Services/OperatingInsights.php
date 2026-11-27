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

        $sales = $this->sales($today->subWeeks(12), $today);
        $items = DB::table('order_items as i')->join('orders as o', 'i.order_id', '=', 'o.id')
            ->join('products as p', 'i.product_id', '=', 'p.id')
            ->where('o.created_at', '>=', $today->subWeeks(12))->where('o.created_at', '<', $today)
            ->where('o.payment_status', 'paid')->where('o.status', '!=', 'cancelled')
            ->where('i.status', '!=', 'cancelled')->where('p.is_active', true)
            ->selectRaw('DATE(o.created_at) as day, p.id, p.name, SUM(i.quantity) as units')
            ->groupByRaw('DATE(o.created_at), p.id, p.name')->get()->groupBy('day');
        $history = array_values(array_filter($items->keys()->all(), fn ($date) => $this->date($date)->dayOfWeekIso >= 5));
        rsort($history);
        $forecast = $this->weather->serviceWeather($nextDays, $now, forecast: true);
        $weatherHistory = $this->weather->serviceWeather(array_slice($history, 0, 12), $now);
        $unitHistory = $items->map(fn ($rows) => (int) $rows->sum('units'))->all();
        $salesHistory = array_map(fn ($day) => $day['net'], $sales);
        $paydayUnits = $this->paydayLift($history, $unitHistory);
        $paydaySales = $this->paydayLift($history, $salesHistory);

        $result = [];
        foreach ($nextDays as $date) {
            $target = $this->date($date);
            $payday = $this->payday($target);
            $weather = $forecast[$date];
            $weekday = array_values(array_filter($history, fn ($d) => $this->date($d)->dayOfWeekIso === $target->dayOfWeekIso));
            $payPeers = array_values(array_filter($weekday, fn ($d) => $this->payday($this->date($d)) === $payday));
            $weatherPeers = $weather['rainy'] === null ? [] : array_values(array_filter($payPeers,
                fn ($d) => ($weatherHistory[$d]['rainy'] ?? null) === $weather['rainy']));
            $matches = count($weatherPeers) >= 2 ? $weatherPeers : (count($payPeers) >= 3 ? $payPeers : $weekday);
            $basis = count($weatherPeers) >= 2 ? 'weekday_payday_weather' : (count($payPeers) >= 3 ? 'weekday_payday' : 'weekday');
            $usePaydayLift = $payday && $basis === 'weekday' && $paydayUnits !== null;
            $regularWeekday = array_values(array_filter($weekday, fn ($d) => !$this->payday($this->date($d))));
            if ($usePaydayLift && count($regularWeekday) >= 2) {
                $matches = $regularWeekday;
                $basis = 'weekday_payday_adjusted';
            }
            $unitFactor = $basis === 'weekday_payday_adjusted' ? $paydayUnits : 1;
            $salesFactor = $basis === 'weekday_payday_adjusted' ? $paydaySales : 1;
            $baselineUnits = $this->median(array_map(fn ($d) => $unitHistory[$d], $weekday));
            $units = count($matches) >= 2 ? $this->median(array_map(fn ($d) => $unitHistory[$d], $matches)) * $unitFactor : null;
            $products = [];
            if ($units !== null) {
                $lines = collect($matches)->flatMap(fn ($d) => $items[$d]->map(fn ($item) => [$d, $item]))->groupBy(fn ($line) => $line[1]->id);
                foreach ($lines as $group) {
                    $byDay = $group->mapWithKeys(fn ($line) => [$line[0] => (int) $line[1]->units]);
                    $daily = array_map(fn ($d) => $byDay->get($d, 0), $matches);
                    $typical = (int) ceil($this->median($daily) * $unitFactor);
                    if ($typical > 0) {
                        sort($daily);
                        $products[] = [
                            'id' => (int) $group->first()[1]->id,
                            'name' => $group->first()[1]->name,
                            'suggested' => $typical,
                            'busy' => max($typical, (int) ceil($daily[(int) ceil(count($daily) * 0.75) - 1] * $unitFactor)),
                        ];
                    }
                }
                usort($products, fn ($a, $b) => $b['suggested'] <=> $a['suggested'] ?: strcmp($a['name'], $b['name']));
            }

            $result[] = [
                'date' => $date, 'payday' => $payday, 'rain_chance' => $weather['rain_chance'],
                'basis' => $basis, 'sample_days' => count($matches), 'units' => $units,
                'weekday_units' => $baselineUnits,
                'net_sales' => $units === null || $salesFactor === null ? null : round($this->median(array_map(fn ($d) => $sales[$d]['net'] ?? 0, $matches)) * $salesFactor, 2),
                'products' => $products,
            ];
        }

        return ['days' => $result];
    }

    public function review(CarbonImmutable $date, CarbonImmutable $now): array
    {
        $selected = $date->toDateString();
        $lastWeek = $date->subWeek()->toDateString();
        $sales = $this->sales($date->subWeeks(12), $date->addDay());
        $prior = [];
        for ($i = 1; $i <= 12; $i++) {
            $d = $date->subWeeks($i)->toDateString();
            if (($sales[$d]['receipts'] ?? 0) > 0) {
                $prior[] = $d;
            }
        }
        $weather = $this->weather->serviceWeather(array_values(array_unique([$selected, $lastWeek, ...array_slice($prior, 0, 6)])), $now);
        $staffing = DB::table('service_day_staffing')->whereBetween('service_date', [$date->subWeeks(12)->toDateString(), $selected])
            ->get(['service_date', 'headcount', 'staff_hours'])->keyBy('service_date');
        $payday = $this->payday($date);
        $rainy = $weather[$selected]['rainy'];
        $payPeers = array_values(array_filter($prior, fn ($d) => $this->payday($this->date($d)) === $payday));
        $rainPeers = $rainy === null ? [] : array_values(array_filter($payPeers,
            fn ($d) => ($weather[$d]['rainy'] ?? null) === $rainy));
        $priorNet = $this->summarize($prior, $sales);
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
            'typical' => $priorNet + ['change_pct' => $hasReceipts && $priorNet['days'] >= 3 ? $this->change($net, $priorNet['net']) : null],
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

    private function paydayLift(array $history, array $values): ?float
    {
        $regular = [];
        foreach ($history as $date) {
            $day = $this->date($date);
            if (isset($values[$date]) && !$this->payday($day)) {
                $regular[$day->dayOfWeekIso][] = $values[$date];
            }
        }

        $ratios = [];
        foreach ($history as $date) {
            $day = $this->date($date);
            $peerValues = $regular[$day->dayOfWeekIso] ?? [];
            if (isset($values[$date]) && $this->payday($day) && count($peerValues) >= 2) {
                $median = $this->median($peerValues);
                if ($median > 0) {
                    $ratios[] = max(0, $values[$date]) / $median;
                }
            }
        }

        return count($ratios) >= 3 ? $this->median($ratios) : null;
    }

    private function sales(CarbonImmutable $from, CarbonImmutable $until): array
    {
        return DB::table('financial_transactions')->where('type', 'payment')
            ->where('transacted_at', '>=', $from)->where('transacted_at', '<', $until)
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
