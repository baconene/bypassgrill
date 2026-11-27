<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ForecastSnapshots
{
    private const TOLERANCE = 10;
    public const REPLAY_START = '2026-06-01';
    public const REPLAY_END = '2026-11-28';

    public function __construct(private OperatingInsights $insights, private StoreWeather $weather) {}

    public function capture(CarbonImmutable $now): array
    {
        $asOf = $now->toDateString();
        $saved = 0;
        if (!DB::table('production_forecast_snapshots')->where('run_type', 'live')->where('forecast_as_of', $asOf)->exists()) {
            $forecast = $this->insights->forecast($now);
            $saved = DB::table('production_forecast_snapshots')->insertOrIgnore(
                $this->snapshotRows($forecast, $asOf, $now, 'live'),
            );
        }

        return ['saved' => $saved, 'evaluated' => $this->evaluate($now)];
    }

    /**
     * With $fresh, the saved replay is rebuilt from current sales, e.g. after late entries or a model change.
     * The old rows are removed in the same transaction as the new ones are written, so a failed run keeps them.
     */
    public function replay(CarbonImmutable $now, bool $fresh = false): array
    {
        $start = CarbonImmutable::parse(self::REPLAY_START, StoreWeather::TIMEZONE);
        $through = min($now->toDateString(), self::REPLAY_END);
        if (!$fresh && DB::table('production_forecast_snapshots')->where('run_type', 'replay')->where('forecast_as_of', $through)->exists()) {
            return ['saved' => 0, 'replaced' => 0, 'through' => $through];
        }

        $data = $this->insights->replayData($start, $now);
        $seen = $rows = [];
        $index = 0;
        for ($day = $start; $day->toDateString() <= $through; $day = $day->addDay()) {
            $asOf = $day->toDateString();
            while (isset($data['ledger'][$index]) && $data['ledger'][$index]->available_day < $asOf) {
                $entry = $data['ledger'][$index++];
                $seen[$entry->day] ??= ['net' => 0.0, 'receipts' => 0];
                $seen[$entry->day]['net'] += (float) $entry->net;
                $seen[$entry->day]['receipts'] += (int) $entry->receipts;
            }

            $training = [];
            foreach ($data['training'] as $date => $sample) {
                if (($seen[$date]['receipts'] ?? 0) > 0) {
                    $training[$date] = array_replace($sample, ['net_sales' => round($seen[$date]['net'], 2)]);
                }
            }
            $forecast = $this->insights->replayForecast($day->setTime(10, 0), $training);
            foreach ($this->snapshotRows($forecast, $asOf, $now, 'replay') as $row) {
                $target = $row['service_date'];
                if ($target < $now->toDateString()) {
                    $actual = ($data['sales'][$target]['receipts'] ?? 0) > 0 ? $data['sales'][$target]['net'] : null;
                    $observed = $data['training'][$target] ?? [];
                    $row += $this->score($actual, $row['estimated_net_sales'], $row['weekday_baseline'], self::TOLERANCE, $now) + [
                        'observed_rain_mm' => $observed['rain_mm'] ?? null,
                        'observed_condition' => $observed['condition'] ?? null,
                    ];
                }
                // Keep equal columns in each multirow insert, including future targets.
                $rows[] = $row + [
                    'actual_net_sales' => null, 'sales_error' => null, 'baseline_error' => null,
                    'observed_rain_mm' => null, 'observed_condition' => null, 'result' => 'pending', 'evaluated_at' => null,
                ];
            }
        }

        $replaced = 0;
        $saved = DB::transaction(function () use ($rows, $fresh, &$replaced) {
            if ($fresh) {
                $replaced = DB::table('production_forecast_snapshots')->where('run_type', 'replay')->delete();
            }
            $saved = 0;
            foreach (array_chunk($rows, 40) as $batch) {
                $saved += DB::table('production_forecast_snapshots')->insertOrIgnore($batch);
            }

            return $saved;
        });

        return ['saved' => $saved, 'replaced' => $replaced, 'through' => $through];
    }

    private function snapshotRows(array $forecast, string $asOf, CarbonImmutable $now, string $type): array
    {
        return array_map(fn ($day) => [
            'run_type' => $type,
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
    }

    private function score(?float $actual, float|string|null $estimate, float|string|null $baseline, int $tolerance, CarbonImmutable $now): array
    {
        $error = $actual === null || $estimate === null ? null : round($actual - (float) $estimate, 2);

        return [
            'actual_net_sales' => $actual,
            'sales_error' => $error,
            'baseline_error' => $actual !== null && $baseline !== null ? round($actual - (float) $baseline, 2) : null,
            'result' => match (true) {
                $actual === null => 'no_sales_record',
                $error === null => 'no_estimate',
                abs($error) <= round(abs((float) $estimate) * $tolerance / 100, 2) => 'hit',
                default => 'miss',
            },
            'evaluated_at' => $now,
        ];
    }

    public function predictions(string $type, ?string $asOf): array
    {
        $query = DB::table('production_forecast_snapshots as snapshots')->where('snapshots.run_type', $type);
        if ($asOf !== null) {
            $query->where('snapshots.forecast_as_of', $asOf);
        } else {
            $query->where('snapshots.result', '!=', 'pending')
                ->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('production_forecast_snapshots as newer')
                    ->whereColumn('newer.run_type', 'snapshots.run_type')
                    ->whereColumn('newer.service_date', 'snapshots.service_date')
                    ->whereColumn('newer.forecast_as_of', '>', 'snapshots.forecast_as_of'));
        }
        $stats = (clone $query)->selectRaw("
            SUM(CASE WHEN result = 'hit' THEN 1 ELSE 0 END) as hits,
            SUM(CASE WHEN result = 'miss' THEN 1 ELSE 0 END) as misses,
            SUM(CASE WHEN result = 'no_estimate' THEN 1 ELSE 0 END) as no_estimate,
            AVG(ABS(sales_error)) as mean_error,
            AVG(CASE WHEN sales_error IS NOT NULL THEN ABS(baseline_error) END) as baseline_mean_error
        ")->first();
        $runs = DB::table('production_forecast_snapshots')->where('run_type', $type);
        $results = $query->orderBy('snapshots.service_date', $asOf === null ? 'desc' : 'asc')->paginate(15)
            ->through(fn ($row) => $this->formatPrediction($row));

        return [
            'hits' => (int) $stats->hits,
            'misses' => (int) $stats->misses,
            'no_estimate' => (int) $stats->no_estimate,
            'mean_error' => $stats->mean_error === null ? null : round((float) $stats->mean_error, 2),
            'baseline_mean_error' => $stats->baseline_mean_error === null ? null : round((float) $stats->baseline_mean_error, 2),
            'first_run' => (clone $runs)->min('forecast_as_of'),
            'last_run' => (clone $runs)->max('forecast_as_of'),
            'run_days' => (clone $runs)->distinct()->count('forecast_as_of'),
            'tolerance_pct' => self::TOLERANCE,
            'predictions' => $results,
        ];
    }

    private function formatPrediction(object $row): array
    {
        return [
            'date' => $row->service_date,
            'as_of' => $row->forecast_as_of,
            'estimate' => $row->estimated_net_sales === null ? null : (float) $row->estimated_net_sales,
            'actual' => $row->actual_net_sales === null ? null : (float) $row->actual_net_sales,
            'error' => $row->sales_error === null ? null : (float) $row->sales_error,
            'baseline_error' => $row->baseline_error === null ? null : (float) $row->baseline_error,
            'result' => $row->result,
            'sample_days' => (int) $row->sample_days,
            'payday_distance' => (int) $row->payday_distance,
            'weather_source' => $row->weather_source,
            'rain_chance' => $row->rain_chance === null ? null : (float) $row->rain_chance,
            'observed_rain_mm' => $row->observed_rain_mm === null ? null : (float) $row->observed_rain_mm,
        ];
    }

    public function summary(): array
    {
        // Report one score per service date, using the last forecast saved before that shift.
        $series = DB::table('production_forecast_snapshots as snapshots')
            ->select('snapshots.*')->where('snapshots.run_type', 'live')
            ->whereIn('snapshots.result', ['hit', 'miss', 'no_sales_record', 'no_estimate'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('production_forecast_snapshots as newer')
                ->whereColumn('newer.run_type', 'snapshots.run_type')
                ->whereColumn('newer.service_date', 'snapshots.service_date')
                ->whereColumn('newer.forecast_as_of', '>', 'snapshots.forecast_as_of'))
            ->orderByDesc('snapshots.service_date')->limit(30)->get();
        $scored = $series->whereIn('result', ['hit', 'miss']);

        return [
            'last_captured_at' => DB::table('production_forecast_snapshots')->where('run_type', 'live')->max('captured_at'),
            'tolerance_pct' => self::TOLERANCE,
            'hits' => $scored->where('result', 'hit')->count(),
            'scored' => $scored->count(),
            'days' => $series->take(10)->map(fn ($row) => $this->formatPrediction($row))->values()->all(),
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
                DB::table('production_forecast_snapshots')->where('id', $row->id)->update($this->score(
                    $actual, $row->estimated_net_sales, $row->weekday_baseline, $row->tolerance_pct, $now,
                ) + [
                    'observed_rain_mm' => $weather[$row->service_date]['rain_mm'] ?? $row->observed_rain_mm,
                    'observed_condition' => $weather[$row->service_date]['condition'] ?? $row->observed_condition,
                ]);
                $count++;
            }
        });

        return $count;
    }
}
