<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class ProductionDemandModel
{
    // Sales from 6 weeks ago count half as much as last week's
    public const HALF_LIFE_DAYS = 42;

    // Pulls a learned uplift toward "no change" until there are enough examples of that kind of day
    private const UPLIFT_SHRINK = 3;

    private const BIAS_SHRINK = 6;

    /**
     * Back-tests each method using only sales from before every test date, and picks the one with the lowest
     * recent error: the plain weekday average, matching on payday and rain ("context"), or the factor model.
     */
    public function validate(array $days): array
    {
        $errors = [];
        $history = [];
        $all = [];
        $residuals = [];
        foreach ($days as $day) {
            $weekday = $day['weekday'];
            $past = $history[$weekday] ?? [];
            if (count($past) >= 3) {
                $weekdayWeights = array_fill_keys(array_keys($past), 1);
                $contextWeights = $this->weights($past, $day, true);
                $sales = array_column($past, 'net_sales', 'date');
                $factor = $this->factorEstimate($past, $day['date'], $day['special'] ?? null, $this->uplifts($all), $day['rainy']);
                $errors[] = [
                    abs($day['net_sales'] - $this->estimate($sales, $weekdayWeights)),
                    abs($day['net_sales'] - $this->estimate($sales, $contextWeights)),
                    abs($day['net_sales'] - $factor['estimate'] * $this->bias($residuals)),
                ];
                if ($factor['estimate'] > 0) {
                    $residuals[] = $day['net_sales'] / $factor['estimate'];
                }
            }
            $history[$weekday][$day['date']] = $day;
            $all[$day['date']] = $day;
        }
        $errors = array_slice($errors, -28);
        $mae = fn (int $column) => $errors === [] ? null : array_sum(array_column($errors, $column)) / count($errors);
        [$weekdayMae, $contextMae, $factorMae] = [$mae(0), $mae(1), $mae(2)];

        $mode = 'baseline';
        if (count($errors) >= 6) {
            $best = min($weekdayMae, $contextMae, $factorMae);
            // Simpler methods win ties
            $mode = $best === $weekdayMae ? 'baseline' : ($best === $contextMae ? 'context' : 'factors');
        }

        return [
            'mode' => $mode,
            'use_context' => $mode !== 'baseline',
            'test_days' => count($errors),
            'weekday_mae' => $weekdayMae === null ? null : round($weekdayMae, 2),
            'context_mae' => $contextMae === null ? null : round($contextMae, 2),
            'factor_mae' => $factorMae === null ? null : round($factorMae, 2),
            'bias' => round($this->bias($residuals), 3),
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
     *
     * "observed" is the unshrunk ratio, used to scale past special days back to a regular day.
     *
     * @return array<string, array{uplift: float, observed: float, days: int}>
     */
    public function uplifts(array $history): array
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
                $ratios[$day['special'] ?? 'regular'][] = $day['net_sales'] / $typical;
            }
        }

        $regular = isset($ratios['regular']) ? $this->median($ratios['regular']) : 1.0;
        $uplifts = [];
        foreach (HolidayCalendar::SPECIAL_DAYS as $kind) {
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
     * The factor model: past same-weekday sales are scaled back to a regular day (removing the uplift observed
     * for their kind of day), extreme days are capped, recent weeks and matching weather count more, then the target's uplift
     * is applied.
     *
     * @return array{base: ?float, uplift: float, estimate: ?float}
     */
    public function factorEstimate(array $sameWeekday, string $target, ?string $special, array $uplifts, ?bool $rainy): array
    {
        $uplift = $special === null ? 1.0 : ($uplifts[$special]['uplift'] ?? 1.0);
        if (count($sameWeekday) < 2) {
            return ['base' => null, 'uplift' => $uplift, 'estimate' => null];
        }

        $targetDay = CarbonImmutable::parse($target);
        $values = $weights = [];
        foreach ($sameWeekday as $date => $day) {
            $own = $day['special'] ?? null;
            $values[$date] = $day['net_sales'] / ($own === null ? 1.0 : ($uplifts[$own]['observed'] ?? 1.0));
            $weight = 0.5 ** (abs(CarbonImmutable::parse($date)->diffInDays($targetDay)) / self::HALF_LIFE_DAYS);
            if ($rainy !== null && ($day['rainy'] ?? null) === $rainy) {
                $weight *= 2;
            }
            $weights[$date] = $weight;
        }

        if (count($values) >= 4) {
            $typical = $this->median(array_values($values));
            $values = array_map(fn ($value) => min($typical * 1.5, max($typical * 0.6, $value)), $values);
        }

        $base = $this->estimate($values, $weights);

        return ['base' => $base, 'uplift' => $uplift, 'estimate' => $base === null ? null : round($base * $uplift, 2)];
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

    private function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
