<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ForecastSnapshots
{
    private const TOLERANCE = 10;

    public function __construct(private OperatingInsights $insights, private StoreWeather $weather) {}

    public function capture(CarbonImmutable $now): array
    {
        $asOf = $now->toDateString();
        $saved = 0;
        if (!DB::table('production_forecast_snapshots')->where('forecast_as_of', $asOf)->exists()) {
            $forecast = $this->insights->forecast($now);
            $rows = array_map(fn ($day) => [
                'forecast_as_of' => $asOf,
                'service_date' => $day['date'],
                'captured_at' => $now,
                'estimated_net_sales' => $day['net_sales'],
                'weekday_baseline' => $day['weekday_baseline'],
                'model_version' => 'weekday-context-v1',
                'used_context' => $forecast['model']['use_context'],
                'sample_days' => $day['sample_days'],
                'payday_distance' => $day['payday_distance'],
                'weather_source' => $day['weather_source'],
                'forecast_condition' => $day['condition'],
                'rain_chance' => $day['rain_chance'],
                'tolerance_pct' => self::TOLERANCE,
            ], $forecast['month']);
            $saved = DB::table('production_forecast_snapshots')->insertOrIgnore($rows);
        }

        return ['saved' => $saved, 'evaluated' => $this->evaluate($now)];
    }

    public function summary(): array
    {
        // Report one score per service date, using the last forecast saved before that shift.
        $series = DB::table('production_forecast_snapshots as snapshots')
            ->select('snapshots.*')
            ->whereIn('snapshots.result', ['hit', 'miss', 'no_sales_record', 'no_estimate'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('production_forecast_snapshots as newer')
                ->whereColumn('newer.service_date', 'snapshots.service_date')
                ->whereColumn('newer.forecast_as_of', '>', 'snapshots.forecast_as_of'))
            ->orderByDesc('snapshots.service_date')->limit(30)->get();
        $scored = $series->whereIn('result', ['hit', 'miss']);

        return [
            'last_captured_at' => DB::table('production_forecast_snapshots')->max('captured_at'),
            'tolerance_pct' => self::TOLERANCE,
            'hits' => $scored->where('result', 'hit')->count(),
            'scored' => $scored->count(),
            'days' => $series->take(10)->map(fn ($row) => [
                'date' => $row->service_date,
                'as_of' => $row->forecast_as_of,
                'estimate' => $row->estimated_net_sales === null ? null : (float) $row->estimated_net_sales,
                'actual' => $row->actual_net_sales === null ? null : (float) $row->actual_net_sales,
                'error' => $row->sales_error === null ? null : (float) $row->sales_error,
                'baseline_error' => $row->baseline_error === null ? null : (float) $row->baseline_error,
                'result' => $row->result,
                'payday_distance' => (int) $row->payday_distance,
                'weather_source' => $row->weather_source,
                'rain_chance' => $row->rain_chance === null ? null : (float) $row->rain_chance,
                'observed_rain_mm' => $row->observed_rain_mm === null ? null : (float) $row->observed_rain_mm,
            ])->values()->all(),
        ];
    }

    private function evaluate(CarbonImmutable $now): int
    {
        $pending = DB::table('production_forecast_snapshots')->where('service_date', '<', $now->toDateString())
            ->where(fn ($query) => $query->where('result', 'pending')
                ->orWhere('service_date', '>=', $now->subDays(30)->toDateString()));
        $dates = (clone $pending)->distinct()->orderBy('service_date')->pluck('service_date')->all();
        if ($dates === []) {
            return 0;
        }

        $sales = DB::table('financial_transactions')->where('type', 'payment')
            ->where('transacted_at', '>=', $dates[0])
            ->where('transacted_at', '<', $now->startOfDay())
            ->selectRaw('DATE(transacted_at) as day, SUM(amount) as net, SUM(CASE WHEN amount > 0 THEN 1 ELSE 0 END) as receipts')
            ->groupByRaw('DATE(transacted_at)')->get()->keyBy('day');
        $weather = $this->weather->serviceWeather(array_reverse($dates), $now, fetchLimit: 3);
        $count = 0;
        $pending->chunkById(400, function ($rows) use (&$count, $sales, $weather, $now) {
            foreach ($rows as $row) {
                $day = $sales->get($row->service_date);
                $actual = ($day->receipts ?? 0) > 0 ? (float) $day->net : null;
                $estimate = $row->estimated_net_sales === null ? null : (float) $row->estimated_net_sales;
                $error = $actual === null || $estimate === null ? null : round($actual - $estimate, 2);
                $result = match (true) {
                    $actual === null => 'no_sales_record',
                    $error === null => 'no_estimate',
                    abs($error) <= round(abs($estimate) * $row->tolerance_pct / 100, 2) => 'hit',
                    default => 'miss',
                };
                DB::table('production_forecast_snapshots')->where('id', $row->id)->update([
                    'actual_net_sales' => $actual,
                    'sales_error' => $error,
                    'baseline_error' => $actual !== null && $row->weekday_baseline !== null ? round($actual - (float) $row->weekday_baseline, 2) : null,
                    'observed_rain_mm' => $weather[$row->service_date]['rain_mm'] ?? $row->observed_rain_mm,
                    'observed_condition' => $weather[$row->service_date]['condition'] ?? $row->observed_condition,
                    'result' => $result,
                    'evaluated_at' => $now,
                ]);
                $count++;
            }
        });

        return $count;
    }
}
