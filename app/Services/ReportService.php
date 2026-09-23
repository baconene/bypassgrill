<?php

namespace App\Services;

use App\Enums\InventoryCostKind;
use App\Models\FinancialTransaction;
use App\Models\Ingredient;
use App\Models\InventoryCostEntry;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Expense descriptions written by the inventory code when stock is bought: stock in,
     * a new item's starting stock, and an upward count adjustment. With COGS on, their
     * cost reaches profit through COGS when sold, so they are purchases, not opex.
     */
    public const INVENTORY_PURCHASE_PATTERNS = ['Inventory Stock In%', 'Initial stock:%', 'Inventory Adjustment:%'];

    public static function whereInventoryPurchase($query)
    {
        foreach (self::INVENTORY_PURCHASE_PATTERNS as $pattern) {
            $query->orWhere('description', 'like', $pattern);
        }

        return $query;
    }

    public function getDailySalesReport(?Carbon $date = null): array
    {
        $date ??= Carbon::today();

        // Revenue from payment transactions using the same DATE(transacted_at) grouping
        // the daily chart uses, so the two numbers always match for the same date.
        $paymentTxs = FinancialTransaction::where('type', 'payment')
            ->whereDate('transacted_at', $date->toDateString())
            ->get(['order_id', 'amount']);

        $totalRevenue = (float) $paymentTxs->sum('amount');
        $orderIds = $paymentTxs->whereNotNull('order_id')->pluck('order_id')->unique()->values();
        $orders = Order::whereIn('id', $orderIds)->get();

        return [
            'date' => $date->toDateString(),
            'total_orders' => $orderIds->count(),
            'total_sales' => $totalRevenue,
            'total_discount' => (float) $orders->sum('discount_amount'),
            'total_tax' => (float) $orders->sum('tax_amount'),
            'orders' => $orders,
        ];
    }

    public function getMonthlySalesReport(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1);
        $end = $start->copy()->endOfMonth();

        $orders = Order::whereBetween('created_at', [$start, $end])
            ->where('payment_status', 'paid')
            ->get();

        return [
            'month' => $start->format('Y-m'),
            'total_orders' => $orders->count(),
            'total_sales' => $orders->sum('total_amount'),
            'total_discount' => $orders->sum('discount_amount'),
            'total_tax' => $orders->sum('tax_amount'),
        ];
    }

    public function getProductSalesReport(?Carbon $startDate = null, ?Carbon $endDate = null)
    {
        $startDate ??= Carbon::now()->startOfMonth();
        $endDate ??= Carbon::now()->endOfMonth();

        return OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('order_items.created_at', [$startDate, $endDate])
            ->selectRaw('order_items.product_id, products.name as product_name, SUM(order_items.quantity) as total_quantity, SUM(order_items.subtotal) as total_sales')
            ->groupBy('order_items.product_id', 'products.name')
            ->orderByDesc('total_sales')
            ->get();
    }

    public function getInventoryValuation()
    {
        return Ingredient::where('is_active', true)
            ->selectRaw('*, ROUND(current_quantity * cost_per_unit, 2) as valuation')
            ->get();
    }

    /**
     * Two bases, deliberately kept apart:
     *   accrual (default) - the profit-and-loss view. Stock is an asset when bought and
     *     a cost when used, so COGS comes from the ledger and inventory losses stand on
     *     their own line. This is what the Reports page and the dashboard show.
     *   cash - what actually left the tills. Inventory purchases stay inside operating
     *     expenses and neither COGS nor inventory losses are deducted. Profit sharing
     *     allocates cash, so it asks for this one.
     */
    public function getProfitLossReport(Carbon $start, Carbon $end, bool $accrualBasis = true): array
    {
        // Revenue: sum of payment transactions in the period.
        // Using FinancialTransaction as the source (same as the chart) so P&L revenue
        // always matches chart income for the same date range.
        $paymentOrderIds = FinancialTransaction::where('type', 'payment')
            ->whereBetween('transacted_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->whereNotNull('order_id')
            ->pluck('order_id')
            ->unique();

        $orderStats = Order::whereIn('id', $paymentOrderIds)
            ->where('payment_status', 'paid')
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(subtotal), 0) as gross_sales, COALESCE(SUM(discount_amount), 0) as discounts')
            ->first();

        $netRevenue = (float) FinancialTransaction::where('type', 'payment')
            ->whereBetween('transacted_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->sum('amount');
        $grossSales = (float) ($orderStats->gross_sales ?? 0); // pre-discount subtotals
        $discounts = (float) ($orderStats->discounts ?? 0);
        $paidOrderCount = (int) ($orderStats->order_count ?? 0);

        // COGS: the ledger owns orders created on or after the cutover; orders from before it
        // keep the cost recorded on their items. A period spanning the cutover adds both.
        $cutover = DB::table('cogs_ledger_settings')->value('cogs_ledger_start_at');
        $ledgerOrderIds = $cutover
            ? Order::whereIn('id', $paymentOrderIds)->where('created_at', '>=', $cutover)->pluck('id')
            : collect();
        $legacyOrderIds = $paymentOrderIds->diff($ledgerOrderIds);
        $ledgerCogs = $ledgerOrderIds->isEmpty() ? 0.0 : (float) InventoryCostEntry::whereIn('order_id', $ledgerOrderIds)
            ->whereIn('kind', [InventoryCostKind::CONSUMPTION->value, InventoryCostKind::CONSUMPTION_REVERSAL->value])
            ->sum('total_cost');
        $legacyCogs = $legacyOrderIds->isEmpty() ? 0.0 : (float) OrderItem::whereIn('order_id', $legacyOrderIds)->sum('cost_subtotal');
        $cogs = round($ledgerCogs + $legacyCogs, 2);

        // Completed orders that aren't fully paid yet — their revenue is NOT counted
        // in profit (profit recognises paid orders only). Surfaced so this excluded
        // revenue is visible instead of silently missing.
        $unpaidCompleted = Order::whereBetween('created_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->where('status', 'completed')
            ->where('payment_status', '!=', 'paid')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as total')
            ->first();

        // Cash basis leaves COGS out of gross profit; it reports money moved, not cost used.
        $deductedCogs = $accrualBasis ? $cogs : 0;
        $grossProfit = $netRevenue - $deductedCogs;
        $grossMargin = $netRevenue > 0 ? round(($grossProfit / $netRevenue) * 100, 2) : 0;

        // ── Operating expenses ────────────────────────────────────────────────
        // When COGS is ON, inventory restock costs are asset purchases (Cash → Inventory)
        // whose consumption is already captured by COGS from order item costs. Excluding
        // them here prevents double-counting. When COGS is OFF they act as the cost proxy.
        $expenseBase = FinancialTransaction::where('type', 'expense')
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->copy()->endOfDay()]);

        if ($accrualBasis) {
            $expenseBase->whereNot(fn ($q) => self::whereInventoryPurchase($q));
        }

        $expenseRows = (clone $expenseBase)->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as count')->first();
        $totalExpenses = (float) ($expenseRows->total ?? 0);
        $expenseCount = (int) ($expenseRows->count ?? 0);

        $expenseBreakdown = (clone $expenseBase)
            ->orderByDesc('transacted_at')
            ->get(['description', 'amount', 'transacted_at'])
            ->map(fn ($e) => [
                'description' => $e->description,
                'amount' => (float) $e->amount,
                'transacted_at' => $e->transacted_at,
            ]);

        // ── Inventory purchases (separate line, NOT in operating expenses) ────
        // Always shown for transparency. When COGS is ON these are asset movements
        // (Cash → Inventory) that the system neutralises; their cost reappears as
        // COGS when the goods are sold. When COGS is OFF they appear inside opex.
        $invPurchaseRows = FinancialTransaction::where('type', 'expense')
            ->where(fn ($q) => self::whereInventoryPurchase($q))
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->copy()->endOfDay()])
            ->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as count')
            ->first();

        $totalInvPurchases = (float) ($invPurchaseRows->total ?? 0);

        $invPurchaseBreakdown = FinancialTransaction::where('type', 'expense')
            ->where(fn ($q) => self::whereInventoryPurchase($q))
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->copy()->endOfDay()])
            ->orderByDesc('transacted_at')
            ->get(['description', 'amount', 'transacted_at'])
            ->map(fn ($e) => [
                'description' => $e->description,
                'amount' => (float) $e->amount,
                'transacted_at' => $e->transacted_at,
            ]);

        // Income adjustments (credit entries recorded manually)
        $incomeAdjRows = FinancialTransaction::where('type', 'income_adjustment')
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->copy()->endOfDay()])
            ->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as count')
            ->first();

        $totalIncomeAdj = (float) ($incomeAdjRows->total ?? 0);

        $incomeAdjBreakdown = FinancialTransaction::where('type', 'income_adjustment')
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->copy()->endOfDay()])
            ->orderByDesc('transacted_at')
            ->get(['description', 'amount', 'transacted_at'])
            ->map(fn ($e) => [
                'description' => $e->description,
                'amount' => (float) $e->amount,
                'transacted_at' => $e->transacted_at,
            ]);

        // Payroll disbursements
        $payrollRows = FinancialTransaction::where('type', 'payroll')
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->copy()->endOfDay()])
            ->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as count')
            ->first();

        $totalPayroll = (float) ($payrollRows->total ?? 0);

        $payrollBreakdown = FinancialTransaction::where('type', 'payroll')
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->copy()->endOfDay()])
            ->orderByDesc('transacted_at')
            ->get(['description', 'amount', 'transacted_at'])
            ->map(fn ($e) => [
                'description' => $e->description,
                'amount' => (float) $e->amount,
                'transacted_at' => $e->transacted_at,
            ]);

        // Inventory losses: stock used up without being sold, recognised on the date it
        // went missing rather than when it was bought. Never a cash movement.
        //
        // Gated on the cutover, like COGS. Entries written while the ledger was still in
        // shadow mode must not reach profit: setting the ledger up means correcting stock,
        // and those corrections are recorded as count losses. Shadow mode changes nothing.
        //
        // Count gains are deliberately left out. Finding stock is not income, and counting
        // it up is a way of adding inventory, which must leave profit at exactly zero.
        // Gains are still recorded in the ledger and shown in the Inventory reports tab.
        $lossKinds = [
            InventoryCostKind::WASTE->value,
            InventoryCostKind::COUNT_LOSS->value,
        ];
        $lossFrom = $cutover ? max(Carbon::parse($cutover), $start->copy()->startOfDay()) : null;
        $lossBase = InventoryCostEntry::whereIn('kind', $lossKinds)
            ->whereBetween('recognized_at', [$lossFrom ?? $start->copy()->startOfDay(), $end->copy()->endOfDay()]);

        $deductLosses = $accrualBasis && $cutover !== null;
        $totalInvLosses = $deductLosses ? round((float) (clone $lossBase)->sum('total_cost'), 2) : 0.0;
        $invLossCount = $deductLosses ? (clone $lossBase)->count() : 0;
        $invLossBreakdown = $deductLosses
            ? (clone $lossBase)->orderByDesc('recognized_at')
                ->get(['kind', 'ingredient_name', 'quantity', 'total_cost', 'recognized_at'])
                ->map(fn ($e) => [
                    'description' => trim(str_replace('_', ' ', $e->kind->value).': '.($e->ingredient_name ?? 'Item')),
                    'amount' => (float) $e->total_cost,
                    'transacted_at' => $e->recognized_at,
                ])
            : collect();

        // Profit distribution payouts (cash disbursed to shareholders)
        $payoutShareRows = FinancialTransaction::where('type', 'payout_share')
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->copy()->endOfDay()])
            ->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as count')
            ->first();

        $totalPayoutShare = (float) ($payoutShareRows->total ?? 0);

        $payoutShareBreakdown = FinancialTransaction::where('type', 'payout_share')
            ->whereBetween('transacted_at', [$start->startOfDay(), $end->copy()->endOfDay()])
            ->orderByDesc('transacted_at')
            ->get(['description', 'amount', 'transacted_at'])
            ->map(fn ($e) => [
                'description' => $e->description,
                'amount' => (float) $e->amount,
                'transacted_at' => $e->transacted_at,
            ]);

        $netProfit = $grossProfit + $totalIncomeAdj - $totalExpenses - $totalInvLosses - $totalPayroll - $totalPayoutShare;
        $totalRevenuePlusAdj = $netRevenue + $totalIncomeAdj;
        $netMargin = $totalRevenuePlusAdj > 0 ? round(($netProfit / $totalRevenuePlusAdj) * 100, 2) : 0;

        $hasCogs = $cogs > 0;

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'revenue' => [
                'order_count' => $paidOrderCount,
                'gross_sales' => $grossSales,
                'discounts' => $discounts,
                'net_revenue' => $netRevenue,
            ],
            'cogs' => [
                'total' => $cogs,
                'has_data' => $hasCogs,
            ],
            'gross_profit' => $grossProfit,
            'gross_margin' => $grossMargin,
            // Costs here sum to the COGS total above, so the statement can be read down
            // to a line and then across to the dishes that produced it.
            'product_margins' => $this->productMargins($paymentOrderIds, $ledgerOrderIds, $legacyOrderIds),
            'income_adjustments' => [
                'total' => $totalIncomeAdj,
                'count' => (int) ($incomeAdjRows->count ?? 0),
                'breakdown' => $incomeAdjBreakdown,
            ],
            'expenses' => [
                'total' => $totalExpenses,
                'count' => $expenseCount,
                'breakdown' => $expenseBreakdown,
            ],
            // Inventory purchases are shown separately.
            // When COGS is ON : these are asset movements (not opex); cost flows via COGS.
            // When COGS is OFF: these are already included inside 'expenses' above.
            'inventory_losses' => [
                'total' => $totalInvLosses,
                'count' => $invLossCount,
                'breakdown' => $invLossBreakdown,
            ],
            'inventory_purchases' => [
                'total' => $totalInvPurchases,
                'count' => (int) ($invPurchaseRows->count ?? 0),
                'included_in_expenses' => ! $accrualBasis,  // tells the UI where they appear
                'breakdown' => $invPurchaseBreakdown,
            ],
            'payroll' => [
                'total' => $totalPayroll,
                'count' => (int) ($payrollRows->count ?? 0),
                'breakdown' => $payrollBreakdown,
            ],
            'payout_share' => [
                'total' => $totalPayoutShare,
                'count' => (int) ($payoutShareRows->count ?? 0),
                'breakdown' => $payoutShareBreakdown,
            ],
            'net_profit' => $netProfit,
            'net_margin' => $netMargin,
            'include_cogs' => $accrualBasis,
            'accrual_basis' => $accrualBasis,
            // Completed-but-unpaid revenue excluded from profit (recognised on payment).
            'unpaid_completed' => [
                'total' => (float) ($unpaidCompleted->total ?? 0),
                'count' => (int) ($unpaidCompleted->cnt ?? 0),
            ],
        ];
    }

    /**
     * What each product sold for against what it cost, drawn from the same two sources
     * as the COGS figure above, so these costs add up to that total. Answers the
     * question the headline numbers cannot: which dishes are actually worth cooking.
     *
     * @param  Collection  $paymentOrderIds  paid orders in the period
     * @param  Collection  $ledgerOrderIds  the subset costed by the ledger
     * @param  Collection  $legacyOrderIds  the subset costed by their items
     */
    private function productMargins($paymentOrderIds, $ledgerOrderIds, $legacyOrderIds): array
    {
        if ($paymentOrderIds->isEmpty()) {
            return [];
        }

        $sold = OrderItem::whereIn('order_items.order_id', $paymentOrderIds)
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->selectRaw('order_items.product_id, products.name as product_name, SUM(order_items.quantity) as quantity, SUM(order_items.subtotal) as sales')
            ->groupBy('order_items.product_id', 'products.name')
            ->get();

        // Ledger rows carry the order item, so the product comes from the join.
        $ledgerCost = $ledgerOrderIds->isEmpty()
            ? collect()
            : InventoryCostEntry::whereIn('inventory_cost_entries.order_id', $ledgerOrderIds)
                ->whereIn('inventory_cost_entries.kind', [InventoryCostKind::CONSUMPTION->value, InventoryCostKind::CONSUMPTION_REVERSAL->value])
                ->join('order_items', 'inventory_cost_entries.order_item_id', '=', 'order_items.id')
                ->selectRaw('order_items.product_id, SUM(inventory_cost_entries.total_cost) as cost')
                ->groupBy('order_items.product_id')
                ->pluck('cost', 'product_id');

        $legacyCost = $legacyOrderIds->isEmpty()
            ? collect()
            : OrderItem::whereIn('order_id', $legacyOrderIds)
                ->selectRaw('product_id, SUM(cost_subtotal) as cost')
                ->groupBy('product_id')
                ->pluck('cost', 'product_id');

        return $sold->map(function ($row) use ($ledgerCost, $legacyCost) {
            $amount = (float) $row->sales;
            $cost = (float) ($ledgerCost[$row->product_id] ?? 0) + (float) ($legacyCost[$row->product_id] ?? 0);

            return [
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'quantity' => (float) $row->quantity,
                'sales' => round($amount, 2),
                'cost' => round($cost, 2),
                'gross_profit' => round($amount - $cost, 2),
                'margin' => $amount > 0 ? round((($amount - $cost) / $amount) * 100, 2) : 0.0,
            ];
        })->sortByDesc('gross_profit')->values()->all();
    }
}
