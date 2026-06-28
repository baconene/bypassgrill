<?php

namespace App\Services\Distribution;

use App\Models\ProductOwnership;
use App\Models\Shareholder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class IncentivePoolService
{
    /**
     * Compute sales incentives directly from product sales × ownership %.
     * Each shareholder earns their ownership share of each product's revenue.
     * No pool rules involved — the incentive is purely sales-attribution-based.
     */
    public function compute(string $start, string $end, array $metrics, float $netProfit = 0.0): array
    {
        [$from, $to] = $this->bounds($start, $end);

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

        // Fill contribution_pct for owned products (% of total owned-product sales)
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

    private function bounds(string $start, string $end): array
    {
        return [
            Carbon::parse($start, 'Asia/Manila')->startOfDay(),
            Carbon::parse($end, 'Asia/Manila')->endOfDay(),
        ];
    }
}
