<?php

namespace App\Services;

use App\Models\Order;
use Carbon\Carbon;

class ReportService
{
    public function getDailySalesReport(Carbon $date = null): array
    {
        $date ??= Carbon::today();

        // Revenue from payment transactions using the same DATE(transacted_at) grouping
        // the daily chart uses, so the two numbers always match for the same date.
        $paymentTxs = \App\Models\FinancialTransaction::where('type', 'payment')
            ->whereDate('transacted_at', $date->toDateString())
            ->get(['order_id', 'amount']);

        $totalRevenue = (float) $paymentTxs->sum('amount');
        $orderIds     = $paymentTxs->whereNotNull('order_id')->pluck('order_id')->unique()->values();
        $orders       = Order::whereIn('id', $orderIds)->get();

        return [
            'date'           => $date->toDateString(),
            'total_orders'   => $orderIds->count(),
            'total_sales'    => $totalRevenue,
            'total_discount' => (float) $orders->sum('discount_amount'),
            'total_tax'      => (float) $orders->sum('tax_amount'),
            'orders'         => $orders,
        ];
    }

    public function getMonthlySalesReport(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1);
        $end   = $start->copy()->endOfMonth();

        $orders = Order::whereBetween('created_at', [$start, $end])
            ->where('payment_status', 'paid')
            ->get();

        return [
            'month'          => $start->format('Y-m'),
            'total_orders'   => $orders->count(),
            'total_sales'    => $orders->sum('total_amount'),
            'total_discount' => $orders->sum('discount_amount'),
            'total_tax'      => $orders->sum('tax_amount'),
        ];
    }

    public function getProductSalesReport(Carbon $startDate = null, Carbon $endDate = null)
    {
        $startDate ??= Carbon::now()->startOfMonth();
        $endDate ??= Carbon::now()->endOfMonth();

        return \App\Models\OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('order_items.created_at', [$startDate, $endDate])
            ->selectRaw('order_items.product_id, products.name as product_name, SUM(order_items.quantity) as total_quantity, SUM(order_items.subtotal) as total_sales')
            ->groupBy('order_items.product_id', 'products.name')
            ->orderByDesc('total_sales')
            ->get();
    }

    public function getInventoryValuation()
    {
        return \App\Models\Ingredient::where('is_active', true)
            ->selectRaw('*, (current_quantity) as valuation')
            ->get();
    }

    public function getProfitLossReport(Carbon $start, Carbon $end, bool $includeCogs = true): array
    {
        // Revenue: sum of payment transactions in the period.
        // Using FinancialTransaction as the source (same as the chart) so P&L revenue
        // always matches chart income for the same date range.
        $paymentOrderIds = \App\Models\FinancialTransaction::where('type', 'payment')
            ->whereBetween('transacted_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->whereNotNull('order_id')
            ->pluck('order_id')
            ->unique();

        $orderStats = \App\Models\Order::whereIn('id', $paymentOrderIds)
            ->where('payment_status', 'paid')
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(subtotal), 0) as gross_sales, COALESCE(SUM(discount_amount), 0) as discounts')
            ->first();

        $netRevenue     = (float) \App\Models\FinancialTransaction::where('type', 'payment')
            ->whereBetween('transacted_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->sum('amount');
        $grossSales     = (float) ($orderStats->gross_sales ?? 0); // pre-discount subtotals
        $discounts      = (float) ($orderStats->discounts   ?? 0);
        $paidOrderCount = (int)   ($orderStats->order_count ?? 0);

        // COGS: sum of cost_subtotal on items from the same paid orders
        $cogs = (float) \App\Models\OrderItem::whereIn('order_id', $paymentOrderIds)->sum('cost_subtotal');

        // Completed orders that aren't fully paid yet — their revenue is NOT counted
        // in profit (profit recognises paid orders only). Surfaced so this excluded
        // revenue is visible instead of silently missing.
        $unpaidCompleted = \App\Models\Order::whereBetween('created_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->where('status', 'completed')
            ->where('payment_status', '!=', 'paid')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as total')
            ->first();

        // Adjust COGS based on toggle: if includeCogs is false, don't deduct it from gross profit
        $deductedCogs = $includeCogs ? $cogs : 0;
        $grossProfit  = $netRevenue - $deductedCogs;
        $grossMargin  = $netRevenue > 0 ? round(($grossProfit / $netRevenue) * 100, 2) : 0;

        // ── Operating expenses ────────────────────────────────────────────────
        // When COGS is ON, inventory restock costs are asset purchases (Cash → Inventory)
        // whose consumption is already captured by COGS from order item costs. Excluding
        // them here prevents double-counting. When COGS is OFF they act as the cost proxy.
        $expenseBase = \App\Models\FinancialTransaction::where('type', 'expense')
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->copy()->endOfDay()]);

        if ($includeCogs) {
            $expenseBase->where('description', 'not like', 'Inventory Stock In%');
        }

        $expenseRows   = (clone $expenseBase)->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as count')->first();
        $totalExpenses = (float) ($expenseRows->total ?? 0);
        $expenseCount  = (int)   ($expenseRows->count ?? 0);

        $expenseBreakdown = (clone $expenseBase)
            ->orderByDesc('transacted_at')
            ->get(['description', 'amount', 'transacted_at'])
            ->map(fn ($e) => [
                'description'   => $e->description,
                'amount'        => (float) $e->amount,
                'transacted_at' => $e->transacted_at,
            ]);

        // ── Inventory purchases (separate line, NOT in operating expenses) ────
        // Always shown for transparency. When COGS is ON these are asset movements
        // (Cash → Inventory) that the system neutralises; their cost reappears as
        // COGS when the goods are sold. When COGS is OFF they appear inside opex.
        $invPurchaseRows = \App\Models\FinancialTransaction::where('type', 'expense')
            ->where('description', 'like', 'Inventory Stock In%')
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->copy()->endOfDay()])
            ->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as count')
            ->first();

        $totalInvPurchases = (float) ($invPurchaseRows->total ?? 0);

        $invPurchaseBreakdown = \App\Models\FinancialTransaction::where('type', 'expense')
            ->where('description', 'like', 'Inventory Stock In%')
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->copy()->endOfDay()])
            ->orderByDesc('transacted_at')
            ->get(['description', 'amount', 'transacted_at'])
            ->map(fn ($e) => [
                'description'   => $e->description,
                'amount'        => (float) $e->amount,
                'transacted_at' => $e->transacted_at,
            ]);

        // Income adjustments (credit entries recorded manually)
        $incomeAdjRows = \App\Models\FinancialTransaction::where('type', 'income_adjustment')
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->copy()->endOfDay()])
            ->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as count')
            ->first();

        $totalIncomeAdj = (float) ($incomeAdjRows->total ?? 0);

        $incomeAdjBreakdown = \App\Models\FinancialTransaction::where('type', 'income_adjustment')
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->copy()->endOfDay()])
            ->orderByDesc('transacted_at')
            ->get(['description', 'amount', 'transacted_at'])
            ->map(fn ($e) => [
                'description'   => $e->description,
                'amount'        => (float) $e->amount,
                'transacted_at' => $e->transacted_at,
            ]);

        // Payroll disbursements
        $payrollRows = \App\Models\FinancialTransaction::where('type', 'payroll')
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->copy()->endOfDay()])
            ->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as count')
            ->first();

        $totalPayroll = (float) ($payrollRows->total ?? 0);

        $payrollBreakdown = \App\Models\FinancialTransaction::where('type', 'payroll')
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->copy()->endOfDay()])
            ->orderByDesc('transacted_at')
            ->get(['description', 'amount', 'transacted_at'])
            ->map(fn ($e) => [
                'description'   => $e->description,
                'amount'        => (float) $e->amount,
                'transacted_at' => $e->transacted_at,
            ]);

        $netProfit           = $grossProfit + $totalIncomeAdj - $totalExpenses - $totalPayroll;
        $totalRevenuePlusAdj = $netRevenue + $totalIncomeAdj;
        $netMargin           = $totalRevenuePlusAdj > 0 ? round(($netProfit / $totalRevenuePlusAdj) * 100, 2) : 0;

        $hasCogs = $cogs > 0;

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end'   => $end->toDateString(),
            ],
            'revenue' => [
                'order_count' => $paidOrderCount,
                'gross_sales' => $grossSales,
                'discounts'   => $discounts,
                'net_revenue' => $netRevenue,
            ],
            'cogs' => [
                'total'    => $cogs,
                'has_data' => $hasCogs,
            ],
            'gross_profit' => $grossProfit,
            'gross_margin' => $grossMargin,
            'income_adjustments' => [
                'total'     => $totalIncomeAdj,
                'count'     => (int) ($incomeAdjRows->count ?? 0),
                'breakdown' => $incomeAdjBreakdown,
            ],
            'expenses' => [
                'total'     => $totalExpenses,
                'count'     => $expenseCount,
                'breakdown' => $expenseBreakdown,
            ],
            // Inventory purchases are shown separately.
            // When COGS is ON : these are asset movements (not opex); cost flows via COGS.
            // When COGS is OFF: these are already included inside 'expenses' above.
            'inventory_purchases' => [
                'total'               => $totalInvPurchases,
                'count'               => (int) ($invPurchaseRows->count ?? 0),
                'included_in_expenses' => ! $includeCogs,  // tells the UI where they appear
                'breakdown'           => $invPurchaseBreakdown,
            ],
            'payroll' => [
                'total'     => $totalPayroll,
                'count'     => (int) ($payrollRows->count ?? 0),
                'breakdown' => $payrollBreakdown,
            ],
            'net_profit'   => $netProfit,
            'net_margin'   => $netMargin,
            'include_cogs' => $includeCogs,
            // Completed-but-unpaid revenue excluded from profit (recognised on payment).
            'unpaid_completed' => [
                'total' => (float) ($unpaidCompleted->total ?? 0),
                'count' => (int) ($unpaidCompleted->cnt ?? 0),
            ],
        ];
    }
}
