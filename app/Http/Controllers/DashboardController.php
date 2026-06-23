<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\KitchenSetting;
use App\Models\Order;
use App\Services\InventoryService;
use App\Services\ReportService;
use Carbon\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService,
        private ReportService $reportService,
    ) {}

    public function index(): Response
    {
        $user = auth()->user();
        $stats = $this->buildStats($user);

        $pl = null;
        if ($user->hasAnyRole(['admin', 'auditor'])) {
            $pl = $this->buildMonthlyPl();
        }

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentOrders' => $this->recentOrders(),
            'pl' => $pl,
            'servingTime' => $this->buildServingTime($user),
        ]);
    }

    private function buildMonthlyPl(): array
    {
        $start = Carbon::now()->startOfMonth();
        $end   = Carbon::now()->endOfMonth();
        $report = $this->reportService->getProfitLossReport($start, $end);

        return [
            'revenue'      => $report['revenue']['net_revenue'],
            'cogs'         => $report['cogs']['total'],
            'gross_profit' => $report['gross_profit'],
            'expenses'     => $report['expenses']['total'],
            'net_profit'   => $report['net_profit'],
            'net_margin'   => $report['net_margin'],
        ];
    }

    private function buildStats($user): array
    {
        $stats = [];

        if ($user->hasAnyRole(['admin', 'cashier'])) {
            $stats['today_orders'] = Order::whereDate('created_at', today())->count();
            $stats['today_revenue'] = (float) Order::whereDate('created_at', today())
                ->where('payment_status', 'paid')
                ->sum('total_amount');
            $stats['active_orders'] = Order::whereIn('status', ['pending', 'preparing'])->count();
        }

        if ($user->hasAnyRole(['admin', 'kitchen'])) {
            $stats['pending_orders'] = Order::where('status', 'pending')->count();
            $stats['preparing_orders'] = Order::where('status', 'preparing')->count();
            $stats['ready_orders'] = Order::where('status', 'ready')->count();
        }

        if ($user->hasAnyRole(['admin', 'auditor'])) {
            $stats['low_stock_count'] = Ingredient::whereColumn('current_quantity', '<=', 'min_quantity')
                ->where('is_active', true)
                ->count();
            $stats['total_ingredients'] = Ingredient::where('is_active', true)->count();
        }

        return $stats;
    }

    private function buildServingTime($user): ?array
    {
        if (! $user->hasAnyRole(['admin', 'cashier', 'kitchen'])) {
            return null;
        }

        $row = Order::whereDate('created_at', today())
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as avg_seconds, COUNT(*) as completed_count')
            ->first();

        $peakHours = Order::whereDate('created_at', today())
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->selectRaw('HOUR(created_at) as hour, COUNT(*) as order_count, AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as avg_seconds')
            ->groupByRaw('HOUR(created_at)')
            ->orderByDesc('order_count')
            ->limit(3)
            ->get()
            ->map(fn ($r) => [
                'hour'        => (int) $r->hour,
                'order_count' => (int) $r->order_count,
                'avg_seconds' => (int) round((float) $r->avg_seconds),
            ])
            ->values()
            ->toArray();

        $kitchenSetting = KitchenSetting::getSetting();

        return [
            'avg_seconds'     => $row->avg_seconds ? (int) round((float) $row->avg_seconds) : null,
            'completed_today' => (int) ($row->completed_count ?? 0),
            'peak_hours'      => $peakHours,
            'fast_minutes'    => $kitchenSetting->serving_fast_minutes,
            'slow_minutes'    => $kitchenSetting->serving_slow_minutes,
        ];
    }

    private function recentOrders(): array
    {
        $user = auth()->user();

        if (! $user->hasAnyRole(['admin', 'cashier', 'kitchen', 'auditor'])) {
            return [];
        }

        return Order::with(['items.product', 'queueNumber'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($order) => [
                'id' => $order->id,
                'queue_number' => $order->queueNumber?->number,
                'order_type' => $order->order_type,
                'status' => $order->status,
                'total_amount' => (float) $order->total_amount,
                'payment_status' => $order->payment_status,
                'items_count' => $order->items->count(),
                'created_at' => $order->created_at?->toDateTimeString(),
            ])
            ->toArray();
    }
}
