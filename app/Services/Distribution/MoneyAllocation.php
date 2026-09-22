<?php

namespace App\Services\Distribution;

/** Largest-remainder allocation: every cent is assigned exactly once. */
class MoneyAllocation
{
    public static function split(float $amount, array $weights): array
    {
        $cents = (int) round(max(0, $amount) * 100);
        $total = array_sum($weights);
        if ($total <= 0) {
            return array_fill_keys(array_keys($weights), 0.0);
        }
        $parts = $remainders = [];
        foreach ($weights as $key => $weight) {
            if ($weight < 0) {
                throw new \InvalidArgumentException('Allocation weights cannot be negative.');
            }
            $exact = $cents * $weight / $total;
            $parts[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $parts[$key];
        }
        arsort($remainders, SORT_NUMERIC);
        $remaining = $cents - array_sum($parts);
        foreach (array_keys($remainders) as $key) {
            if ($remaining-- <= 0) {
                break;
            }
            $parts[$key]++;
        }

        return array_map(fn ($part) => $part / 100, $parts);
    }
}
