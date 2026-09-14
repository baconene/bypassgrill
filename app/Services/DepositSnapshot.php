<?php

namespace App\Services;

use App\Models\FinancialTransaction;
use App\Models\PaymentTender;
use Carbon\CarbonInterface;

class DepositSnapshot
{
    public function capture(CarbonInterface $at): array
    {
        // One aggregate query gives all financial figures the same ledger read.
        $rows = FinancialTransaction::query()
            ->where('type', '!=', 'order')->where('transacted_at', '<=', $at)
            ->selectRaw('type, payment_tender_id, SUM(amount) AS total, SUM(CASE WHEN transacted_at >= ? THEN amount ELSE 0 END) AS daily', [$at->copy()->startOfDay()])
            ->groupBy('type', 'payment_tender_id')->get();
        $totals = [];
        $daily = [];
        $balances = [];
        foreach ($rows as $row) {
            $type = $row->type;
            $key = $row->payment_tender_id ?? 'untagged';
            $sign = in_array($type, ['payment', 'income_adjustment']) ? 1 : -1;
            $cents = (int) round((float) $row->total * 100);
            $totals[$type] = ($totals[$type] ?? 0) + $cents;
            $daily[$type] = ($daily[$type] ?? 0) + (int) round((float) $row->daily * 100);
            $balances[$key] = ($balances[$key] ?? 0) + $sign * $cents;
        }
        $net = 0;
        foreach ($daily as $type => $amount) {
            $net += in_array($type, ['payment', 'income_adjustment']) ? $amount : -$amount;
        }
        $tenders = PaymentTender::orderBy('display_order')->get()->map(fn ($tender) => [
            'id' => $tender->id, 'name' => $tender->name,
            'balance' => ($balances[$tender->id] ?? 0) / 100,
        ])->all();
        $tenders[] = ['id' => null, 'name' => 'Untagged', 'balance' => ($balances['untagged'] ?? 0) / 100];

        return [
            'captured_at' => $at->toIso8601String(),
            'business_date' => $at->toDateString(),
            'running_balance' => array_sum($balances) / 100,
            'net_balance' => $net / 100,
            'expense' => ($daily['expense'] ?? 0) / 100,
            'income_adjustment' => ($daily['income_adjustment'] ?? 0) / 100,
            'payroll_deductions' => ($daily['payroll'] ?? 0) / 100,
            'income' => ($daily['payment'] ?? 0) / 100,
            'asset_deductions' => ($daily['asset_deduction'] ?? 0) / 100,
            'payout_shares' => ($daily['payout_share'] ?? 0) / 100,
            'balance_by_tender' => $tenders,
            'cumulative_totals' => array_map(fn ($cents) => $cents / 100, $totals),
        ];
    }
}
