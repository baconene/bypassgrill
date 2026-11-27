<?php

namespace App\Services;

class ProductionDemandModel
{
    // Give the contextual forecast a fair comparison using only earlier sales for each test date.
    public function validate(array $days): array
    {
        $errors = [];
        $history = [];
        foreach ($days as $day) {
            $weekday = $day['weekday'];
            $past = $history[$weekday] ?? [];
            if (count($past) >= 3) {
                $weekdayWeights = array_fill_keys(array_keys($past), 1);
                $contextWeights = $this->weights($past, $day, true);
                $sales = array_column($past, 'net_sales', 'date');
                $errors[] = [
                    abs($day['net_sales'] - $this->estimate($sales, $weekdayWeights)),
                    abs($day['net_sales'] - $this->estimate($sales, $contextWeights)),
                ];
            }
            $history[$weekday][$day['date']] = $day;
        }
        $errors = array_slice($errors, -28);
        $weekdayMae = $errors === [] ? null : array_sum(array_column($errors, 0)) / count($errors);
        $contextMae = $errors === [] ? null : array_sum(array_column($errors, 1)) / count($errors);

        return [
            'use_context' => count($errors) >= 6 && $contextMae < $weekdayMae,
            'test_days' => count($errors),
            'weekday_mae' => $weekdayMae === null ? null : round($weekdayMae, 2),
            'context_mae' => $contextMae === null ? null : round($contextMae, 2),
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
}
