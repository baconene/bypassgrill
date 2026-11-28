<?php

namespace App\Services;

class ProductionDemandModel
{
    // Pulls a learned uplift toward "no change" until there are enough examples of that kind of day
    private const UPLIFT_SHRINK = 4;

    private const BIAS_SHRINK = 6;

    // A tuned model must beat the plain weekday average by this much in the back-test, or the average is used.
    // With a few dozen test days, smaller wins are mostly luck.
    private const MIN_IMPROVEMENT = 0.03;

    // Among settings within this margin of the best, the simplest wins
    private const TIE_MARGIN = 0.01;

    private const KINDS = [
        'none' => [],
        'peaks' => ['payday_long_weekend', 'long_weekend', 'holiday'],
        'all' => HolidayCalendar::SPECIAL_DAYS,
    ];

    /**
     * Every combination the tuner tries. half_life: days until a past week counts half (null = all weeks equal);
     * kinds: which special days get a learned uplift; cap: limit extreme days; bias: correct running under/over;
     * blend: share of the tuned estimate, the rest is the weekday average; rain: weight for matching weather.
     */
    public static function candidates(): array
    {
        $candidates = [];
        foreach ([null, 56, 28] as $halfLife) {
            foreach (array_keys(self::KINDS) as $kinds) {
                foreach ([false, true] as $cap) {
                    foreach ([false, true] as $bias) {
                        foreach ([0.5, 1.0] as $blend) {
                            foreach ([1, 2] as $rain) {
                                $candidates[] = compact('halfLife', 'kinds', 'cap', 'bias', 'blend', 'rain');
                            }
                        }
                    }
                }
            }
        }

        return array_map(fn ($c) => [
            'half_life' => $c['halfLife'], 'kinds' => $c['kinds'], 'cap' => $c['cap'],
            'bias' => $c['bias'], 'blend' => $c['blend'], 'rain' => $c['rain'],
        ], $candidates);
    }

    /**
     * Tunes the forecast on this history. Walks forward through the days, predicting each from earlier days
     * only, with the plain weekday average, the older payday/rain matching ("context"), and every tuned
     * setting. The lowest recent error wins, provided it clearly beats the weekday average.
     */
    public function validate(array $days): array
    {
        $candidates = self::candidates();
        $history = $all = [];
        $baseErrors = $contextErrors = [];
        $errors = $residuals = array_fill(0, count($candidates), []);

        foreach ($days as $day) {
            $weekday = $day['weekday'];
            $past = $history[$weekday] ?? [];
            if (count($past) >= 3) {
                $sales = array_column($past, 'net_sales', 'date');
                $baseline = $this->estimate($sales, array_fill_keys(array_keys($past), 1));
                $baseErrors[] = abs($day['net_sales'] - $baseline);
                $contextErrors[] = abs($day['net_sales'] - $this->estimate($sales, $this->weights($past, $day, true)));
                $uplifts = array_map(fn ($kinds) => $kinds === [] ? [] : $this->uplifts($all, $kinds), self::KINDS);

                $prepared = array_map(fn ($u) => $this->prepare($past, $day['date'], $u), $uplifts);
                $bases = [];
                foreach ($candidates as $i => $config) {
                    // Settings that differ only in blend or bias share the same base estimate
                    $key = $config['kinds'].'|'.$config['half_life'].'|'.(int) $config['cap'].'|'.$config['rain'];
                    $bases[$key] ??= $this->combine($prepared[$config['kinds']], $config, $day['rainy']);
                    $special = $day['special'] ?? null;
                    $uplift = $special === null ? 1.0 : ($uplifts[$config['kinds']][$special]['uplift'] ?? 1.0);
                    $raw = $bases[$key] === null ? null : round($bases[$key] * $uplift, 2);
                    $tuned = $raw * ($config['bias'] ? $this->bias($residuals[$i]) : 1.0);
                    $errors[$i][] = abs($day['net_sales'] - ($config['blend'] * $tuned + (1 - $config['blend']) * $baseline));
                    if ($raw > 0) {
                        $residuals[$i][] = $day['net_sales'] / $raw;
                    }
                }
            }
            $history[$weekday][$day['date']] = $day;
            $all[$day['date']] = $day;
        }

        $mae = fn (array $list) => $list === [] ? null : array_sum($recent = array_slice($list, -28)) / count($recent);
        $weekdayMae = $mae($baseErrors);
        $contextMae = $mae($contextErrors);
        $tested = count(array_slice($baseErrors, -28));

        $best = null;
        $bestMae = INF;
        if ($tested >= 6) {
            $scores = array_map($mae, $errors);
            $lowest = min($scores);
            foreach ($candidates as $i => $config) {
                if ($scores[$i] <= $lowest * (1 + self::TIE_MARGIN)
                    && ($best === null || $this->complexity($config) < $this->complexity($candidates[$best]))) {
                    $best = $i;
                }
            }
            $bestMae = $scores[$best];
        }

        $mode = 'baseline';
        if ($tested >= 6) {
            $threshold = $weekdayMae * (1 - self::MIN_IMPROVEMENT);
            if ($bestMae < $threshold && $bestMae <= $contextMae) {
                $mode = 'factors';
            } elseif ($contextMae < $threshold) {
                $mode = 'context';
            }
        }

        return [
            'mode' => $mode,
            'use_context' => $mode !== 'baseline',
            'test_days' => $tested,
            'weekday_mae' => $weekdayMae === null ? null : round($weekdayMae, 2),
            'context_mae' => $contextMae === null ? null : round($contextMae, 2),
            'factor_mae' => $best === null ? null : round($bestMae, 2),
            'config' => $best === null ? null : $candidates[$best],
            'bias' => $best === null || !$candidates[$best]['bias'] ? 1.0 : round($this->bias($residuals[$best]), 3),
            'tested_settings' => count($candidates),
        ];
    }

    public function weights(array $history, array $target, bool $useContext): array
    {
        $weights = [];
        foreach ($history as $date => $day) {
            $weight = 1;
            if ($useContext) {
                $weight += $day['near_payday'] === $target['near_payday'] ? 1 : 0;
                $weight += $target['rainy'] !== null && $day['rainy'] === $target['rainy'] ? 2 : 0;
            }
            $weights[$date] = $weight;
        }

        return $weights;
    }

    public function estimate(array $values, array $weights): ?float
    {
        $total = $count = 0;
        foreach ($values as $date => $value) {
            $weight = $weights[$date] ?? 0;
            $total += $value * $weight;
            $count += $weight;
        }

        return $count > 0 ? round($total / $count, 2) : null;
    }

    /**
     * How much busier each kind of special day is than a regular day of the same weekday, learned from history.
     * Each day is compared with the median of the other days on its weekday; an uplift is the typical ratio of
     * that kind of day over the typical ratio of regular days, shrunk toward 1 while examples are few.
     * "observed" is the unshrunk ratio, used to scale past special days back to a regular day.
     *
     * @return array<string, array{uplift: float, observed: float, days: int}>
     */
    public function uplifts(array $history, array $kinds = HolidayCalendar::SPECIAL_DAYS): array
    {
        $byWeekday = [];
        foreach ($history as $day) {
            $byWeekday[$day['weekday']][$day['date']] = (float) $day['net_sales'];
        }

        $ratios = [];
        foreach ($history as $day) {
            $others = $byWeekday[$day['weekday']];
            unset($others[$day['date']]);
            $typical = count($others) >= 2 ? $this->median(array_values($others)) : null;
            if ($typical > 0) {
                $kind = in_array($day['special'] ?? null, $kinds, true) ? $day['special'] : 'regular';
                $ratios[$kind][] = $day['net_sales'] / $typical;
            }
        }

        $regular = isset($ratios['regular']) ? $this->median($ratios['regular']) : 1.0;
        $uplifts = [];
        foreach ($kinds as $kind) {
            $n = count($ratios[$kind] ?? []);
            $raw = $n > 0 && $regular > 0 ? $this->median($ratios[$kind]) / $regular : 1.0;
            $uplift = 1 + ($raw - 1) * $n / ($n + self::UPLIFT_SHRINK);
            $uplifts[$kind] = [
                'uplift' => round(min(2.0, max(0.6, $uplift)), 3),
                'observed' => round(min(2.5, max(0.5, $raw)), 3),
                'days' => $n,
            ];
        }

        return $uplifts;
    }

    /**
     * The tuned estimate: past same-weekday sales scaled back to a regular day (removing the uplift observed for
     * their kind of day), optionally capped, weighted by recency and matching weather, then the target's uplift.
     *
     * @return array{base: ?float, uplift: float, estimate: ?float}
     */
    public function factorEstimate(array $sameWeekday, string $target, ?string $special, array $uplifts, ?bool $rainy, array $config): array
    {
        $uplift = $special === null ? 1.0 : ($uplifts[$special]['uplift'] ?? 1.0);
        if (count($sameWeekday) < 2) {
            return ['base' => null, 'uplift' => $uplift, 'estimate' => null];
        }

        $base = $this->combine($this->prepare($sameWeekday, $target, $uplifts), $config, $rainy);

        return ['base' => $base, 'uplift' => $uplift, 'estimate' => $base === null ? null : round($base * $uplift, 2)];
    }

    /** Past days scaled back to a regular day, with their age in days and weather, ready for any setting. */
    private function prepare(array $sameWeekday, string $target, array $uplifts): array
    {
        $targetDay = self::dayNumber($target);
        $rows = [];
        foreach ($sameWeekday as $date => $day) {
            $own = $day['special'] ?? null;
            $rows[] = [
                $day['net_sales'] / ($own === null ? 1.0 : ($uplifts[$own]['observed'] ?? 1.0)),
                abs($targetDay - self::dayNumber((string) $date)),
                $day['rainy'] ?? null,
            ];
        }

        return $rows;
    }

    private function combine(array $rows, array $config, ?bool $rainy): ?float
    {
        if ($rows === []) {
            return null;
        }

        $low = $high = null;
        if ($config['cap'] && count($rows) >= 4) {
            $typical = $this->median(array_column($rows, 0));
            [$low, $high] = [$typical * 0.6, $typical * 1.5];
        }

        $total = $count = 0.0;
        foreach ($rows as [$value, $age, $wasRainy]) {
            $weight = $config['half_life'] === null ? 1.0 : 0.5 ** ($age / $config['half_life']);
            if ($rainy !== null && $wasRainy === $rainy) {
                $weight *= $config['rain'];
            }
            $total += ($low === null ? $value : min($high, max($low, $value))) * $weight;
            $count += $weight;
        }

        return $count > 0 ? round($total / $count, 2) : null;
    }

    private static function dayNumber(string $date): int
    {
        static $cache = [];

        return $cache[$date] ??= intdiv(strtotime(substr($date, 0, 10).' 00:00:00 UTC'), 86400);
    }

    /** Corrects a forecast that tends to run under or over: the recent typical actual ÷ forecast, shrunk and capped. */
    public function bias(array $ratios): float
    {
        $recent = array_slice($ratios, -12);
        if (count($recent) < 6) {
            return 1.0;
        }

        $n = count($recent);
        $bias = 1 + ($this->median($recent) - 1) * $n / ($n + self::BIAS_SHRINK);

        return min(1.15, max(0.85, $bias));
    }

    public static function kinds(string $name): array
    {
        return self::KINDS[$name];
    }

    /** Plain-language summary of a tuned setting, for the forecast page. */
    public static function describe(array $config): string
    {
        $parts = [
            $config['half_life'] === null ? 'all weeks count equally' : 'recent weeks count more (half weight after '.round($config['half_life'] / 7).' weeks)',
            match ($config['kinds']) {
                'none' => 'no special-day adjustment',
                'peaks' => 'adjusts for long weekends and holidays',
                default => 'adjusts for long weekends, holidays and paydays',
            },
        ];
        if ($config['cap']) {
            $parts[] = 'one-off days capped';
        }
        if ($config['bias']) {
            $parts[] = 'corrects running high or low';
        }
        if ($config['rain'] > 1) {
            $parts[] = 'matches rainy or dry days';
        }
        if ($config['blend'] < 1) {
            $parts[] = 'averaged with the plain weekday average';
        }

        return ucfirst(implode(', ', $parts)).'.';
    }

    private function complexity(array $config): int
    {
        return ($config['half_life'] !== null ? 1 : 0) + ['none' => 0, 'peaks' => 1, 'all' => 2][$config['kinds']]
            + (int) $config['cap'] + (int) $config['bias'] + ($config['rain'] > 1 ? 1 : 0) + ($config['blend'] < 1 ? 1 : 0);
    }

    private function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
