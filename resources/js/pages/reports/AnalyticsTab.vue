<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import {
    RefreshCw, BarChart3, Flame, Clock, TrendingUp, Layers, Link2, Sparkles, Download, Printer,
} from 'lucide-vue-next'

// ── Types ───────────────────────────────────────────────────────────────────
interface HourPoint { hour: number; label: string; orders: number; revenue: number; aov: number }
interface Heatmap { days?: string[]; products?: { name: string; qty: number }[]; grid: number[][]; max: number }
interface Funnel { part: string; window: string; orders: number; revenue: number; avg: number }
interface ProdHour { hour: number; label: string; items: { name: string; qty: number; revenue: number; pct: number }[] }
interface Affinity { part: string; pairs: { pair: string; count: number }[] }
interface Bundle {
    range: { start: string; end: string }
    orders_heatmap: Heatmap
    hourly_trend: HourPoint[]
    peak_hours: any
    sales_funnel: Funnel[]
    product_heatmap: Heatmap
    product_by_hour: ProdHour[]
    affinity: Affinity[]
    forecast: any
}

// ── State ───────────────────────────────────────────────────────────────────
const toManilaDate = (d: Date) => d.toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' })
const manilaToday = () => toManilaDate(new Date())
const daysAgo = (n: number) => toManilaDate(new Date(Date.now() - n * 864e5))

const startDate = ref(daysAgo(30))
const endDate = ref(manilaToday())
const categoryId = ref<number | ''>('')
const categories = ref<{ id: number; name: string }[]>([])
const loading = ref(false)
const data = ref<Bundle | null>(null)
const trendMetric = ref<'orders' | 'revenue' | 'aov'>('orders')

const fmt = (v: number) => '₱' + v.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const fmtShort = (v: number) => v >= 1000 ? '₱' + (v / 1000).toFixed(1) + 'K' : '₱' + Math.round(v)

const setRange = (n: number) => { startDate.value = daysAgo(n); endDate.value = manilaToday(); load() }

const load = async () => {
    loading.value = true
    try {
        const res = await api.get('/api/v1/reports/analytics', {
            params: { start_date: startDate.value, end_date: endDate.value, category_id: categoryId.value || undefined },
        })
        data.value = res.data
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to load analytics')
    } finally {
        loading.value = false
    }
}

// ── Heatmap colouring (single warm hue, light → dark) ────────────────────────
const heatColor = (v: number, max: number) => {
    if (v <= 0 || max <= 0) return 'transparent'
    const t = Math.min(1, v / max)
    // light amber → deep orange
    const light = 95 - t * 55          // lightness 95% → 40%
    return `hsl(28 90% ${light}%)`
}
const heatText = (v: number, max: number) => (max > 0 && v / max > 0.55) ? '#fff' : 'hsl(28 60% 25%)'

const hours = Array.from({ length: 24 }, (_, h) => h)

// ── Hourly trend SVG geometry ────────────────────────────────────────────────
const trend = computed(() => {
    const pts = data.value?.hourly_trend ?? []
    const W = 820, H = 240, padL = 52, padR = 12, padT = 16, padB = 32
    const chartW = W - padL - padR, chartH = H - padT - padB
    const vals = pts.map(p => p[trendMetric.value])
    const maxV = Math.max(...vals, 1)
    const niceMax = Math.ceil(maxV / Math.pow(10, Math.floor(Math.log10(maxV)))) * Math.pow(10, Math.floor(Math.log10(maxV)))
    const stepX = chartW / 23
    const isMoney = trendMetric.value !== 'orders'
    const ticks = [0, 1, 2, 3, 4].map(i => ({
        y: padT + chartH - (i / 4) * chartH,
        label: isMoney ? fmtShort((niceMax * i) / 4) : String(Math.round((niceMax * i) / 4)),
    }))
    const points = pts.map((p, i) => ({
        x: padL + i * stepX,
        y: padT + chartH - (p[trendMetric.value] / niceMax) * chartH,
        ...p,
    }))
    const path = points.map((p, i) => (i === 0 ? 'M' : 'L') + p.x.toFixed(1) + ' ' + p.y.toFixed(1)).join(' ')
    const area = path + ` L ${padL + 23 * stepX} ${padT + chartH} L ${padL} ${padT + chartH} Z`
    return { W, H, padL, padT, chartH, stepX, ticks, points, path, area, baselineY: padT + chartH }
})

const peakColor = (key: string) => ({
    highest_order_hour: 'text-blue-600',
    highest_revenue_hour: 'text-green-600',
    lowest_sales_hour: 'text-amber-600',
}[key] ?? 'text-foreground')

// ── Export ──────────────────────────────────────────────────────────────────
const exportCSV = () => {
    if (!data.value) return
    const rows = [['Hour', 'Orders', 'Revenue', 'Avg Order Value']]
    data.value.hourly_trend.forEach(h => rows.push([h.label, String(h.orders), String(h.revenue), String(h.aov)]))
    const csv = rows.map(r => r.map(c => `"${c}"`).join(',')).join('\n')
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }))
    const a = document.createElement('a')
    a.href = url; a.download = `analytics-${startDate.value}-to-${endDate.value}.csv`; a.click()
    URL.revokeObjectURL(url)
}
const printReport = () => window.print()

onMounted(async () => {
    try {
        const res = await api.get('/api/v1/categories')
        categories.value = (res.data.data ?? res.data ?? []).map((c: any) => ({ id: c.id, name: c.name }))
    } catch { /* non-critical */ }
    await load()
})
</script>

<template>
    <div class="space-y-4">
        <!-- Filters -->
        <div class="rounded-xl border bg-card shadow-sm p-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">From</label>
                    <input v-model="startDate" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">To</label>
                    <input v-model="endDate" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Category</label>
                    <select v-model="categoryId" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                        <option value="">All categories</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <button @click="load" :disabled="loading"
                    class="rounded-lg bg-primary px-5 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 flex items-center gap-1.5">
                    <RefreshCw v-if="loading" class="h-3.5 w-3.5 animate-spin" />
                    <BarChart3 v-else class="h-3.5 w-3.5" /> Generate
                </button>
                <div class="flex items-center gap-1 ml-auto">
                    <button v-for="d in [7, 30, 90]" :key="d" @click="setRange(d)"
                        class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted">{{ d }}d</button>
                    <button @click="exportCSV" class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted flex items-center gap-1"><Download class="h-3 w-3" /> CSV</button>
                    <button @click="printReport" class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted flex items-center gap-1"><Printer class="h-3 w-3" /> Print</button>
                </div>
            </div>
        </div>

        <div v-if="loading && !data" class="text-center py-16 text-sm text-muted-foreground">Loading analytics…</div>

        <template v-if="data">
            <!-- Forecast + Peak insight cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-[10px] uppercase tracking-wide text-muted-foreground flex items-center gap-1"><Sparkles class="h-3 w-3" /> Next Hour ({{ data.forecast.next_hour_label }})</p>
                    <p class="text-2xl font-black mt-1">~{{ data.forecast.expected_orders_next_hour }}</p>
                    <p class="text-[10px] text-muted-foreground">expected orders</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-[10px] uppercase tracking-wide text-muted-foreground flex items-center gap-1"><TrendingUp class="h-3 w-3" /> Expected Today</p>
                    <p class="text-xl font-black mt-1 text-green-600">{{ fmtShort(data.forecast.expected_sales_today) }}</p>
                    <p class="text-[10px] text-muted-foreground">avg for this weekday</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-[10px] uppercase tracking-wide text-muted-foreground flex items-center gap-1"><Clock class="h-3 w-3" /> Peak Hour</p>
                    <p class="text-2xl font-black mt-1 text-blue-600">{{ data.peak_hours.highest_order_hour.hour }}</p>
                    <p class="text-[10px] text-muted-foreground">{{ data.peak_hours.highest_order_hour.value }} orders</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-[10px] uppercase tracking-wide text-muted-foreground flex items-center gap-1"><Flame class="h-3 w-3" /> Peak Weekday</p>
                    <p class="text-2xl font-black mt-1 text-orange-600">{{ data.peak_hours.peak_weekday.day }}</p>
                    <p class="text-[10px] text-muted-foreground">{{ data.peak_hours.peak_weekday.orders }} orders</p>
                </div>
            </div>

            <!-- Peak insight strip -->
            <div class="rounded-xl border bg-card shadow-sm p-4 grid sm:grid-cols-3 gap-3 text-sm">
                <div><span class="text-muted-foreground">Top revenue hour:</span> <span class="font-bold text-green-600">{{ data.peak_hours.highest_revenue_hour.hour }}</span> ({{ fmt(data.peak_hours.highest_revenue_hour.value) }})</div>
                <div><span class="text-muted-foreground">Lowest sales hour:</span> <span class="font-bold text-amber-600">{{ data.peak_hours.lowest_sales_hour?.hour ?? '—' }}</span></div>
                <div><span class="text-muted-foreground">Fastest growing:</span> <span class="font-bold">{{ data.peak_hours.fastest_growing.period ?? '—' }}</span> <span v-if="data.peak_hours.fastest_growing.period" class="text-green-600">+{{ data.peak_hours.fastest_growing.growth }}%</span></div>
            </div>

            <!-- Orders Heatmap -->
            <div class="rounded-xl border bg-card shadow-sm p-4 overflow-x-auto">
                <h3 class="font-bold text-sm flex items-center gap-2 mb-3"><Flame class="h-4 w-4 text-orange-500" /> Orders Heatmap — Day × Hour</h3>
                <div class="min-w-[760px]">
                    <div class="flex">
                        <div class="w-10 shrink-0"></div>
                        <div class="flex-1 grid" :style="{ gridTemplateColumns: `repeat(24, minmax(0, 1fr))` }">
                            <div v-for="h in hours" :key="h" class="text-[9px] text-center text-muted-foreground">{{ h }}</div>
                        </div>
                    </div>
                    <div v-for="(row, di) in data.orders_heatmap.grid" :key="di" class="flex items-center">
                        <div class="w-10 shrink-0 text-xs font-semibold text-muted-foreground">{{ data.orders_heatmap.days?.[di] }}</div>
                        <div class="flex-1 grid gap-0.5 py-0.5" :style="{ gridTemplateColumns: `repeat(24, minmax(0, 1fr))` }">
                            <div v-for="(v, hi) in row" :key="hi"
                                class="aspect-square rounded-[3px] flex items-center justify-center text-[8px] font-semibold"
                                :style="{ backgroundColor: heatColor(v, data.orders_heatmap.max), color: heatText(v, data.orders_heatmap.max) }"
                                :title="`${data.orders_heatmap.days?.[di]} ${hi}:00 — ${v} orders`">
                                {{ v > 0 ? v : '' }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hourly Trend -->
            <div class="rounded-xl border bg-card shadow-sm p-4">
                <div class="flex items-center justify-between flex-wrap gap-2 mb-3">
                    <h3 class="font-bold text-sm flex items-center gap-2"><TrendingUp class="h-4 w-4 text-primary" /> Hourly Sales Trend</h3>
                    <div class="flex items-center gap-1">
                        <button v-for="m in (['orders', 'revenue', 'aov'] as const)" :key="m" @click="trendMetric = m"
                            :class="['rounded-lg border px-2.5 py-1 text-xs font-medium capitalize', trendMetric === m ? 'bg-primary text-primary-foreground border-primary' : 'hover:bg-muted']">
                            {{ m === 'aov' ? 'Avg Order' : m }}
                        </button>
                    </div>
                </div>
                <svg :viewBox="`0 0 ${trend.W} ${trend.H}`" class="w-full" :style="{ height: '240px' }">
                    <g v-for="(t, i) in trend.ticks" :key="i">
                        <line :x1="trend.padL" :y1="t.y" :x2="trend.W - 12" :y2="t.y" stroke="currentColor" class="text-border" stroke-width="1" stroke-dasharray="3 3" />
                        <text :x="trend.padL - 6" :y="t.y + 3" text-anchor="end" class="fill-muted-foreground text-[9px]">{{ t.label }}</text>
                    </g>
                    <path :d="trend.area" class="fill-primary/10" />
                    <path :d="trend.path" fill="none" class="stroke-primary" stroke-width="2" />
                    <g v-for="(p, i) in trend.points" :key="i">
                        <circle :cx="p.x" :cy="p.y" r="2.5" class="fill-primary" />
                        <text v-if="i % 2 === 0" :x="p.x" :y="trend.baselineY + 14" text-anchor="middle" class="fill-muted-foreground text-[8px]">{{ p.hour }}</text>
                    </g>
                </svg>
            </div>

            <!-- Sales Funnel by daypart -->
            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="p-4 border-b"><h3 class="font-bold text-sm flex items-center gap-2"><Layers class="h-4 w-4 text-purple-500" /> Sales Funnel by Time of Day</h3></div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm min-w-[480px]">
                        <thead class="bg-muted/50 text-muted-foreground text-xs uppercase">
                            <tr><th class="px-4 py-2 text-left">Daypart</th><th class="px-4 py-2 text-left">Window</th><th class="px-4 py-2 text-right">Orders</th><th class="px-4 py-2 text-right">Revenue</th><th class="px-4 py-2 text-right">Avg Ticket</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="f in data.sales_funnel" :key="f.part" class="hover:bg-muted/20">
                                <td class="px-4 py-2 font-semibold">{{ f.part }}</td>
                                <td class="px-4 py-2 text-muted-foreground text-xs">{{ f.window }}</td>
                                <td class="px-4 py-2 text-right font-bold">{{ f.orders }}</td>
                                <td class="px-4 py-2 text-right text-green-600 font-semibold">{{ fmt(f.revenue) }}</td>
                                <td class="px-4 py-2 text-right">{{ fmt(f.avg) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Product Demand Heatmap -->
            <div v-if="data.product_heatmap.products?.length" class="rounded-xl border bg-card shadow-sm p-4 overflow-x-auto">
                <h3 class="font-bold text-sm flex items-center gap-2 mb-3"><Flame class="h-4 w-4 text-teal-500" /> Product Demand Heatmap — Product × Hour</h3>
                <div class="min-w-[820px]">
                    <div class="flex">
                        <div class="w-32 shrink-0"></div>
                        <div class="flex-1 grid" :style="{ gridTemplateColumns: `repeat(24, minmax(0, 1fr))` }">
                            <div v-for="h in hours" :key="h" class="text-[9px] text-center text-muted-foreground">{{ h }}</div>
                        </div>
                    </div>
                    <div v-for="(row, pi) in data.product_heatmap.grid" :key="pi" class="flex items-center">
                        <div class="w-32 shrink-0 text-xs font-medium truncate pr-2" :title="data.product_heatmap.products?.[pi]?.name">{{ data.product_heatmap.products?.[pi]?.name }}</div>
                        <div class="flex-1 grid gap-0.5 py-0.5" :style="{ gridTemplateColumns: `repeat(24, minmax(0, 1fr))` }">
                            <div v-for="(v, hi) in row" :key="hi"
                                class="aspect-square rounded-[3px] flex items-center justify-center text-[8px] font-semibold"
                                :style="{ backgroundColor: heatColor(v, data.product_heatmap.max), color: heatText(v, data.product_heatmap.max) }"
                                :title="`${data.product_heatmap.products?.[pi]?.name} @ ${hi}:00 — ${v} sold`">
                                {{ v > 0 ? v : '' }}
                            </div>
                        </div>
                    </div>
                </div>
                <p class="text-[10px] text-muted-foreground mt-2">Reveals breakfast / lunch / dinner / late-night sellers at a glance.</p>
            </div>

            <!-- Product Demand by Hour + Affinity -->
            <div class="grid lg:grid-cols-2 gap-4">
                <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                    <div class="p-4 border-b"><h3 class="font-bold text-sm flex items-center gap-2"><Clock class="h-4 w-4 text-blue-500" /> Top Products by Hour</h3></div>
                    <div class="max-h-[420px] overflow-y-auto divide-y">
                        <div v-for="h in data.product_by_hour" :key="h.hour" class="p-3">
                            <p class="text-xs font-bold text-muted-foreground mb-1.5">{{ h.label }}</p>
                            <div class="space-y-1">
                                <div v-for="it in h.items" :key="it.name" class="flex items-center gap-2 text-sm">
                                    <span class="flex-1 truncate">{{ it.name }}</span>
                                    <span class="font-bold">{{ it.qty }}</span>
                                    <span class="text-xs text-muted-foreground w-12 text-right">{{ it.pct }}%</span>
                                </div>
                            </div>
                        </div>
                        <div v-if="!data.product_by_hour.length" class="p-6 text-center text-sm text-muted-foreground">No product data in range.</div>
                    </div>
                </div>

                <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                    <div class="p-4 border-b"><h3 class="font-bold text-sm flex items-center gap-2"><Link2 class="h-4 w-4 text-pink-500" /> Product Affinity by Time</h3></div>
                    <div class="p-3 space-y-3 max-h-[420px] overflow-y-auto">
                        <div v-for="a in data.affinity" :key="a.part">
                            <p class="text-xs font-bold text-muted-foreground mb-1.5">{{ a.part }}</p>
                            <div class="flex flex-wrap gap-1.5">
                                <span v-for="p in a.pairs" :key="p.pair" class="rounded-full bg-muted px-2.5 py-1 text-xs">
                                    {{ p.pair }} <span class="font-bold text-pink-600">×{{ p.count }}</span>
                                </span>
                            </div>
                        </div>
                        <div v-if="!data.affinity.length" class="p-6 text-center text-sm text-muted-foreground">Not enough basket data to detect pairs.</div>
                    </div>
                </div>
            </div>

            <!-- Forecast detail -->
            <div class="rounded-xl border bg-card shadow-sm p-4">
                <h3 class="font-bold text-sm flex items-center gap-2 mb-2"><Sparkles class="h-4 w-4 text-amber-500" /> Forecast (based on last {{ data.forecast.based_on_weeks }} weeks)</h3>
                <p class="text-sm text-muted-foreground">
                    Expected busy periods today:
                    <span v-for="(b, i) in data.forecast.expected_busy_periods" :key="b" class="font-bold text-foreground">{{ b }}<span v-if="i < data.forecast.expected_busy_periods.length - 1">, </span></span>
                    <span v-if="!data.forecast.expected_busy_periods.length">—</span>
                </p>
            </div>
        </template>
    </div>
</template>
