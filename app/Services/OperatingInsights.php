<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OperatingInsights
{
    public function __construct(private StoreWeather $weather, private ProductionDemandModel $model) {}

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
        $salesHistory = $this->productionSales($sales);
        $forecast = $this->weather->serviceWeather($nextDays, $now, forecast: true);
        $weatherHistory = $this->weather->trainingWeather(array_reverse(array_keys($salesHistory)), $now);
        $training = [];
        foreach ($salesHistory as $date => $netSales) {
            $day = $this->date($date);
            $training[$date] = [
                'date' => $date, 'weekday' => $day->dayOfWeekIso, 'net_sales' => $netSales,
                'near_payday' => WeekdaySalesRank::nearPayday($day),
                'rainy' => $weatherHistory[$date]['rainy'],
                'condition' => $weatherHistory[$date]['condition'],
                'rain_mm' => $weatherHistory[$date]['rain_mm'],
                'rainy_hours' => $weatherHistory[$date]['rainy_hours'],
            ];
        }
        $validation = $this->model->validate($training);
        $unitHistory = $items->map(fn ($rows) => (int) $rows->sum('units'))->all();

        $result = [];
        foreach ($nextDays as $date) {
            $target = $this->date($date);
            $payday = $this->payday($target);
            $weather = $forecast[$date];
            $nearPayday = WeekdaySalesRank::nearPayday($target);
            $weekday = array_filter($training, fn ($day) => $day['weekday'] === $target->dayOfWeekIso);
            $weekdaySales = array_column($weekday, 'net_sales', 'date');
            $rank = WeekdaySalesRank::summarize($weekdaySales, $date);
            $weights = $this->model->weights($weekday, ['near_payday' => $nearPayday, 'rainy' => $weather['rainy']], $validation['use_context']);
            $baseline = array_fill_keys(array_keys($weekday), 1);
            $stockWeights = array_intersect_key($weights, $unitHistory);
            $baselineUnits = $this->model->estimate($unitHistory, array_intersect_key($baseline, $unitHistory));
            $units = count($stockWeights) >= 2 ? $this->model->estimate($unitHistory, $stockWeights) : null;
            $ordered = array_intersect_key($orders, $weights);
            $projectedOrders = count($ordered) >= 2 ? $this->model->estimate($ordered, $weights) : null;
            $baselineOrders = count($ordered) >= 2 ? $this->model->estimate($ordered, $baseline) : null;
            $products = [];
            if ($units !== null) {
                $lines = collect(array_keys($stockWeights))->flatMap(fn ($d) => $items[$d]->map(fn ($item) => [$d, $item]))->groupBy(fn ($line) => $line[1]->id);
                foreach ($lines as $group) {
                    $byDay = $group->mapWithKeys(fn ($line) => [$line[0] => (int) $line[1]->units])->all();
                    $firstSale = min(array_keys($byDay));
                    $productWeights = array_filter($stockWeights, fn ($d) => $d >= $firstSale, ARRAY_FILTER_USE_KEY);
                    $daily = array_replace(array_fill_keys(array_keys($productWeights), 0), $byDay);
                    $suggested = (int) ceil($this->model->estimate($daily, $productWeights));
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

            $result[] = [
                'date' => $date, 'payday' => $payday, 'rain_chance' => $weather['rain_chance'],
                'sample_days' => count($weekday), 'units' => $units,
                'weekday_units' => $baselineUnits,
                'weekday_rank' => $rank,
                'payday_distance' => WeekdaySalesRank::paydayDistance($target),
                'near_payday' => $nearPayday,
                'orders' => $projectedOrders,
                'typical_orders' => $baselineOrders,
                'net_sales' => count($weekday) >= 2 ? $this->model->estimate($weekdaySales, $weights) : null,
                'net_sales_samples' => array_values($weekday),
                'products' => $products,
            ];
        }

        return ['days' => $result, 'model' => $validation + [
            'training_days' => count($training),
            'weather_days' => count(array_filter($training, fn ($day) => $day['rainy'] !== null)),
        ]];
    }

    public function warmForecastWeather(CarbonImmutable $now): void
    {
        $history = $this->productionSales($this->sales($now->startOfDay()));
        $this->weather->trainingWeather(array_reverse(array_keys($history)), $now);
    }

    private function productionSales(array $sales): array
    {
        $history = [];
        foreach ($sales as $date => $day) {
            if ($day['receipts'] > 0 && $this->date($date)->dayOfWeekIso >= 5) {
                $history[$date] = $day['net'];
            }
        }
        ksort($history);

        return $history;
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
