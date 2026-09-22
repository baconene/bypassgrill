<?php

namespace App\Services\Distribution;

use App\Models\DistributionSnapshot;
use App\Models\DistributionSnapshotDetail;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfitDistributionService
{
    private const VERSION_KEY = 'dist:cache_version';

    public function __construct(
        private SalesAggregateService $sales,
        private ShareDistributionService $shares,
        private ReportService $reports,
        private IncentivePoolService $incentive,
    ) {}

    public function compute(string $basis, string $start, string $end, ?int $categoryId = null, ?int $productId = null, ?int $shareholderId = null): array
    {
        $ver = Cache::get(self::VERSION_KEY, 0);
        $key = 'dist:carried-cash:v'.$ver.':'.md5(implode('|', [$basis, $start, $end, $categoryId, $productId, $shareholderId]));

        return Cache::remember($key, 300, function () use ($basis, $start, $end, $categoryId, $productId, $shareholderId) {
            $metrics = $this->sales->salesMetrics($start, $end, $categoryId, $productId);
            // Financial shows cash movement, not the COGS-based profit-and-loss report.
            if (! $categoryId && ! $productId) {
                $pl = $this->reports->getProfitLossReport(Carbon::parse($start), Carbon::parse($end), true);
                $metrics = [
                    'gross_sales' => $pl['revenue']['gross_sales'],
                    'discounts' => $pl['revenue']['discounts'],
                    'net_sales' => $pl['revenue']['net_revenue'],
                    'refunds' => abs((float) DB::table('financial_transactions')->where('type', 'payment')->where('amount', '<', 0)->whereBetween('transacted_at', [Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay()])->sum('amount')),
                    'cogs' => $pl['cogs']['total'],
                    'order_count' => $pl['revenue']['order_count'],
                ];
            }
            $salesBase = round($metrics['net_sales'], 2);

            $scoped = $categoryId || $productId;
            $detail = $this->profitDetail($start, $end, $categoryId, $productId, $metrics);
            $base = $detail['closing_balance'];
            $baseLabel = $scoped ? 'Estimated gross profit' : 'Available closing balance';

            $profitBase = $detail['net_profit'];

            $incentive = $this->incentive->compute($start, $end, $metrics, $base, $basis);
            $overBudget = round($incentive['total'] * 100) > round(max(0, $base) * 100);
            $dividendBase = round(max(0, $base - $incentive['total']), 2);
            $distributable = $dividendBase;
            $alloc = $this->shares->allocate($distributable, $shareholderId);

            return [
                'basis' => $basis,
                'can_snapshot' => ! $scoped && ! $shareholderId && ! $overBudget,
                'over_budget' => $overBudget,
                'base_label' => $baseLabel,
                'range' => ['start' => $start, 'end' => $end],
                'metrics' => $metrics,
                'base_amount' => $base,
                'incentive_pool' => $incentive['total'],
                'distributable' => $distributable,
                'members' => $alloc['members'],
                'members_total' => $alloc['members_total'],
                'members_percentage' => $alloc['members_percentage'],
                'company_amount' => $alloc['company_amount'],
                'company_percentage' => $alloc['company_percentage'],
                'incentive' => $incentive,
                'chart' => $this->chartData($alloc),
                'financial_summary' => [
                    'gross_sales' => $metrics['gross_sales'],
                    'net_sales' => $metrics['net_sales'],
                    'refunds' => $metrics['refunds'],
                    'cogs' => $metrics['cogs'],
                    'income_adjustments' => $detail['income_adjustments'],
                    'expenses' => $detail['expenses'],
                    'payroll' => $detail['payroll'],
                    'previous_payouts' => $detail['previous_payouts'],
                    'asset_deductions' => $detail['asset_deductions'],
                    'opening_balance' => $detail['opening_balance'],
                    'closing_balance' => $detail['closing_balance'],
                    'calculation' => $scoped ? 'scoped_estimate' : 'financial_cash',
                    'sales_base' => $salesBase,
                    'net_profit' => $profitBase,
                    'period_end' => $end,
                ],
            ];
        });
    }

    public static function bumpCacheVersion(): void
    {
        Cache::add(self::VERSION_KEY, 0);
        Cache::increment(self::VERSION_KEY);
    }

    private function profitDetail(string $start, string $end, ?int $categoryId, ?int $productId, array $metrics): array
    {
        if ($categoryId || $productId) {
            return [
                'net_profit' => round($metrics['net_sales'] - $metrics['cogs'], 2),
                'opening_balance' => 0.0,
                'closing_balance' => round($metrics['net_sales'] - $metrics['cogs'], 2),
                'income_adjustments' => 0.0,
                'expenses' => 0.0,
                'payroll' => 0.0,
                'previous_payouts' => 0.0,
                'asset_deductions' => 0.0,
            ];
        }

        // Keep all cash expenses (including inventory purchases). COGS is informational
        // here; deducting it as well would mix cash movement with accrual costs.
        $pl = $this->reports->getProfitLossReport(Carbon::parse($start), Carbon::parse($end), false);
        $assets = (float) DB::table('financial_transactions')->where('type', 'asset_deduction')
            ->whereBetween('transacted_at', [Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay()])->sum('amount');
        // Same brought-forward ledger balance as Financial, including earlier payouts.
        $opening = (float) (DB::table('financial_transactions')->where('type', '!=', 'order')
            ->whereDate('transacted_at', '<', $start)
            ->selectRaw("SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE -amount END) as balance")
            ->value('balance') ?? 0);
        $net = round((float) ($pl['net_profit'] ?? 0) - $assets, 2);

        return [
            'net_profit' => $net,
            'opening_balance' => round($opening, 2),
            'closing_balance' => round($opening + $net, 2),
            'asset_deductions' => round($assets, 2),
            'income_adjustments' => round((float) ($pl['income_adjustments']['total'] ?? 0), 2),
            'expenses' => round((float) ($pl['expenses']['total'] ?? 0), 2),
            'payroll' => round((float) ($pl['payroll']['total'] ?? 0), 2),
            'previous_payouts' => round((float) ($pl['payout_share']['total'] ?? 0), 2),
        ];
    }

    private function chartData(array $alloc): array
    {
        $data = [];
        foreach ($alloc['members'] as $m) {
            $data[] = ['label' => $m['name'], 'value' => $m['amount'], 'type' => 'member'];
        }
        $data[] = ['label' => 'Company', 'value' => $alloc['company_amount'], 'type' => 'company'];

        return $data;
    }

    public function snapshot(array $result, ?array $filters = null): DistributionSnapshot
    {
        if (! ($result['can_snapshot'] ?? false)) {
            throw ValidationException::withMessages(['distribution' => 'Clear filters and ensure incentives do not exceed available profit before saving.']);
        }
        // Snapshot the combined entitlement displayed in the report, including incentives.
        $members = collect($result['members'])->keyBy('shareholder_id')->all();
        foreach ($result['incentive']['by_shareholder'] as $incentive) {
            $id = $incentive['shareholder_id'];
            $members[$id] ??= ['shareholder_id' => $id, 'name' => $incentive['name'], 'percentage' => 0, 'amount' => 0];
            $members[$id]['amount'] = round($members[$id]['amount'] + $incentive['incentive_amount'], 2);
        }
        $result['members'] = array_values($members);
        $result['members_total'] = round(array_sum(array_column($members, 'amount')), 2);
        $result['company_amount'] = round($result['company_amount'] + $result['incentive']['company_retained'], 2);
        $result['distributable'] = round($result['members_total'] + $result['company_amount'], 2);

        return DB::transaction(function () use ($result, $filters) {
            $snap = DistributionSnapshot::create([
                'period_start' => $result['range']['start'],
                'period_end' => $result['range']['end'],
                'distribution_basis' => $result['basis'],
                'gross_amount' => $result['metrics']['gross_sales'],
                'refunds_amount' => $result['metrics']['refunds'],
                'cogs_amount' => $result['metrics']['cogs'],
                'expenses_amount' => $result['financial_summary']['expenses'] + $result['financial_summary']['payroll'],
                'royalty_amount' => 0,
                'distributable_amount' => $result['distributable'],
                'members_amount' => $result['members_total'],
                'company_amount' => $result['company_amount'],
                'filters_applied' => array_merge($filters ?? [], [
                    'calculation' => 'financial_closing_balance', 'include_asset_deductions' => true,
                    'opening_balance' => $result['financial_summary']['opening_balance'] ?? 0,
                    'period_net' => $result['financial_summary']['net_profit'] ?? 0,
                ]),
                'created_by' => auth()->id(),
            ]);

            foreach ($result['members'] as $m) {
                DistributionSnapshotDetail::create([
                    'snapshot_id' => $snap->id,
                    'recipient_type' => 'shareholder',
                    'shareholder_id' => $m['shareholder_id'],
                    'recipient_name' => $m['name'],
                    'percentage' => $m['percentage'],
                    'amount' => $m['amount'],
                ]);
            }
            DistributionSnapshotDetail::create([
                'snapshot_id' => $snap->id,
                'recipient_type' => 'company',
                'shareholder_id' => null,
                'recipient_name' => 'Company Retained Earnings',
                'percentage' => $result['company_percentage'],
                'amount' => $result['company_amount'],
            ]);

            return $snap->load('details');
        });
    }

    /** Monthly distribution trend over a date range (members / company / incentive). */
    public function trend(string $basis, string $start, string $end): array
    {
        $cursor = Carbon::parse($start)->startOfMonth();
        $last = Carbon::parse($end)->startOfMonth();
        $out = [];

        while ($cursor <= $last) {
            $mStart = max($start, $cursor->copy()->startOfMonth()->toDateString());
            $mEnd = min($end, $cursor->copy()->endOfMonth()->toDateString());
            $r = $this->compute($basis, $mStart, $mEnd);
            $out[] = [
                'month' => $cursor->format('M Y'),
                'members' => $r['members_total'],
                'company' => $r['company_amount'],
                'incentive' => $r['incentive']['total'],
                'by_member' => collect($r['members'])->mapWithKeys(fn ($m) => [$m['name'] => $m['amount']])->all(),
            ];
            $cursor->addMonth();
        }

        return $out;
    }
}
