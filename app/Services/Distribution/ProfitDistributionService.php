<?php

namespace App\Services\Distribution;

use App\Models\DistributionSnapshot;
use App\Models\DistributionSnapshotDetail;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProfitDistributionService
{
    private const VERSION_KEY = 'dist:cache_version';

    public function __construct(
        private SalesAggregateService    $sales,
        private ShareDistributionService $shares,
        private ReportService            $reports,
        private IncentivePoolService     $incentive,
    ) {}

    public function compute(string $basis, string $start, string $end, ?int $categoryId = null, ?int $productId = null, ?int $shareholderId = null): array
    {
        $ver = Cache::get(self::VERSION_KEY, 0);
        $key = 'dist:v' . $ver . ':' . md5(implode('|', [$basis, $start, $end, $categoryId, $productId, $shareholderId]));

        return Cache::remember($key, 300, function () use ($basis, $start, $end, $categoryId, $productId, $shareholderId) {
            $metrics   = $this->sales->salesMetrics($start, $end, $categoryId, $productId);
            $salesBase = round($metrics['net_sales'] - $metrics['refunds'], 2);

            $scoped = $categoryId || $productId;
            $zeroDetail = ['net_profit' => 0.0, 'income_adjustments' => 0.0, 'expenses' => 0.0, 'payroll' => 0.0];

            if ($basis === 'profit') {
                $detail    = $this->profitDetail($start, $end, $categoryId, $productId, $metrics);
                $base      = $detail['net_profit'];
                $baseLabel = $scoped ? 'Gross Profit (scope)' : 'Net Profit';
            } else {
                try {
                    $detail = $this->profitDetail($start, $end, $categoryId, $productId, $metrics);
                } catch (\Throwable) {
                    $detail = $zeroDetail;
                }
                $base      = $detail['net_profit'];
                $baseLabel = $scoped ? 'Gross Profit (scope)' : 'Net Profit';
            }

            $profitBase    = $detail['net_profit'];
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

    public static function bumpCacheVersion(): void
    {
        Cache::add(self::VERSION_KEY, 0);
        Cache::increment(self::VERSION_KEY);
    }

    private function profitDetail(string $start, string $end, ?int $categoryId, ?int $productId, array $metrics): array
    {
        if ($categoryId || $productId) {
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
