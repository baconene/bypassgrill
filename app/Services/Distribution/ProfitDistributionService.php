<?php

namespace App\Services\Distribution;

use App\Models\DistributionSnapshot;
use App\Models\DistributionSnapshotDetail;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the full profit / sales distribution. Treats existing sales and
 * financial tables as the single source of truth — never duplicates them.
 */
class ProfitDistributionService
{
    /** Cache-version stamp — bumped whenever underlying financial data changes. */
    private const VERSION_KEY = 'dist:cache_version';

    public function __construct(
        private SalesAggregateService $sales,
        private RoyaltyEngine $royalty,
        private ShareDistributionService $shares,
        private ReportService $reports,
    ) {}

    /**
     * Compute a live distribution preview.
     *
     * @param string $basis 'sales' | 'profit'
     */
    public function compute(string $basis, string $start, string $end, ?int $categoryId = null, ?int $productId = null, ?int $shareholderId = null): array
    {
        $ver = Cache::get(self::VERSION_KEY, 0);
        $key = 'dist:v' . $ver . ':' . md5(implode('|', [$basis, $start, $end, $categoryId, $productId, $shareholderId]));

        return Cache::remember($key, 300, function () use ($basis, $start, $end, $categoryId, $productId, $shareholderId) {
            $metrics   = $this->sales->salesMetrics($start, $end, $categoryId, $productId);
            $royalty   = $this->royalty->compute($start, $end, $categoryId, $productId);
            $salesBase = round($metrics['net_sales'] - $metrics['refunds'], 2);

            $scoped = $categoryId || $productId;
            $zeroDetail = ['net_profit' => 0.0, 'income_adjustments' => 0.0, 'expenses' => 0.0, 'payroll' => 0.0];

            if ($basis === 'profit') {
                $detail    = $this->profitDetail($start, $end, $categoryId, $productId, $metrics);
                $base      = $detail['net_profit'];
                $baseLabel = $scoped ? 'Gross Profit (scope)' : 'Net Profit';
            } elseif ($basis === 'hybrid') {
                $detail    = $this->profitDetail($start, $end, $categoryId, $productId, $metrics);
                $base      = $detail['net_profit'];
                $baseLabel = $scoped ? 'Gross Profit (scope) — Hybrid' : 'Net Profit — Hybrid';
            } else {
                // Sales basis: only compute the profit detail for the financial summary card;
                // wrap in try-catch so a P&L failure never breaks the sales calculation.
                try {
                    $detail = $this->profitDetail($start, $end, $categoryId, $productId, $metrics);
                } catch (\Throwable) {
                    $detail = $zeroDetail;
                }
                $base      = $salesBase;
                $baseLabel = 'Net Sales';
            }

            $profitBase = $detail['net_profit'];

            $distributable = round(max(0, $base - $royalty['total']), 2);
            $alloc         = $this->shares->allocate($distributable, $shareholderId);
            $chartRoyalties = $royalty['total'];

            if ($basis === 'hybrid') {
                // Build royalty-by-holder map using plain PHP so integer shareholder IDs
                // match without Collection::groupBy converting keys to strings.
                $royaltyByHolder = [];
                foreach ($royalty['by_recipient'] as $r) {
                    if ($r['shareholder_id'] !== null) {
                        $id = (int) $r['shareholder_id'];
                        $royaltyByHolder[$id] = round(($royaltyByHolder[$id] ?? 0.0) + (float) $r['amount'], 2);
                    }
                }

                $linkedTotal = 0.0;
                $alloc['members'] = array_map(function ($m) use ($royaltyByHolder, &$linkedTotal) {
                    $royaltyAmt  = $royaltyByHolder[(int) $m['shareholder_id']] ?? 0.0;
                    $linkedTotal = round($linkedTotal + $royaltyAmt, 2);
                    return array_merge($m, [
                        'profit_share'   => $m['amount'],
                        'royalty_amount' => round($royaltyAmt, 2),
                        'amount'         => round($m['amount'] + $royaltyAmt, 2),
                    ]);
                }, $alloc['members']);

                $alloc['members_total'] = round(array_sum(array_column($alloc['members'], 'amount')), 2);
                // Pie chart: linked royalties are already inside member slices; only show the remainder
                $chartRoyalties = round($royalty['total'] - $linkedTotal, 2);
            }

            return [
                'basis'        => $basis,
                'base_label'   => $baseLabel,
                'range'        => ['start' => $start, 'end' => $end],
                'metrics'      => $metrics,
                'base_amount'  => $base,
                'royalty'      => $royalty,
                'distributable'=> $distributable,
                'members'      => $alloc['members'],
                'members_total'=> $alloc['members_total'],
                'members_percentage' => $alloc['members_percentage'],
                'company_amount'     => $alloc['company_amount'],
                'company_percentage' => $alloc['company_percentage'],
                'chart' => $this->chartData($alloc, $chartRoyalties),
                'financial_summary' => [
                    'gross_sales'        => $metrics['gross_sales'],
                    'net_sales'          => $metrics['net_sales'],
                    'refunds'            => $metrics['refunds'],
                    'cogs'               => $metrics['cogs'],
                    'income_adjustments' => $detail['income_adjustments'],
                    'expenses'           => $detail['expenses'],
                    'payroll'            => $detail['payroll'],
                    'sales_base'         => $salesBase,
                    'net_profit'         => $profitBase,
                    'period_end'         => $end,
                ],
            ];
        });
    }

    /**
     * Invalidate every cached distribution result. Called whenever orders,
     * order items, or financial transactions change, so the live P&L report and
     * the profit-sharing summary never drift apart.
     */
    public static function bumpCacheVersion(): void
    {
        Cache::add(self::VERSION_KEY, 0);
        Cache::increment(self::VERSION_KEY);
    }

    /**
     * Net profit plus the lines that bridge gross profit → net profit
     * (manual income adjustments, operating expenses, payroll).
     *
     * @return array{net_profit:float, income_adjustments:float, expenses:float, payroll:float}
     */
    private function profitDetail(string $start, string $end, ?int $categoryId, ?int $productId, array $metrics): array
    {
        if ($categoryId || $productId) {
            // Scoped profit = scope net sales − scope COGS (operating expenses,
            // adjustments and payroll can't be attributed to a single product/category).
            return [
                'net_profit'         => round($metrics['net_sales'], 2),
                'income_adjustments' => 0.0,
                'expenses'           => 0.0,
                'payroll'            => 0.0,
            ];
        }

        $pl = $this->reports->getProfitLossReport(Carbon::parse($start), Carbon::parse($end), false);

        return [
            'net_profit'         => round((float) ($pl['net_profit'] ?? 0), 2),
            'income_adjustments' => round((float) ($pl['income_adjustments']['total'] ?? 0), 2),
            'expenses'           => round((float) ($pl['expenses']['total'] ?? 0), 2),
            'payroll'            => round((float) ($pl['payroll']['total'] ?? 0), 2),
        ];
    }

    private function chartData(array $alloc, float $royaltyTotal): array
    {
        $data = [];
        foreach ($alloc['members'] as $m) {
            $data[] = ['label' => $m['name'], 'value' => $m['amount'], 'type' => 'member'];
        }
        if ($royaltyTotal > 0) {
            $data[] = ['label' => 'Royalties', 'value' => round($royaltyTotal, 2), 'type' => 'royalty'];
        }
        $data[] = ['label' => 'Company', 'value' => $alloc['company_amount'], 'type' => 'company'];

        return $data;
    }

    /** Persist a historical snapshot from a computed result. */
    public function snapshot(array $result, ?array $filters = null): DistributionSnapshot
    {
        return DB::transaction(function () use ($result, $filters) {
            $snap = DistributionSnapshot::create([
                'period_start'         => $result['range']['start'],
                'period_end'           => $result['range']['end'],
                'distribution_basis'   => $result['basis'],
                'gross_amount'         => $result['metrics']['gross_sales'],
                'refunds_amount'       => $result['metrics']['refunds'],
                'cogs_amount'          => $result['metrics']['cogs'],
                'expenses_amount'      => max(0, round($result['base_amount'] - ($result['metrics']['net_sales'] - $result['metrics']['cogs']), 2)),
                'royalty_amount'       => $result['royalty']['total'],
                'distributable_amount' => $result['distributable'],
                'members_amount'       => $result['members_total'],
                'company_amount'       => $result['company_amount'],
                'filters_applied'      => $filters,
                'created_by'           => auth()->id(),
            ]);

            foreach ($result['members'] as $m) {
                DistributionSnapshotDetail::create([
                    'snapshot_id'    => $snap->id,
                    'recipient_type' => 'shareholder',
                    'shareholder_id' => $m['shareholder_id'],
                    'recipient_name' => $m['name'],
                    'percentage'     => $m['percentage'],
                    'amount'         => $m['amount'],
                ]);
            }
            foreach ($result['royalty']['by_recipient'] as $r) {
                DistributionSnapshotDetail::create([
                    'snapshot_id'    => $snap->id,
                    'recipient_type' => 'royalty',
                    'shareholder_id' => $r['shareholder_id'],
                    'recipient_name' => $r['recipient_name'],
                    'percentage'     => 0,
                    'amount'         => $r['amount'],
                ]);
            }
            DistributionSnapshotDetail::create([
                'snapshot_id'    => $snap->id,
                'recipient_type' => 'company',
                'shareholder_id' => null,
                'recipient_name' => 'Company Retained Earnings',
                'percentage'     => $result['company_percentage'],
                'amount'         => $result['company_amount'],
            ]);

            return $snap->load('details');
        });
    }

    /** Monthly distribution trend over a date range (members / company / royalties). */
    public function trend(string $basis, string $start, string $end): array
    {
        $cursor = Carbon::parse($start)->startOfMonth();
        $last   = Carbon::parse($end)->startOfMonth();
        $out = [];

        while ($cursor <= $last) {
            $mStart = $cursor->copy()->startOfMonth()->toDateString();
            $mEnd   = $cursor->copy()->endOfMonth()->toDateString();
            $r = $this->compute($basis, $mStart, $mEnd);
            $out[] = [
                'month'    => $cursor->format('M Y'),
                'members'  => $r['members_total'],
                'company'  => $r['company_amount'],
                'royalty'  => $r['royalty']['total'],
                'by_member'=> collect($r['members'])->mapWithKeys(fn ($m) => [$m['name'] => $m['amount']])->all(),
            ];
            $cursor->addMonth();
        }

        return $out;
    }
}
