<?php

namespace App\Services;

class DepositReconciliation
{
    public function calculate(array $opening, array $closing, array $counts): array
    {
        $cents = static fn ($value): int => (int) round((float) $value * 100);
        $drawer = $cents($counts['drawer_cash']);
        $shiftGcash = $cents($counts['shift_gcash']);
        $lockbox = $cents($counts['lockbox_total']);
        $totalGcash = $cents($counts['total_gcash']);
        $shiftNet = $cents($closing['running_balance']) - $cents($opening['running_balance']);
        $breakdown = [];
        foreach (['payment', 'income_adjustment', 'expense', 'payroll', 'asset_deduction', 'payout_share'] as $type) {
            $breakdown[$type] = ($cents($closing['cumulative_totals'][$type] ?? 0)
                - $cents($opening['cumulative_totals'][$type] ?? 0)) / 100;
        }

        return [
            'version' => 2,
            'drawer_cash' => $drawer / 100,
            'shift_gcash' => $shiftGcash / 100,
            'lockbox_total' => $lockbox / 100,
            'total_gcash' => $totalGcash / 100,
            'shift_actual' => ($drawer + $shiftGcash) / 100,
            'shift_net' => $shiftNet / 100,
            'shift_variance' => ($drawer + $shiftGcash - $shiftNet) / 100,
            // Drawer cash has already been transferred into the counted lockbox.
            // Shift GCash is already included in the total wallet balance.
            'overall_actual' => ($lockbox + $totalGcash) / 100,
            'overall_expected' => $cents($closing['running_balance']) / 100,
            'overall_variance' => ($lockbox + $totalGcash - $cents($closing['running_balance'])) / 100,
            'shift_breakdown' => $breakdown,
            'notes' => $counts['notes'] ?? null,
        ];
    }
}
