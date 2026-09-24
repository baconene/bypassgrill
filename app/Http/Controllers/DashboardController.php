<?php

namespace App\Http\Controllers;

use App\Models\DepositControl;
use App\Models\Ingredient;
use App\Models\KitchenSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\InventoryService;
use App\Services\ReportService;
use App\Services\ShiftChecklist;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService,
        private ReportService $reportService,
        private ShiftChecklist $shiftChecklist,
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
            'stats'                  => $stats,
            'recentOrders'           => $this->recentOrders(),
            'pl'                     => $pl,
            'servingTime'            => $this->buildServingTime($user),
            'pendingProductBreakdown' => $this->buildPendingProductBreakdown($user),
            'depositShift'           => $this->buildDepositShift($user),
            'shiftChecklist'         => $user->hasAnyRole(['admin', 'cashier', 'auditor'])
                ? $this->shiftChecklist->for($user)
                : null,
        ]);
    }

    /**
     * TIMESTAMPDIFF and HOUR are MySQL spellings, and the dashboard threw a 500 under
     * SQLite because of them. Both engines are in play here: MySQL in production,
     * SQLite in the test suite.
     */
    private function elapsedSecondsSql(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "(strftime('%s', completed_at) - strftime('%s', created_at))"
            : 'TIMESTAMPDIFF(SECOND, created_at, completed_at)';
    }

    private function hourSql(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', created_at) AS INTEGER)"
            : 'HOUR(created_at)';
    }

    /**
     * The shift the deposit control is currently holding, if any. Same definition the
     * deposit control itself uses: active_slot marks the one open shift, closed_at says
     * whether the closing count is in yet, and only the cashier who opened it may
     * finish it. Shown so the card can say whose shift is open rather than inviting a
     * second person to start one they will not be allowed to complete.
     */
    private function buildDepositShift($user): ?array
    {
        if (! $user->hasAnyRole(['admin', 'cashier', 'auditor'])) {
            return null;
        }

        $shift = DepositControl::with('user:id,name')->where('active_slot', 1)->first();

        if (! $shift) {
            return null;
        }

        return [
            'id'          => $shift->id,
            'user_id'     => $shift->user_id,
            'user_name'   => $shift->user?->name,
            'is_mine'     => $shift->user_id === $user->id,
            'opened_at'   => $shift->opened_at?->toIso8601String(),
            'closed_at'   => $shift->closed_at?->toIso8601String(),
            'opening_cash' => (float) ($shift->opening_snapshot['opening_cash'] ?? 0),
            'stage'       => $shift->closed_at === null ? 'counting' : 'awaiting_submission',
        ];
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

        $elapsed = $this->elapsedSecondsSql();
        $hour    = $this->hourSql();

        $row = Order::whereDate('created_at', today())
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->selectRaw("AVG({$elapsed}) as avg_seconds, COUNT(*) as completed_count")
            ->first();

        $peakHours = Order::whereDate('created_at', today())
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->selectRaw("{$hour} as hour, COUNT(*) as order_count, AVG({$elapsed}) as avg_seconds")
            ->groupByRaw($hour)
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

    private function buildPendingProductBreakdown($user): array
    {
        if (! $user->hasAnyRole(['admin', 'cashier', 'kitchen'])) {
            return [];
        }

        return OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereIn('orders.status', ['pending', 'preparing', 'ready'])
            ->selectRaw('products.name as product_name, SUM(order_items.quantity) as total_qty')
            ->groupBy('products.name')
            ->orderByDesc('total_qty')
            ->get()
            ->map(fn ($r) => [
                'name' => $r->product_name,
                'qty'  => (int) $r->total_qty,
            ])
            ->toArray();
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
