<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Trend analytics for the Reports module. created_at is stored in Manila local
 * time (app.timezone = Asia/Manila), so hour/DOW SQL expressions read directly
 * from the column — no timezone conversion needed.
 */
class AnalyticsService
{
    private const CACHE_TTL = 300;               // 5 minutes
    private const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /** Build the full analytics bundle for a date range (+ optional category). */
    public function bundle(string $startDate, string $endDate, ?int $categoryId = null): array
    {
        $key = 'analytics:' . md5($startDate . '|' . $endDate . '|' . ($categoryId ?? 'all'));

        return Cache::remember($key, self::CACHE_TTL, function () use ($startDate, $endDate, $categoryId) {
            [$start, $end] = $this->localBounds($startDate, $endDate);

            return [
                'range'              => ['start' => $startDate, 'end' => $endDate],
                'orders_heatmap'     => $this->ordersHeatmap($start, $end, $categoryId),
                'hourly_trend'       => $this->hourlyTrend($start, $end, $categoryId),
                'peak_hours'         => $this->peakHours($start, $end, $categoryId),
                'sales_funnel'       => $this->salesFunnel($start, $end, $categoryId),
                'product_heatmap'    => $this->productHeatmap($start, $end, $categoryId),
                'product_by_hour'    => $this->productDemandByHour($start, $end, $categoryId),
                'affinity'           => $this->affinityByDaypart($start, $end, $categoryId),
                'forecast'           => $this->forecast($categoryId),
            ];
        });
    }

    private function localBounds(string $start, string $end): array
    {
        return [
            Carbon::parse($start, 'Asia/Manila')->startOfDay(),
            Carbon::parse($end, 'Asia/Manila')->endOfDay(),
        ];
    }

    /** Hour / DOW / date SQL expressions.
     *  created_at is stored in Manila local time, so no TZ conversion is needed.
     *  MySQL uses HOUR/DAYOFWEEK/DATE; SQLite uses strftime equivalents. */
    private function localHour(string $col = 'orders.created_at'): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return "CAST(strftime('%H', $col) AS INTEGER)";
        }
        return "HOUR($col)";
    }

    private function localDow(string $col = 'orders.created_at'): string
    {
        if (DB::getDriverName() === 'sqlite') {
            // strftime('%w') → 0=Sun..6=Sat; +1 → 1=Sun..7=Sat (matches MySQL DAYOFWEEK)
            return "CAST(strftime('%w', $col) AS INTEGER) + 1";
        }
        // MySQL DAYOFWEEK: 1=Sunday .. 7=Saturday
        return "DAYOFWEEK($col)";
    }

    private function localDate(string $col = 'orders.created_at'): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return "date($col)";
        }
        return "DATE($col)";
    }

    /** Base orders query (non-cancelled), with optional category restriction. */
    private function baseOrders(Carbon $start, Carbon $end, ?int $categoryId)
    {
        $q = DB::table('orders')
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.created_at', [$start, $end]);

        if ($categoryId) {
            $q->whereExists(function ($sub) use ($categoryId) {
                $sub->select(DB::raw(1))
                    ->from('order_items')
                    ->join('products', 'products.id', '=', 'order_items.product_id')
                    ->whereColumn('order_items.order_id', 'orders.id')
                    ->where('products.category_id', $categoryId);
            });
        }

        return $q;
    }

    // ── 1. Orders heatmap: day-of-week × hour ────────────────────────────────
    private function ordersHeatmap(Carbon $start, Carbon $end, ?int $categoryId): array
    {
        $rows = $this->baseOrders($start, $end, $categoryId)
            ->selectRaw($this->localDow() . ' as dow, ' . $this->localHour() . ' as hr, COUNT(*) as c')
            ->groupByRaw($this->localDow() . ', ' . $this->localHour())
            ->get();

        // grid[dowIndex 0-6 (Mon-Sun)][hour 0-23]
        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        $max  = 0;
        foreach ($rows as $r) {
            $dowMon = ((int) $r->dow + 5) % 7;   // convert 1=Sun..7=Sat -> 0=Mon..6=Sun
            $grid[$dowMon][(int) $r->hr] = (int) $r->c;
            $max = max($max, (int) $r->c);
        }

        return [
            'days'  => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            'grid'  => $grid,
            'max'   => $max,
        ];
    }

    // ── 2. Hourly sales trend: orders / revenue / AOV per hour-of-day ─────────
    private function hourlyTrend(Carbon $start, Carbon $end, ?int $categoryId): array
    {
        if ($categoryId) {
            // category-scoped: count distinct orders + sum category item subtotals
            $rows = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->join('products', 'products.id', '=', 'order_items.product_id')
                ->where('orders.status', '!=', 'cancelled')
                ->where('products.category_id', $categoryId)
                ->whereBetween('orders.created_at', [$start, $end])
                ->selectRaw($this->localHour() . ' as hr, COUNT(DISTINCT orders.id) as orders, SUM(order_items.subtotal) as revenue')
                ->groupByRaw($this->localHour())
                ->get();
        } else {
            $rows = $this->baseOrders($start, $end, null)
                ->selectRaw($this->localHour() . ' as hr, COUNT(*) as orders, SUM(orders.total_amount) as revenue')
                ->groupByRaw($this->localHour())
                ->get();
        }

        $byHour = [];
        foreach ($rows as $r) {
            $byHour[(int) $r->hr] = ['orders' => (int) $r->orders, 'revenue' => (float) $r->revenue];
        }

        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $o = $byHour[$h]['orders'] ?? 0;
            $rev = round($byHour[$h]['revenue'] ?? 0, 2);
            $out[] = [
                'hour'    => $h,
                'label'   => sprintf('%02d:00', $h),
                'orders'  => $o,
                'revenue' => $rev,
                'aov'     => $o > 0 ? round($rev / $o, 2) : 0,
            ];
        }

        return $out;
    }

    // ── 5. Peak hours insights ───────────────────────────────────────────────
    private function peakHours(Carbon $start, Carbon $end, ?int $categoryId): array
    {
        $hourly = $this->hourlyTrend($start, $end, $categoryId);
        $active = array_values(array_filter($hourly, fn ($h) => $h['orders'] > 0));

        $topOrders  = $this->argTop($hourly, 'orders');
        $topRevenue = $this->argTop($hourly, 'revenue');
        $lowest     = $active ? $this->argBottom($active, 'orders') : null;

        // weekday vs weekend by day-of-week
        $dowRows = $this->baseOrders($start, $end, $categoryId)
            ->selectRaw($this->localDow() . ' as dow, COUNT(*) as c, SUM(orders.total_amount) as rev')
            ->groupByRaw($this->localDow())
            ->get();

        $dowCounts = array_fill(0, 7, 0);   // 0=Sun..6=Sat
        foreach ($dowRows as $r) {
            $dowCounts[((int) $r->dow) - 1] = (int) $r->c;
        }
        $peakDowIdx   = $this->indexOfMax($dowCounts);
        $weekendIdx   = $dowCounts[0] >= $dowCounts[6] ? 0 : 6;   // Sun vs Sat

        // fastest growing daypart: compare 2nd half vs 1st half of the range
        $growth = $this->fastestGrowingPeriod($start, $end, $categoryId);

        return [
            'highest_order_hour'   => $topOrders,
            'highest_revenue_hour' => $topRevenue,
            'lowest_sales_hour'    => $lowest,
            'peak_weekday'         => ['day' => self::DAYS[$peakDowIdx], 'orders' => $dowCounts[$peakDowIdx]],
            'peak_weekend'         => ['day' => self::DAYS[$weekendIdx], 'orders' => $dowCounts[$weekendIdx]],
            'fastest_growing'      => $growth,
        ];
    }

    private function argTop(array $rows, string $field): array
    {
        $best = $rows[0];
        foreach ($rows as $r) {
            if ($r[$field] > $best[$field]) {
                $best = $r;
            }
        }
        return ['hour' => $best['label'], 'value' => $best[$field]];
    }

    private function argBottom(array $rows, string $field): array
    {
        $worst = $rows[0];
        foreach ($rows as $r) {
            if ($r[$field] < $worst[$field]) {
                $worst = $r;
            }
        }
        return ['hour' => $worst['label'], 'value' => $worst[$field]];
    }

    private function indexOfMax(array $arr): int
    {
        $idx = 0;
        foreach ($arr as $i => $v) {
            if ($v > $arr[$idx]) {
                $idx = $i;
            }
        }
        return $idx;
    }

    private function fastestGrowingPeriod(Carbon $start, Carbon $end, ?int $categoryId): array
    {
        $mid = $start->copy()->addSeconds((int) ($end->diffInSeconds($start) / 2));

        $part = fn (Carbon $a, Carbon $b) => $this->daypartTotals($a, $b, $categoryId);
        $first  = $part($start, $mid);
        $second = $part($mid, $end);

        $best = ['period' => null, 'growth' => 0.0];
        foreach ($second as $name => $rev) {
            $prev = $first[$name] ?? 0;
            $growth = $prev > 0 ? (($rev - $prev) / $prev) * 100 : ($rev > 0 ? 100 : 0);
            if ($growth > $best['growth']) {
                $best = ['period' => $name, 'growth' => round($growth, 1)];
            }
        }
        return $best;
    }

    private function dayparts(): array
    {
        return [
            'Morning'   => [5, 11],
            'Lunch'     => [11, 14],
            'Afternoon' => [14, 17],
            'Evening'   => [17, 21],
            'Night'     => [21, 29],   // 21:00–05:00 (wraps; handled via modulo)
        ];
    }

    private function daypartCaseSql(string $hourExpr): string
    {
        return "CASE
            WHEN $hourExpr >= 5  AND $hourExpr < 11 THEN 'Morning'
            WHEN $hourExpr >= 11 AND $hourExpr < 14 THEN 'Lunch'
            WHEN $hourExpr >= 14 AND $hourExpr < 17 THEN 'Afternoon'
            WHEN $hourExpr >= 17 AND $hourExpr < 21 THEN 'Evening'
            ELSE 'Night' END";
    }

    private function daypartTotals(Carbon $start, Carbon $end, ?int $categoryId): array
    {
        $rows = $this->baseOrders($start, $end, $categoryId)
            ->selectRaw($this->daypartCaseSql($this->localHour()) . " as part, SUM(orders.total_amount) as rev")
            ->groupByRaw($this->daypartCaseSql($this->localHour()))
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->part] = (float) $r->rev;
        }
        return $out;
    }

    // ── 6. Sales funnel by daypart ───────────────────────────────────────────
    private function salesFunnel(Carbon $start, Carbon $end, ?int $categoryId): array
    {
        $rows = $this->baseOrders($start, $end, $categoryId)
            ->selectRaw($this->daypartCaseSql($this->localHour()) . " as part, COUNT(*) as orders, SUM(orders.total_amount) as revenue")
            ->groupByRaw($this->daypartCaseSql($this->localHour()))
            ->get()
            ->keyBy('part');

        $labels = [
            'Morning'   => '5AM–11AM',
            'Lunch'     => '11AM–2PM',
            'Afternoon' => '2PM–5PM',
            'Evening'   => '5PM–9PM',
            'Night'     => '9PM–5AM',
        ];

        $out = [];
        foreach ($labels as $part => $window) {
            $o = (int) ($rows[$part]->orders ?? 0);
            $rev = round((float) ($rows[$part]->revenue ?? 0), 2);
            $out[] = [
                'part'    => $part,
                'window'  => $window,
                'orders'  => $o,
                'revenue' => $rev,
                'avg'     => $o > 0 ? round($rev / $o, 2) : 0,
            ];
        }
        return $out;
    }

    // ── 4. Product demand heatmap: product × hour ────────────────────────────
    private function productHeatmap(Carbon $start, Carbon $end, ?int $categoryId, int $topN = 12): array
    {
        // top N products by quantity in range
        $topProducts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.created_at', [$start, $end])
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->selectRaw('products.id, products.name, SUM(order_items.quantity) as qty')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('qty')
            ->limit($topN)
            ->get();

        if ($topProducts->isEmpty()) {
            return ['products' => [], 'grid' => [], 'max' => 0];
        }

        $ids = $topProducts->pluck('id')->all();

        $cells = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.created_at', [$start, $end])
            ->whereIn('order_items.product_id', $ids)
            ->selectRaw('order_items.product_id as pid, ' . $this->localHour() . ' as hr, SUM(order_items.quantity) as qty')
            ->groupByRaw('order_items.product_id, ' . $this->localHour())
            ->get();

        $rowIndex = [];
        foreach ($topProducts as $i => $p) {
            $rowIndex[$p->id] = $i;
        }
        $grid = array_fill(0, count($topProducts), array_fill(0, 24, 0));
        $max  = 0;
        foreach ($cells as $c) {
            $ri = $rowIndex[$c->pid];
            $grid[$ri][(int) $c->hr] = (int) $c->qty;
            $max = max($max, (int) $c->qty);
        }

        return [
            'products' => $topProducts->map(fn ($p) => ['name' => $p->name, 'qty' => (int) $p->qty])->all(),
            'grid'     => $grid,
            'max'      => $max,
        ];
    }

    // ── 3. Product demand by hour: top products in each hour ──────────────────
    private function productDemandByHour(Carbon $start, Carbon $end, ?int $categoryId, int $perHour = 5): array
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.created_at', [$start, $end])
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->selectRaw($this->localHour() . ' as hr, products.name, SUM(order_items.quantity) as qty, SUM(order_items.subtotal) as revenue')
            ->groupByRaw($this->localHour() . ', products.name')
            ->get()
            ->groupBy('hr');

        $out = [];
        foreach ($rows as $hr => $items) {
            $total = $items->sum('qty');
            $sorted = $items->sortByDesc('qty')->take($perHour)->values();
            $out[] = [
                'hour'  => (int) $hr,
                'label' => sprintf('%02d:00 – %02d:00', (int) $hr, ((int) $hr + 1) % 24),
                'items' => $sorted->map(fn ($i) => [
                    'name'    => $i->name,
                    'qty'     => (int) $i->qty,
                    'revenue' => round((float) $i->revenue, 2),
                    'pct'     => $total > 0 ? round(((int) $i->qty / $total) * 100, 1) : 0,
                ])->all(),
            ];
        }

        usort($out, fn ($a, $b) => $a['hour'] <=> $b['hour']);
        return $out;
    }

    // ── 7. Product affinity by daypart (co-occurrence) ───────────────────────
    private function affinityByDaypart(Carbon $start, Carbon $end, ?int $categoryId, int $topPairs = 5): array
    {
        $hourExpr = $this->localHour('o.created_at');

        $rows = DB::table('order_items as a')
            ->join('order_items as b', function ($j) {
                $j->on('a.order_id', '=', 'b.order_id')->whereColumn('a.product_id', '<', 'b.product_id');
            })
            ->join('orders as o', 'o.id', '=', 'a.order_id')
            ->join('products as pa', 'pa.id', '=', 'a.product_id')
            ->join('products as pb', 'pb.id', '=', 'b.product_id')
            ->where('o.status', '!=', 'cancelled')
            ->whereBetween('o.created_at', [$start, $end])
            ->when($categoryId, fn ($q) => $q->where(function ($w) use ($categoryId) {
                $w->where('pa.category_id', $categoryId)->orWhere('pb.category_id', $categoryId);
            }))
            ->selectRaw($this->daypartCaseSql($hourExpr) . " as part, pa.name as a_name, pb.name as b_name, COUNT(DISTINCT a.order_id) as together")
            ->groupByRaw($this->daypartCaseSql($hourExpr) . ', pa.name, pb.name')
            ->havingRaw('together >= 2')
            ->get()
            ->groupBy('part');

        $order = ['Morning', 'Lunch', 'Afternoon', 'Evening', 'Night'];
        $out = [];
        foreach ($order as $part) {
            $pairs = ($rows[$part] ?? collect())
                ->sortByDesc('together')->take($topPairs)->values()
                ->map(fn ($r) => ['pair' => $r->a_name . ' + ' . $r->b_name, 'count' => (int) $r->together])
                ->all();
            if ($pairs) {
                $out[] = ['part' => $part, 'pairs' => $pairs];
            }
        }
        return $out;
    }

    // ── 8. Simple forecasting (moving average of same weekday/hour) ──────────
    private function forecast(?int $categoryId): array
    {
        $now      = Carbon::now('Asia/Manila');
        $weeks    = 4;
        $lookback = $now->copy()->subWeeks($weeks)->startOfDay();
        $nowLocal = $now->copy();
        $nextHour  = ($now->hour + 1) % 24;
        $todayDow  = $now->dayOfWeek + 1;   // Carbon 0=Sun -> MySQL 1=Sun

        // average orders for (this weekday, next hour) over the last N weeks
        $perHour = DB::table('orders')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$lookback, $nowLocal])
            ->whereRaw($this->localDow() . ' = ?', [$todayDow])
            ->whereRaw($this->localHour() . ' = ?', [$nextHour])
            ->count();

        // average total daily revenue for this weekday over the last N weeks
        $dayRev = DB::table('orders')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$lookback, $nowLocal])
            ->whereRaw($this->localDow() . ' = ?', [$todayDow])
            ->selectRaw('SUM(total_amount) as rev, COUNT(DISTINCT ' . $this->localDate('created_at') . ') as days')
            ->first();

        $expectedToday = ($dayRev && $dayRev->days > 0) ? round($dayRev->rev / $dayRev->days, 2) : 0;

        // busiest upcoming hours for this weekday (avg orders desc)
        $busy = DB::table('orders')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$lookback, $nowLocal])
            ->whereRaw($this->localDow() . ' = ?', [$todayDow])
            ->selectRaw($this->localHour() . ' as hr, COUNT(*) as c')
            ->groupByRaw($this->localHour())
            ->orderByDesc('c')
            ->limit(3)
            ->get()
            ->map(fn ($r) => sprintf('%02d:00', (int) $r->hr))
            ->all();

        return [
            'expected_orders_next_hour' => (int) round($perHour / max(1, $weeks)),
            'next_hour_label'           => sprintf('%02d:00', $nextHour),
            'expected_sales_today'      => $expectedToday,
            'expected_busy_periods'     => $busy,
            'based_on_weeks'            => $weeks,
        ];
    }
}
