<?php

namespace App\Services\Distribution;

use App\Models\IncentiveRule;
use App\Models\ProductOwnership;
use App\Models\Shareholder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class IncentivePoolService
{
    public function compute(string $start, string $end, array $metrics, float $netProfit = 0.0, string $basis = 'profit'): array
    {
        [$from, $to] = $this->bounds($start, $end);

        // Sales mode: attribute product sales directly to shareholders by ownership % (no pool cap)
        if ($basis === 'sales') {
            return $this->computeFromProductSales($from, $to);
        }

        // Profit mode: use configured pool rules (original behaviour)
        return $this->computeFromPool($start, $end, $metrics, $netProfit, $from, $to);
    }

    // ── Sales mode ────────────────────────────────────────────────────────────

    private function computeFromProductSales($from, $to): array
    {
        $productSalesRows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.payment_status', 'paid')
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('products.id', 'products.name')
            ->selectRaw('products.id as product_id, products.name as product_name, COALESCE(SUM(order_items.subtotal), 0) as sales_amount')
            ->get();

        if ($productSalesRows->isEmpty()) {
            return ['total' => 0.0, 'rules' => [], 'by_product' => [], 'by_shareholder' => [], 'company_retained' => 0.0];
        }

        $allOwnerships = ProductOwnership::with('shareholder:id,name')
            ->whereIn('product_id', $productSalesRows->pluck('product_id'))
            ->get()
            ->groupBy('product_id');

        $shareholderTotals = [];
        $companyRetained   = 0.0;
        $totalOwnedSales   = 0.0;
        $byProduct         = [];

        foreach ($productSalesRows as $row) {
            $sales      = (float) $row->sales_amount;
            $productId  = (int) $row->product_id;
            $ownerships = $allOwnerships->get($productId);

            if (! $ownerships || $ownerships->isEmpty()) {
                $companyRetained = round($companyRetained + $sales, 2);
                $byProduct[] = [
                    'product_id'        => $productId,
                    'product_name'      => $row->product_name,
                    'sales_amount'      => round($sales, 2),
                    'contribution_pct'  => 0.0,
                    'product_incentive' => round($sales, 2),
                    'company_retained'  => round($sales, 2),
                    'owners'            => [],
                ];
            } else {
                $owners           = [];
                $productIncentive = 0.0;
                foreach ($ownerships as $ownership) {
                    $amount = round($sales * $ownership->ownership_percentage / 100, 2);
                    $sid    = (int) $ownership->shareholder_id;
                    $shareholderTotals[$sid] = round(($shareholderTotals[$sid] ?? 0.0) + $amount, 2);
                    $productIncentive        = round($productIncentive + $amount, 2);
                    $owners[] = [
                        'shareholder_id' => $sid,
                        'name'           => $ownership->shareholder->name,
                        'ownership_pct'  => (float) $ownership->ownership_percentage,
                        'amount'         => $amount,
                    ];
                }
                $totalOwnedSales = round($totalOwnedSales + $sales, 2);
                $byProduct[] = [
                    'product_id'        => $productId,
                    'product_name'      => $row->product_name,
                    'sales_amount'      => round($sales, 2),
                    'contribution_pct'  => 0.0,
                    'product_incentive' => $productIncentive,
                    'company_retained'  => 0.0,
                    'owners'            => $owners,
                ];
            }
        }

        if ($totalOwnedSales > 0) {
            foreach ($byProduct as &$p) {
                if ($p['company_retained'] === 0.0) {
                    $p['contribution_pct'] = round($p['sales_amount'] / $totalOwnedSales * 100, 2);
                }
            }
            unset($p);
        }

        usort($byProduct, fn ($a, $b) => $b['sales_amount'] <=> $a['sales_amount']);

        $byShareholder = [];
        if (! empty($shareholderTotals)) {
            $names = Shareholder::whereIn('id', array_keys($shareholderTotals))
                ->get(['id', 'name'])
                ->pluck('name', 'id');

            foreach ($shareholderTotals as $id => $amount) {
                $byShareholder[] = [
                    'shareholder_id'   => $id,
                    'name'             => $names[$id] ?? '—',
                    'incentive_amount' => $amount,
                ];
            }
            usort($byShareholder, fn ($a, $b) => $b['incentive_amount'] <=> $a['incentive_amount']);
        }

        return [
            'total'            => round((float) array_sum($shareholderTotals), 2),
            'rules'            => [],
            'by_product'       => $byProduct,
            'by_shareholder'   => $byShareholder,
            'company_retained' => $companyRetained,
        ];
    }

    // ── Profit mode (original pool rules behaviour) ───────────────────────────

    private function computeFromPool(string $start, string $end, array $metrics, float $netProfit, $from, $to): array
    {
        $rules = IncentiveRule::effectiveDuring($start, $end)->orderBy('id')->get();

        if ($rules->isEmpty()) {
            return [
                'total'           => 0.0,
                'rules'           => [],
                'by_product'      => [],
                'by_shareholder'  => [],
                'company_retained'=> 0.0,
            ];
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
                'id'         => $rule->id,
                'name'       => $rule->name,
                'pool_type'  => $rule->pool_type,
                'rate'       => (float) $rule->rate,
                'pool_amount'=> $pool,
            ];
        }

        $distributed = $this->distributePool($totalPool, $from, $to);

        return [
            'total'           => $totalPool,
            'rules'           => $ruleResults,
            'by_product'      => $distributed['by_product'],
            'by_shareholder'  => $distributed['by_shareholder'],
            'company_retained'=> $distributed['company_retained'],
        ];
    }

    private function distributePool(float $pool, $from, $to): array
    {
        if ($pool <= 0) {
            return ['by_product' => [], 'by_shareholder' => [], 'company_retained' => 0.0];
        }

        $productSalesRows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.payment_status', 'paid')
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('products.id', 'products.name')
            ->selectRaw('products.id as product_id, products.name as product_name, COALESCE(SUM(order_items.subtotal), 0) as sales_amount')
            ->get();

        $totalItemSales = (float) $productSalesRows->sum('sales_amount');

        if ($totalItemSales <= 0) {
            return ['by_product' => [], 'by_shareholder' => [], 'company_retained' => $pool];
        }

        $allOwnerships = ProductOwnership::with('shareholder:id,name')
            ->whereIn('product_id', $productSalesRows->pluck('product_id'))
            ->get()
            ->groupBy('product_id');

        $shareholderTotals = [];
        $companyRetained   = 0.0;
        $byProduct         = [];

        foreach ($productSalesRows as $row) {
            $sales            = (float) $row->sales_amount;
            $contributionPct  = round($sales / $totalItemSales * 100, 4);
            $productIncentive = round($sales / $totalItemSales * $pool, 2);
            $productId        = (int) $row->product_id;

            $ownerships = $allOwnerships->get($productId);

            if (! $ownerships || $ownerships->isEmpty()) {
                $companyRetained = round($companyRetained + $productIncentive, 2);
                $byProduct[] = [
                    'product_id'        => $productId,
                    'product_name'      => $row->product_name,
                    'sales_amount'      => round($sales, 2),
                    'contribution_pct'  => round($contributionPct, 2),
                    'product_incentive' => $productIncentive,
                    'company_retained'  => $productIncentive,
                    'owners'            => [],
                ];
            } else {
                $owners = [];
                foreach ($ownerships as $ownership) {
                    $amount = round($productIncentive * $ownership->ownership_percentage / 100, 2);
                    $sid    = (int) $ownership->shareholder_id;
                    $shareholderTotals[$sid] = round(($shareholderTotals[$sid] ?? 0.0) + $amount, 2);
                    $owners[] = [
                        'shareholder_id' => $sid,
                        'name'           => $ownership->shareholder->name,
                        'ownership_pct'  => (float) $ownership->ownership_percentage,
                        'amount'         => $amount,
                    ];
                }
                $byProduct[] = [
                    'product_id'        => $productId,
                    'product_name'      => $row->product_name,
                    'sales_amount'      => round($sales, 2),
                    'contribution_pct'  => round($contributionPct, 2),
                    'product_incentive' => $productIncentive,
                    'company_retained'  => 0.0,
                    'owners'            => $owners,
                ];
            }
        }

        usort($byProduct, fn ($a, $b) => $b['sales_amount'] <=> $a['sales_amount']);

        $byShareholder = [];
        if (! empty($shareholderTotals)) {
            $names = Shareholder::whereIn('id', array_keys($shareholderTotals))
                ->get(['id', 'name'])
                ->pluck('name', 'id');

            foreach ($shareholderTotals as $id => $amount) {
                $byShareholder[] = [
                    'shareholder_id'   => $id,
                    'name'             => $names[$id] ?? '—',
                    'incentive_amount' => $amount,
                ];
            }
            usort($byShareholder, fn ($a, $b) => $b['incentive_amount'] <=> $a['incentive_amount']);
        }

        return [
            'by_product'      => $byProduct,
            'by_shareholder'  => $byShareholder,
            'company_retained'=> $companyRetained,
        ];
    }

    private function bounds(string $start, string $end): array
    {
        return [
            Carbon::parse($start, 'Asia/Manila')->startOfDay(),
            Carbon::parse($end, 'Asia/Manila')->endOfDay(),
        ];
    }
}
