<?php
namespace App\Services\Distribution;

use App\Models\IncentiveRule;
use App\Models\Shareholder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Computes the sales incentive pool independently from the ownership dividend.
 * The pool is a business expense separate from retained earnings / dividend.
 */
class IncentivePoolService
{
    /**
     * @param  string $start     Date string (Y-m-d)
     * @param  string $end       Date string (Y-m-d)
     * @param  array  $metrics   Output of SalesAggregateService::salesMetrics()
     * @param  float  $netProfit Computed net profit for net_profit_pct rules
     */
    public function compute(string $start, string $end, array $metrics, float $netProfit = 0.0): array
    {
        $rules = IncentiveRule::effectiveDuring($start, $end)->orderBy('id')->get();

        if ($rules->isEmpty()) {
            return ['total' => 0.0, 'rules' => [], 'by_shareholder' => [], 'total_linked_sales' => 0.0];
        }

        $grossProfit = max(0, round($metrics['net_sales'] - $metrics['cogs'], 2));
        $totalPool   = 0.0;
        $ruleResults = [];

        foreach ($rules as $rule) {
            $pool = match ($rule->pool_type) {
                'gross_sales_pct'  => round($metrics['gross_sales'] * $rule->rate / 100, 2),
                'gross_profit_pct' => round($grossProfit * $rule->rate / 100, 2),
                'net_profit_pct'   => round(max(0, $netProfit) * $rule->rate / 100, 2),
                'fixed_amount'     => round((float) $rule->rate, 2),
            };
            $totalPool     = round($totalPool + $pool, 2);
            $ruleResults[] = [
                'id'                  => $rule->id,
                'name'                => $rule->name,
                'pool_type'           => $rule->pool_type,
                'rate'                => (float) $rule->rate,
                'distribution_method' => $rule->distribution_method,
                'pool_amount'         => $pool,
            ];
        }

        [$from, $to]     = $this->bounds($start, $end);
        $byShareholder   = $this->distribute($totalPool, $from, $to);
        $totalLinkedSales = (float) collect($byShareholder)->sum('sales_amount');

        return [
            'total'               => $totalPool,
            'rules'               => $ruleResults,
            'by_shareholder'      => $byShareholder,
            'total_linked_sales'  => round($totalLinkedSales, 2),
        ];
    }

    private function distribute(float $pool, $from, $to): array
    {
        $shareholders = Shareholder::active()
            ->whereNotNull('user_id')
            ->with('user:id,name')
            ->orderBy('name')
            ->get();

        if ($shareholders->isEmpty() || $pool <= 0) {
            return [];
        }

        $salesByUser = DB::table('orders')
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('user_id', $shareholders->pluck('user_id'))
            ->selectRaw('user_id, COALESCE(SUM(total_amount), 0) as total_sales')
            ->groupBy('user_id')
            ->pluck('total_sales', 'user_id');

        $totalSales = (float) $salesByUser->sum();

        return $shareholders->map(function ($s) use ($salesByUser, $totalSales, $pool) {
            $sales    = (float) ($salesByUser[$s->user_id] ?? 0);
            $pct      = $totalSales > 0 ? round($sales / $totalSales * 100, 2) : 0.0;
            $incentive = $totalSales > 0 ? round($sales / $totalSales * $pool, 2) : 0.0;

            return [
                'shareholder_id'  => $s->id,
                'name'            => $s->name,
                'user_name'       => $s->user?->name ?? '—',
                'sales_amount'    => $sales,
                'sales_pct'       => $pct,
                'incentive_amount' => $incentive,
            ];
        })->values()->all();
    }

    private function bounds(string $start, string $end): array
    {
        return [
            Carbon::parse($start, 'Asia/Manila')->startOfDay()->utc(),
            Carbon::parse($end, 'Asia/Manila')->endOfDay()->utc(),
        ];
    }
}
