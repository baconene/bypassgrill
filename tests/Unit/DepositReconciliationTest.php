<?php

namespace Tests\Unit;

use App\Services\DepositReconciliation;
use PHPUnit\Framework\TestCase;

class DepositReconciliationTest extends TestCase
{
    public function test_shift_and_overall_variances_are_separate_without_counting_shift_funds_twice(): void
    {
        $report = (new DepositReconciliation)->calculate(
            ['running_balance' => 1000, 'cumulative_totals' => ['payment' => 1200, 'expense' => 200]],
            ['running_balance' => 1500, 'cumulative_totals' => ['payment' => 1800, 'expense' => 300]],
            ['drawer_cash' => 300, 'shift_gcash' => 190, 'lockbox_total' => 1000, 'total_gcash' => 520]
        );
        $this->assertEquals(490, $report['shift_actual']);
        $this->assertEquals(500, $report['shift_net']);
        $this->assertEquals(-10, $report['shift_variance']);
        $this->assertEquals(1520, $report['overall_actual']);
        $this->assertEquals(20, $report['overall_variance']);
        $this->assertEquals(600, $report['shift_breakdown']['payment']);
        $this->assertEquals(100, $report['shift_breakdown']['expense']);
    }

    public function test_decimal_counts_balance_exactly_and_do_not_use_calendar_day_totals(): void
    {
        $report = (new DepositReconciliation)->calculate(
            ['running_balance' => 100.25],
            ['running_balance' => 150.40, 'net_balance' => 999],
            ['drawer_cash' => '30.10', 'shift_gcash' => '20.05', 'lockbox_total' => '130.35', 'total_gcash' => '20.05']
        );
        $this->assertEquals(50.15, $report['shift_actual']);
        $this->assertEquals(0, $report['shift_variance']);
        $this->assertEquals(0, $report['overall_variance']);
    }

    public function test_zero_counts_are_not_replaced_by_expected_values(): void
    {
        $report = (new DepositReconciliation)->calculate(
            ['running_balance' => 100], ['running_balance' => 200],
            ['drawer_cash' => 0, 'shift_gcash' => 0, 'lockbox_total' => 0, 'total_gcash' => 0]
        );
        $this->assertEquals(-100, $report['shift_variance']);
        $this->assertEquals(-200, $report['overall_variance']);
    }
}
