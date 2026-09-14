<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DailyCashReport extends Command
{
    protected $signature = 'report:daily-cash
                            {--from= : Start date (Y-m-d), default 7 days ago}
                            {--to=   : End date   (Y-m-d), default today}';

    protected $description = 'Daily cash-in-hand vs system sales report with short/excess analysis';

    public function handle(): int
    {
        $to   = $this->option('to')   ?: now()->toDateString();
        $from = $this->option('from') ?: now()->subDays(6)->toDateString();

        $this->info("Cash Report  {$from} → {$to}");
        $this->line('');

        $rows = DB::select("
            WITH RECURSIVE dates AS (
                SELECT DATE(:from) AS d
                UNION ALL
                SELECT DATE_ADD(d, INTERVAL 1 DAY) FROM dates WHERE d < DATE(:to)
            ),
            daily_txns AS (
                SELECT
                    DATE(transacted_at)                                                                       AS txn_date,
                    SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount        ELSE 0 END)     AS gross_sales,
                    SUM(CASE WHEN type = 'expense'                        THEN amount        ELSE 0 END)     AS total_expense,
                    SUM(CASE WHEN type = 'payroll'                        THEN amount        ELSE 0 END)     AS total_salary,
                    SUM(CASE WHEN type IN ('payment','income_adjustment') THEN  amount
                             WHEN type IN ('expense','payroll')           THEN -amount
                             ELSE 0 END)                                                                      AS daily_net
                FROM financial_transactions
                WHERE DATE(transacted_at) BETWEEN :from2 AND :to2
                  AND type NOT IN ('order')
                GROUP BY DATE(transacted_at)
            )
            SELECT
                d.d                                   AS `date`,
                COALESCE(t.gross_sales,   0)          AS system_sales,
                COALESCE(t.total_expense, 0)          AS expense,
                COALESCE(t.total_salary,  0)          AS salary,
                SUM(COALESCE(t.daily_net, 0))
                    OVER (ORDER BY d.d ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS net_balance_as_of_day
            FROM dates d
            LEFT JOIN daily_txns t ON t.txn_date = d.d
            ORDER BY d.d
        ", ['from' => $from, 'to' => $to, 'from2' => $from, 'to2' => $to]);

        // ── Prompt for actual cash per day ────────────────────────────────────
        $actuals = [];
        $this->line('Enter actual cash collected each day (press Enter to skip):');
        foreach ($rows as $row) {
            $val = $this->ask("  {$row->date}  system ₱" . number_format($row->system_sales, 2) . '  actual cash');
            $actuals[$row->date] = $val !== null && $val !== '' ? (float) $val : null;
        }

        // ── Build output table ────────────────────────────────────────────────
        $headers = ['Date', 'System Sales', 'Actual Cash', 'Expense', 'Salary', 'Δ None', 'Δ Salary', 'Δ Expense', 'Δ All', 'Net Balance', 'Analysis'];
        $data    = [];

        foreach ($rows as $row) {
            $actual  = $actuals[$row->date] ?? null;
            $sys     = (float) $row->system_sales;
            $exp     = (float) $row->expense;
            $sal     = (float) $row->salary;
            $balance = (float) $row->net_balance_as_of_day;

            $dNone = $actual !== null ? $actual - $sys        : null;
            $dSal  = $actual !== null ? $actual - ($sys - $sal)        : null;
            $dExp  = $actual !== null ? $actual - ($sys - $exp)        : null;
            $dAll  = $actual !== null ? $actual - ($sys - $sal - $exp) : null;

            $analysis = $this->analyse($actual, $dNone, $dSal, $dExp, $dAll, $sal, $exp);

            $fmt = fn($v) => $v !== null ? '₱' . number_format($v, 2) : '—';

            $data[] = [
                $row->date,
                '₱' . number_format($sys, 2),
                $fmt($actual),
                $exp > 0 ? '₱' . number_format($exp, 2) : '—',
                $sal > 0 ? '₱' . number_format($sal, 2) : '—',
                $fmt($dNone),
                $fmt($dSal),
                $fmt($dExp),
                $fmt($dAll),
                '₱' . number_format($balance, 2),
                $analysis,
            ];
        }

        $this->table($headers, $data);

        return self::SUCCESS;
    }

    private function analyse(?float $actual, ?float $dNone, ?float $dSal, ?float $dExp, ?float $dAll, float $sal, float $exp): string
    {
        if ($actual === null) return 'No report submitted';

        $abs = fn($v) => abs($v ?? PHP_FLOAT_MAX);

        if ($abs($dNone) <= 100) return 'Balanced';
        if ($abs($dAll)  <= 100 && ($sal > 0 || $exp > 0))
            return "OK — salary ₱{$sal} + expense ₱{$exp} deducted from cash";
        if ($abs($dSal)  <= 100 && $sal > 0)
            return "OK — salary ₱{$sal} deducted from cash";
        if ($abs($dExp)  <= 100 && $exp > 0)
            return "OK — expense ₱{$exp} deducted from cash";

        $sign  = $dNone > 0 ? 'EXCESS' : 'SHORT';
        $minor = abs($dNone) <= 500 ? 'Minor ' : '';
        $amt   = number_format(abs($dNone), 2);

        if ($dNone > 100) {
            return "{$minor}EXCESS ₱{$amt} — cash exceeds system sales; check unrecorded income or over-collection";
        }

        // SHORT — find best-fit explanation
        $note = '';
        if ($abs($dAll) <= 300 && ($sal > 0 || $exp > 0)) {
            $note = "salary ₱{$sal} + expense ₱{$exp} likely paid from cash";
        } elseif ($abs($dSal) <= 300 && $sal > 0) {
            $note = "salary ₱{$sal} likely paid from cash";
        } elseif ($abs($dExp) <= 300 && $exp > 0) {
            $note = "expense ₱{$exp} likely paid from cash";
        } elseif ($sal > 0 || $exp > 0) {
            $best  = min($abs($dSal), $abs($dExp), $abs($dAll));
            $label = match(true) {
                $best === $abs($dAll) => 'salary+expense (gap ₱' . number_format($abs($dAll), 2) . ')',
                $best === $abs($dSal) => 'salary only (gap ₱'    . number_format($abs($dSal), 2) . ')',
                default               => 'expense only (gap ₱'   . number_format($abs($dExp), 2) . ')',
            };
            $note = "not fully explained; closest: {$label}";
        } else {
            $note = 'unexplained — investigate missing cash';
        }

        return "{$minor}SHORT ₱{$amt} — {$note}";
    }
}
