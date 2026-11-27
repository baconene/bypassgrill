<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class WeekdaySalesRank
{
    public static function paydayDistance(CarbonImmutable $date): int
    {
        $distance = 0;
        while (!in_array($date->subDays($distance)->day, [15, 30], true)
            && !in_array($date->addDays($distance)->day, [15, 30], true)) {
            $distance++;
        }

        return $distance;
    }

    public static function nearPayday(CarbonImmutable $date): bool
    {
        return self::paydayDistance($date) <= 3;
    }

    public static function typicalDates(array $totals): array
    {
        if ($totals === []) {
            return [];
        }

        $sorted = array_values($totals);
        sort($sorted, SORT_NUMERIC);
        $threshold = $sorted[(int) ceil(count($sorted) * 0.8) - 1];

        return array_keys(array_filter($totals, fn ($total) => $total >= $threshold));
    }

    public static function summarize(array $totals, string $date): array
    {
        $typical = array_intersect_key($totals, array_flip(self::typicalDates($totals)));
        $lowest = $totals === [] ? null : min($totals);
        $selected = $totals[$date] ?? null;
        $rank = $selected === null ? null : count(array_filter($totals, fn ($total) => $total < $selected)) + 1;

        return [
            'days' => count($totals),
            'rank' => $rank,
            'lowest' => $lowest,
            'is_lowest' => count($totals) >= 2 && $selected !== null && $selected === $lowest,
            'typical_low' => $typical === [] ? null : min($typical),
            'typical_high' => $typical === [] ? null : max($typical),
        ];
    }
}
