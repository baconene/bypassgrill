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
    public function __construct(
        private SalesAggregateService    $sales,
        private ShareDistributionService $shares,
        private ReportService            $reports,
        private IncentivePoolService     $incentive,
    ) {}

    /**
     * Compute a live distribution preview.
     *
     * @param string $basis 'sales' | 'profit'
     */
    public function compute(string $basis, string $start, string $end, ?int $categoryId = null, ?int $productId = null, ?int $shareholderId = null): array
    {
        $key = 'dist:' . md5(implode('|', [$basis, $start, $end, $categoryId, $productId, $shareholderId]));

        return Cache::remember($key, 300, function () use ($basis, $start, $end, $categoryId, $productId, $shareholderId) {
            $metrics   = $this->sales->salesMetrics($start, $end, $categoryId, $productId);
            $salesBase = round($metrics['net_sales'] - $metrics['refunds'], 2);

            if ($basis === 'profit') {
                $profitBase = $this->profitBase($start, $end, $categoryId, $productId, $metrics);
                $base       = $profitBase;
                $baseLabel  = $categoryId || $productId ? 'Gross Profit (scope)' : 'Net Profit';
            } else {
                // Sales basis: only compute profitBase for the financial summary card;
                // wrap in try-catch so a P&L failure never breaks the sales calculation.
                try {
                    $profitBase = $this->profitBase($start, $end, $categoryId, $productId, $metrics);
                } catch (\Throwable) {
                    $profitBase = 0.0;
                }
                $base      = $salesBase;
                $baseLabel = 'Net Sales';
            }

            $distributable = round(max(0, $base), 2);
            $alloc         = $this->shares->allocate($distributable, $shareholderId);

            // Incentive pool — computed independently, does not affect dividend/company split
            $incentive = $this->incentive->compute($start, $end, $metrics, $profitBase);

            return [
                'basis'              => $basis,
                'base_label'         => $baseLabel,
                'range'              => ['start' => $start, 'end' => $end],
                'metrics'            => $metrics,
                'base_amount'        => $base,
                'distributable'      => $distributable,
                'members'            => $alloc['members'],
                'members_total'      => $alloc['members_total'],
                'members_percentage' => $alloc['members_percentage'],
                'company_amount'     => $alloc['company_amount'],
                'company_percentage' => $alloc['company_percentage'],
                'incentive'          => $incentive,
                'chart'              => $this->chartData($alloc),
                'financial_summary'  => [
                    'gross_sales' => $metrics['gross_sales'],
                    'net_sales'   => $metrics['net_sales'],
                    'refunds'     => $metrics['refunds'],
                    'cogs'        => $metrics['cogs'],
                    'sales_base'  => $salesBase,
                    'net_profit'  => $profitBase,
                    'period_end'  => $end,
                ],
            ];
        });
    }

    private function profitBase(string $start, string $end, ?int $categoryId, ?int $productId, array $metrics): float
    {
        if ($categoryId || $productId) {
            return round($metrics['net_sales'] - $metrics['cogs'], 2);
        }

        $pl = $this->reports->getProfitLossReport(Carbon::parse($start), Carbon::parse($end), true);
        return round((float) ($pl['net_profit'] ?? 0), 2);
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
                'royalty_amount'       => 0,
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

    /** Monthly distribution trend over a date range (members / company / incentive). */
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
                'month'     => $cursor->format('M Y'),
                'members'   => $r['members_total'],
                'company'   => $r['company_amount'],
                'incentive' => $r['incentive']['total'],
                'by_member' => collect($r['members'])->mapWithKeys(fn ($m) => [$m['name'] => $m['amount']])->all(),
            ];
            $cursor->addMonth();
        }

        return $out;
    }
}
