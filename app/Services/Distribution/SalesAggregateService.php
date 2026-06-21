<?php

namespace App\Services\Distribution;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SalesAggregateService
{
    private function bounds(string $start, string $end): array
    {
        return [
            Carbon::parse($start, 'Asia/Manila')->startOfDay(),
            Carbon::parse($end, 'Asia/Manila')->endOfDay(),
        ];
    }

    public function salesMetrics(string $start, string $end, ?int $categoryId = null, ?int $productId = null): array
    {
        [$from, $to] = $this->bounds($start, $end);
        $scoped = $categoryId || $productId;

        if (! $scoped) {
            $rev = DB::table('orders')
                ->where('payment_status', 'paid')
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as gross, COALESCE(SUM(discount_amount),0) as disc')
                ->first();

            $gross = (float) $rev->gross;
            $disc  = (float) $rev->disc;

            $refunds = (float) DB::table('refunds')
                ->join('payments', 'payments.id', '=', 'refunds.payment_id')
                ->join('orders', 'orders.id', '=', 'payments.order_id')
                ->where('refunds.status', 'completed')
                ->whereBetween('orders.created_at', [$from, $to])
                ->sum('refunds.amount');

            $cogs = (float) DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.payment_status', 'paid')
                ->whereBetween('orders.created_at', [$from, $to])
                ->sum('order_items.cost_subtotal');

            return [
                'gross_sales' => round($gross, 2),
                'discounts'   => round($disc, 2),
                'net_sales'   => round($gross - $disc, 2),
                'refunds'     => round($refunds, 2),
                'cogs'        => round($cogs, 2),
                'order_count' => (int) $rev->cnt,
            ];
        }

        $base = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.payment_status', 'paid')
            ->whereBetween('orders.created_at', [$from, $to])
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->when($productId, fn ($q) => $q->where('order_items.product_id', $productId));

        $row = (clone $base)
            ->selectRaw('COUNT(DISTINCT orders.id) as cnt, COALESCE(SUM(order_items.subtotal),0) as sales, COALESCE(SUM(order_items.cost_subtotal),0) as cogs')
            ->first();

        $sales = (float) $row->sales;

        return [
            'gross_sales' => round($sales, 2),
            'discounts'   => 0.0,
            'net_sales'   => round($sales, 2),
            'refunds'     => 0.0,
            'cogs'        => round((float) $row->cogs, 2),
            'order_count' => (int) $row->cnt,
        ];
    }

    public function productNetSales(string $start, string $end, ?int $categoryId = null, ?int $productId = null): array
    {
        [$from, $to] = $this->bounds($start, $end);

        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.payment_status', 'paid')
            ->whereBetween('orders.created_at', [$from, $to])
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->when($productId, fn ($q) => $q->where('order_items.product_id', $productId))
            ->groupBy('products.id', 'products.category_id', 'products.name')
            ->selectRaw('products.id as product_id, products.category_id, products.name, SUM(order_items.subtotal) as net_sales, SUM(order_items.quantity) as qty')
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'category_id'=> (int) $r->category_id,
                'name'       => $r->name,
                'net_sales'  => round((float) $r->net_sales, 2),
                'qty'        => (int) $r->qty,
            ])
            ->all();
    }
}
