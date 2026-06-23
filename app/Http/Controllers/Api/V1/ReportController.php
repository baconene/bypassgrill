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

    private function checkPermission(): void
    {
        if (! auth()->user()?->hasAnyRole('admin') && ! auth()->user()?->hasPermissionTo('view reports')) {
            abort(403, 'Unauthorized');
        }
    }
}
