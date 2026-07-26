<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(
        private ReportService $reportService,
        private AnalyticsService $analyticsService,
    ) {}

    public function analytics(): JsonResponse
    {
        $this->checkPermission();

        $start = request()->input('start_date', Carbon::now('Asia/Manila')->subDays(30)->toDateString());
        $end   = request()->input('end_date', Carbon::now('Asia/Manila')->toDateString());
        $cat   = request()->input('category_id');

        return response()->json(
            $this->analyticsService->bundle($start, $end, $cat ? (int) $cat : null)
        );
    }

    public function dailySales(): JsonResponse
    {
        $this->checkPermission();

        $date = request()->input('date')
            ? Carbon::parse(request()->input('date'), 'Asia/Manila')
            : null;
        $report = $this->reportService->getDailySalesReport($date);

        return response()->json($report);
    }

    public function monthlySales(): JsonResponse
    {
        $this->checkPermission();

        $year = request()->input('year', Carbon::now()->year);
        $month = request()->input('month', Carbon::now()->month);

        $report = $this->reportService->getMonthlySalesReport($year, $month);

        return response()->json($report);
    }

    public function productSales(): JsonResponse
    {
        $this->checkPermission();

        $startDate = request()->input('start_date')
            ? Carbon::parse(request()->input('start_date'), 'Asia/Manila')->startOfDay()
            : null;
        $endDate = request()->input('end_date')
            ? Carbon::parse(request()->input('end_date'), 'Asia/Manila')->endOfDay()
            : null;

        $report = $this->reportService->getProductSalesReport($startDate, $endDate);

        return response()->json($report);
    }

    public function productDailySales(): JsonResponse
    {
        $this->checkPermission();

        $productId = (int) request()->input('product_id');
        $startDate = request()->input('start_date')
            ? Carbon::parse(request()->input('start_date'), 'Asia/Manila')->startOfDay()
            : Carbon::now('Asia/Manila')->subDays(29)->startOfDay();
        $endDate = request()->input('end_date')
            ? Carbon::parse(request()->input('end_date'), 'Asia/Manila')->endOfDay()
            : Carbon::now('Asia/Manila')->endOfDay();

        $rows = \App\Models\OrderItem::where('order_items.product_id', $productId)
            ->whereBetween('order_items.created_at', [$startDate, $endDate])
            ->selectRaw('DATE(order_items.created_at) as date, SUM(order_items.quantity) as qty, SUM(order_items.subtotal) as sales')
            ->groupByRaw('DATE(order_items.created_at)')
            ->orderByRaw('DATE(order_items.created_at)')
            ->get()
            ->keyBy('date');

        $result = [];
        $cursor = $startDate->copy()->startOfDay();
        while ($cursor <= $endDate) {
            $date     = $cursor->toDateString();
            $result[] = [
                'date'  => $date,
                'qty'   => (int) ($rows[$date]?->qty   ?? 0),
                'sales' => round((float) ($rows[$date]?->sales ?? 0), 2),
            ];
            $cursor->addDay();
        }

        return response()->json($result);
    }

    public function inventoryValuation(): JsonResponse
    {
        $this->checkPermission();

        $report = $this->reportService->getInventoryValuation();

        return response()->json($report);
    }

    public function profitLoss(): JsonResponse
    {
        $this->checkPermission();

        $start = request()->input('start_date')
            ? Carbon::parse(request()->input('start_date'), 'Asia/Manila')
            : Carbon::now('Asia/Manila')->startOfMonth();
        $end = request()->input('end_date')
            ? Carbon::parse(request()->input('end_date'), 'Asia/Manila')
            : Carbon::now('Asia/Manila')->endOfMonth();
        $includeCogs = request()->boolean('include_cogs', true);

        return response()->json($this->reportService->getProfitLossReport($start, $end, $includeCogs));
    }

    public function inventoryTransactions(): JsonResponse
    {
        $this->checkPermission();

        $query = \App\Models\InventoryTransaction::with(['ingredient', 'user'])
            ->orderByDesc('created_at');

        if (request()->input('date_from')) {
            $query->whereDate('created_at', '>=', request()->input('date_from'));
        }
        if (request()->input('date_to')) {
            $query->whereDate('created_at', '<=', request()->input('date_to'));
        }
        if (request()->input('type')) {
            $query->where('type', request()->input('type'));
        }
        if (request()->input('ingredient_id')) {
            $query->where('ingredient_id', request()->input('ingredient_id'));
        }

        return response()->json($query->paginate(20));
    }

    public function monthlyChart(): JsonResponse
    {
        $this->checkPermission();

        $year = (int) request()->input('year', Carbon::now()->year);

        $rows = \App\Models\FinancialTransaction::selectRaw(
            "DATE_FORMAT(transacted_at, '%Y-%m') as month,
             SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE 0 END) as income,
             SUM(CASE WHEN type IN ('expense','payroll')
                      AND description NOT LIKE 'COGS:%'
                      AND description NOT LIKE 'Inventory Stock In%'
                 THEN amount ELSE 0 END) as expense"
        )
            ->where('type', '!=', 'order')
            ->whereYear('transacted_at', $year)
            ->groupByRaw("DATE_FORMAT(transacted_at, '%Y-%m')")
            ->orderByRaw("DATE_FORMAT(transacted_at, '%Y-%m')")
            ->get()
            ->keyBy('month');

        $currentMonth = Carbon::now()->year === $year ? Carbon::now()->month : 12;
        $result = [];
        for ($m = 1; $m <= $currentMonth; $m++) {
            $key      = sprintf('%d-%02d', $year, $m);
            $result[] = [
                'month'   => $key,
                'income'  => round((float) ($rows[$key]?->income  ?? 0), 2),
                'expense' => round((float) ($rows[$key]?->expense ?? 0), 2),
            ];
        }

        return response()->json($result);
    }

    public function dailyChart(): JsonResponse
    {
        $this->checkPermission();

        $days  = min(max((int) request()->input('days', 7), 1), 90);
        $end   = Carbon::today()->endOfDay();
        $start = Carbon::today()->subDays($days - 1)->startOfDay();

        $rows = \App\Models\FinancialTransaction::selectRaw(
            "DATE(transacted_at) as date,
             SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE 0 END) as income,
             SUM(CASE WHEN type IN ('expense','payroll')
                      AND description NOT LIKE 'COGS:%'
                      AND description NOT LIKE 'Inventory Stock In%'
                 THEN amount ELSE 0 END) as expense"
        )
            ->where('type', '!=', 'order')
            ->whereBetween('transacted_at', [$start, $end])
            ->groupByRaw('DATE(transacted_at)')
            ->orderByRaw('DATE(transacted_at)')
            ->get()
            ->keyBy('date');

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date     = Carbon::today()->subDays($i)->toDateString();
            $result[] = [
                'date'    => $date,
                'income'  => round((float) ($rows[$date]?->income  ?? 0), 2),
                'expense' => round((float) ($rows[$date]?->expense ?? 0), 2),
            ];
        }

        return response()->json($result);
    }

    public function ftBreakdown(): JsonResponse
    {
        $this->checkPermission();

        $start = Carbon::parse(request()->input('start_date', Carbon::today()->toDateString()))->startOfDay();
        $end   = Carbon::parse(request()->input('end_date',   Carbon::today()->toDateString()))->endOfDay();

        $byType = \App\Models\FinancialTransaction::selectRaw('type, SUM(amount) as total, COUNT(*) as count')
            ->whereBetween('transacted_at', [$start, $end])
            ->where('type', '!=', 'order')
            ->groupBy('type')
            ->get()
            ->map(fn ($r) => [
                'type'  => $r->type,
                'total' => round((float) $r->total, 2),
                'count' => (int) $r->count,
            ])
            ->values();

        $byTender = \App\Models\FinancialTransaction::whereBetween('transacted_at', [$start, $end])
            ->where('type', '!=', 'order')
            ->with('tender')
            ->selectRaw("payment_tender_id,
                SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE 0 END) as total_in,
                SUM(CASE WHEN type IN ('expense','payroll','asset_deduction','payout_share') THEN amount ELSE 0 END) as total_out,
                COUNT(*) as cnt")
            ->groupBy('payment_tender_id')
            ->get()
            ->map(fn ($r) => [
                'tender'    => $r->payment_tender_id ? ($r->tender?->name ?? 'Unknown') : 'Untagged',
                'total_in'  => round((float) $r->total_in,  2),
                'total_out' => round((float) $r->total_out, 2),
                'net'       => round((float) $r->total_in - (float) $r->total_out, 2),
                'count'     => (int) $r->cnt,
            ])
            ->sortByDesc('total_in')
            ->values();

        return response()->json([
            'period'    => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'by_type'   => $byType,
            'by_tender' => $byTender,
        ]);
    }

    public function heatmap(): JsonResponse
    {
        $this->checkPermission();

        $dateFrom = request()->input('date_from');
        $dateTo   = request()->input('date_to');

        $query = \App\Models\Order::where('payment_status', 'paid');

        if ($dateFrom) $query->whereDate('created_at', '>=', $dateFrom);
        if ($dateTo)   $query->whereDate('created_at', '<=', $dateTo);

        $rows = $query
            ->selectRaw('DAYOFWEEK(created_at) as dow, HOUR(created_at) as hr, COUNT(*) as orders')
            ->groupByRaw('DAYOFWEEK(created_at), HOUR(created_at)')
            ->orderByRaw('DAYOFWEEK(created_at), HOUR(created_at)')
            ->get();

        $dowMap = [1 => 'Sunday', 2 => 'Monday', 3 => 'Tuesday',
                   4 => 'Wednesday', 5 => 'Thursday', 6 => 'Friday', 7 => 'Saturday'];

        $lookup = [];
        foreach ($rows as $row) {
            $day = $dowMap[$row->dow] ?? 'Unknown';
            $lookup[$day][(int) $row->hr] = (int) $row->orders;
        }

        $orderedDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        $data       = [];
        $matrix     = [];
        $dayTotals  = array_fill_keys($orderedDays, 0);
        $hourTotals = array_fill(0, 24, 0);

        foreach ($orderedDays as $day) {
            $matrix[$day] = [];
            for ($h = 0; $h < 24; $h++) {
                $count = $lookup[$day][$h] ?? 0;
                $data[]        = ['day' => $day, 'hour' => $h, 'orders' => $count];
                $matrix[$day][$h] = $count;
                $dayTotals[$day]  += $count;
                $hourTotals[$h]   += $count;
            }
        }

        $maxOrders = 0;
        $peakSlot  = ['day' => null, 'hour' => 0, 'orders' => 0];
        foreach ($data as $slot) {
            if ($slot['orders'] > $maxOrders) {
                $maxOrders = $slot['orders'];
                $peakSlot  = $slot;
            }
        }

        $peakHourIdx = (int) array_search(max($hourTotals), $hourTotals);
        $peakDay     = (string) array_search(max($dayTotals), $dayTotals);

        return response()->json([
            'xAxis'  => 'hour',
            'yAxis'  => 'day',
            'data'   => $data,
            'matrix' => $matrix,
            'insights' => [
                'total_orders' => array_sum($hourTotals),
                'peak_slot'    => $peakSlot,
                'peak_hour'    => ['hour' => $peakHourIdx, 'total_orders' => $hourTotals[$peakHourIdx]],
                'peak_day'     => ['day'  => $peakDay,     'total_orders' => $dayTotals[$peakDay] ?? 0],
                'hour_totals'  => array_values($hourTotals),
                'day_totals'   => $dayTotals,
            ],
        ]);
    }

    public function servingTime(): JsonResponse
    {
        $this->checkPermission();

        $dateFrom = request()->input('date_from', Carbon::today()->subDays(29)->toDateString());
        $dateTo   = request()->input('date_to',   Carbon::today()->toDateString());

        $start = Carbon::parse($dateFrom)->startOfDay();
        $end   = Carbon::parse($dateTo)->endOfDay();

        $base = \App\Models\Order::where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereBetween('created_at', [$start, $end]);

        // Daily breakdown — fill every day in range even if zero orders
        $daily = (clone $base)
            ->selectRaw("DATE(created_at) as date,
                AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as avg_seconds,
                MIN(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as min_seconds,
                MAX(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as max_seconds,
                COUNT(*) as order_count")
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get()
            ->keyBy('date');

        $days = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $date = $d->toDateString();
            $row  = $daily[$date] ?? null;
            $days[] = [
                'date'        => $date,
                'avg_minutes' => $row ? round((float) $row->avg_seconds / 60, 2) : null,
                'min_minutes' => $row ? round((float) $row->min_seconds / 60, 2) : null,
                'max_minutes' => $row ? round((float) $row->max_seconds / 60, 2) : null,
                'count'       => $row ? (int) $row->order_count : 0,
            ];
        }

        // Breakdown by order type
        $byType = (clone $base)
            ->selectRaw("order_type,
                AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as avg_seconds,
                MIN(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as min_seconds,
                MAX(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as max_seconds,
                COUNT(*) as order_count")
            ->groupBy('order_type')
            ->get()
            ->map(fn ($r) => [
                'type'        => $r->order_type,
                'avg_minutes' => round((float) $r->avg_seconds / 60, 2),
                'min_minutes' => round((float) $r->min_seconds / 60, 2),
                'max_minutes' => round((float) $r->max_seconds / 60, 2),
                'count'       => (int) $r->order_count,
            ])
            ->values();

        // Distribution buckets
        $distRaw = (clone $base)
            ->selectRaw("
                CASE
                    WHEN TIMESTAMPDIFF(SECOND, created_at, completed_at) <  300  THEN '0-5 min'
                    WHEN TIMESTAMPDIFF(SECOND, created_at, completed_at) <  600  THEN '5-10 min'
                    WHEN TIMESTAMPDIFF(SECOND, created_at, completed_at) <  900  THEN '10-15 min'
                    WHEN TIMESTAMPDIFF(SECOND, created_at, completed_at) < 1200  THEN '15-20 min'
                    WHEN TIMESTAMPDIFF(SECOND, created_at, completed_at) < 1800  THEN '20-30 min'
                    ELSE '30+ min'
                END as bucket,
                COUNT(*) as count")
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $buckets = ['0-5 min', '5-10 min', '10-15 min', '15-20 min', '20-30 min', '30+ min'];
        $distribution = array_map(fn ($b) => [
            'bucket' => $b,
            'count'  => (int) ($distRaw[$b]?->count ?? 0),
        ], $buckets);

        // Overall summary
        $s = (clone $base)
            ->selectRaw("
                AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as avg_seconds,
                MIN(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as min_seconds,
                MAX(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as max_seconds,
                COUNT(*) as total_orders")
            ->first();

        return response()->json([
            'period'        => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'summary'       => [
                'avg_minutes'  => $s?->avg_seconds ? round((float) $s->avg_seconds / 60, 2) : null,
                'min_minutes'  => $s?->min_seconds ? round((float) $s->min_seconds / 60, 2) : null,
                'max_minutes'  => $s?->max_seconds ? round((float) $s->max_seconds / 60, 2) : null,
                'total_orders' => (int) ($s?->total_orders ?? 0),
            ],
            'daily'         => $days,
            'by_order_type' => $byType,
            'distribution'  => $distribution,
        ]);
    }

    public function servingTimeOrders(): JsonResponse
    {
        $this->checkPermission();

        $dateFrom = request()->input('date_from', Carbon::today()->subDays(29)->toDateString());
        $dateTo   = request()->input('date_to',   Carbon::today()->toDateString());
        $bucket   = request()->input('bucket');
        $sortBy   = request()->input('sort_by', 'serving_seconds');
        $sortDir  = request()->input('sort_dir', 'desc') === 'asc' ? 'ASC' : 'DESC';
        $perPage  = min(100, max(10, (int) request()->input('per_page', 50)));

        $start = Carbon::parse($dateFrom)->startOfDay();
        $end   = Carbon::parse($dateTo)->endOfDay();

        $query = \App\Models\Order::where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('id, order_type, customer_name, table_number, created_at, completed_at, total_amount,
                TIMESTAMPDIFF(SECOND, created_at, completed_at) as serving_seconds');

        $bucketRanges = [
            '0-5 min'   => [0,    300],
            '5-10 min'  => [300,  600],
            '10-15 min' => [600,  900],
            '15-20 min' => [900,  1200],
            '20-30 min' => [1200, 1800],
            '30+ min'   => [1800, null],
        ];

        if ($bucket && isset($bucketRanges[$bucket])) {
            [$minS, $maxS] = $bucketRanges[$bucket];
            $query->whereRaw('TIMESTAMPDIFF(SECOND, created_at, completed_at) >= ?', [$minS]);
            if ($maxS !== null) {
                $query->whereRaw('TIMESTAMPDIFF(SECOND, created_at, completed_at) < ?', [$maxS]);
            }
        }

        $allowed = ['serving_seconds', 'created_at', 'order_type', 'total_amount'];
        $sortBy  = in_array($sortBy, $allowed) ? $sortBy : 'serving_seconds';

        if ($sortBy === 'serving_seconds') {
            $query->orderByRaw("TIMESTAMPDIFF(SECOND, created_at, completed_at) $sortDir");
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        $paginated = $query->paginate($perPage)->through(fn ($r) => [
            'id'              => $r->id,
            'order_type'      => $r->order_type ?? 'unknown',
            'customer_name'   => $r->customer_name,
            'table_number'    => $r->table_number,
            'created_at'      => $r->created_at->toIso8601String(),
            'completed_at'    => $r->completed_at->toIso8601String(),
            'serving_seconds' => (int) $r->serving_seconds,
            'serving_minutes' => round((float) $r->serving_seconds / 60, 2),
            'total_amount'    => (float) $r->total_amount,
        ]);

        return response()->json($paginated);
    }

    public function updateOrderServingTime(\App\Models\Order $order): JsonResponse
    {
        $this->checkPermission();

        abort_if($order->status !== 'completed', 422, 'Only completed orders can have their serving time edited.');

        $minutes = (float) request()->input('serving_minutes');
        abort_if($minutes <= 0 || $minutes > 1440, 422, 'Serving time must be between 1 second and 24 hours.');

        $seconds     = (int) round($minutes * 60);
        $completedAt = $order->created_at->copy()->addSeconds($seconds);
        $order->update(['completed_at' => $completedAt]);

        return response()->json([
            'id'              => $order->id,
            'completed_at'    => $completedAt->toIso8601String(),
            'serving_seconds' => $seconds,
            'serving_minutes' => round($seconds / 60, 2),
        ]);
    }

    private function checkPermission(): void
    {
        if (! auth()->user()?->hasAnyRole('admin') && ! auth()->user()?->hasPermissionTo('view reports')) {
            abort(403, 'Unauthorized');
        }
    }
}
