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

        $date = request()->input('date') ? Carbon::parse(request()->input('date')) : null;
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

        $startDate = request()->input('start_date') ? Carbon::parse(request()->input('start_date')) : null;
        $endDate = request()->input('end_date') ? Carbon::parse(request()->input('end_date')) : null;

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
            ? Carbon::parse(request()->input('start_date'))
            : Carbon::now()->startOfMonth();
        $end = request()->input('end_date')
            ? Carbon::parse(request()->input('end_date'))
            : Carbon::now()->endOfMonth();
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
             SUM(CASE WHEN type IN ('expense','payroll')           THEN amount ELSE 0 END) as expense"
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
             SUM(CASE WHEN type IN ('expense','payroll')           THEN amount ELSE 0 END) as expense"
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

    private function checkPermission(): void
    {
        if (! auth()->user()?->hasAnyRole('admin') && ! auth()->user()?->hasPermissionTo('view reports')) {
            abort(403, 'Unauthorized');
        }
    }
}
