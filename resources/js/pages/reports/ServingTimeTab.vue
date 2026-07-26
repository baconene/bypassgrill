<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import { RefreshCw, BarChart3, Timer, TrendingUp, TrendingDown, Clock, Download } from 'lucide-vue-next'

// ── Types ─────────────────────────────────────────────────────────────────────
interface ServingDay {
    date: string
    avg_minutes: number | null
    min_minutes: number | null
    max_minutes: number | null
    count: number
}
interface ServingByType {
    type: string
    avg_minutes: number
    min_minutes: number
    max_minutes: number
    count: number
}
interface ServingDist { bucket: string; count: number }
interface ServingData {
    period: { start: string; end: string }
    summary: { avg_minutes: number | null; min_minutes: number | null; max_minutes: number | null; total_orders: number }
    daily: ServingDay[]
    by_order_type: ServingByType[]
    distribution: ServingDist[]
}

// ── State ─────────────────────────────────────────────────────────────────────
const toManilaDate = (d: Date) => d.toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' })
const manilaToday = () => toManilaDate(new Date())
const daysAgo = (n: number) => toManilaDate(new Date(Date.now() - n * 864e5))

const dateFrom = ref(daysAgo(29))
const dateTo = ref(manilaToday())
const data = ref<ServingData | null>(null)
const loading = ref(false)
const tooltipIdx = ref<number | null>(null)

const setRange = (n: number) => { dateFrom.value = daysAgo(n - 1); dateTo.value = manilaToday(); load() }

// ── Load ──────────────────────────────────────────────────────────────────────
const load = async () => {
    loading.value = true
    try {
        const res = await api.get('/api/v1/reports/serving-time', {
            params: { date_from: dateFrom.value, date_to: dateTo.value },
        })
        data.value = res.data
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to load serving time data')
    } finally {
        loading.value = false
    }
}

onMounted(load)

// ── Chart geometry ────────────────────────────────────────────────────────────
const chart = computed(() => {
    const daily = data.value?.daily ?? []
    if (!daily.length) return null

    const W = 820, H = 220, padL = 50, padR = 16, padT = 16, padB = 44
    const chartH = H - padT - padB
    const baselineY = padT + chartH

    const validVals = daily.filter(d => d.avg_minutes !== null && d.count > 0).map(d => d.avg_minutes as number)
    if (!validVals.length) return null

    const maxVal = Math.max(...validVals, 5)
    const stepCandidates = [1, 2, 5, 10, 15, 20, 30, 60]
    const step = stepCandidates.find(s => Math.ceil(maxVal / s) <= 6) ?? 60
    const niceMax = Math.ceil(maxVal / step) * step

    const ticks = Array.from({ length: 5 }, (_, i) => ({
        y: padT + chartH - (i / 4) * chartH,
        label: `${Math.round((niceMax * i) / 4)}m`,
    }))

    const dayW = (W - padL - padR) / daily.length
    const barW = Math.max(2, Math.min(22, dayW * 0.55))
    const showEvery = daily.length <= 14 ? 1 : daily.length <= 30 ? 3 : daily.length <= 60 ? 7 : 14

    const bars = daily.map((d, i) => {
        const cx = padL + (i + 0.5) * dayW
        const h = d.avg_minutes !== null && d.count > 0 ? Math.max(1, (d.avg_minutes / niceMax) * chartH) : 0
        const minH = d.min_minutes !== null && d.count > 0 ? Math.max(0, (d.min_minutes / niceMax) * chartH) : h
        const maxH = d.max_minutes !== null && d.count > 0 ? Math.max(0, (d.max_minutes / niceMax) * chartH) : h
        return {
            cx, barW,
            barY: baselineY - h, barH: h,
            rangeY1: baselineY - maxH,
            rangeY2: baselineY - minH,
            hasData: d.avg_minutes !== null && d.count > 0,
            count: d.count,
            avg: d.avg_minutes,
            min: d.min_minutes,
            max: d.max_minutes,
            date: d.date,
            labelX: cx, labelY: baselineY + 14,
            label: new Date(d.date + 'T00:00:00').toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }),
            showLabel: i % showEvery === 0 || i === daily.length - 1,
        }
    })

    const withData = bars.filter(b => b.hasData)
    const linePath = withData.length >= 2
        ? withData.map((b, i) => `${i === 0 ? 'M' : 'L'} ${b.cx.toFixed(1)},${b.barY.toFixed(1)}`).join(' ')
        : ''

    return { bars, ticks, baselineY, padL, linePath, W, H }
})

// ── By-type max for bar scaling ────────────────────────────────────────────────
const typeMax = computed(() =>
    data.value?.by_order_type.length
        ? Math.max(...data.value.by_order_type.map(t => t.avg_minutes))
        : 1
)

// ── Distribution max for bar scaling ──────────────────────────────────────────
const distMax = computed(() =>
    data.value?.distribution.length
        ? Math.max(...data.value.distribution.map(d => d.count), 1)
        : 1
)

// ── Export CSV ────────────────────────────────────────────────────────────────
const exportCSV = () => {
    if (!data.value?.daily.length) { toast.info('No data to export'); return }
    const rows = [
        ['Date', 'Avg (min)', 'Min (min)', 'Max (min)', 'Orders'],
        ...data.value.daily.map(d => [
            d.date,
            d.avg_minutes?.toFixed(2) ?? '',
            d.min_minutes?.toFixed(2) ?? '',
            d.max_minutes?.toFixed(2) ?? '',
            String(d.count),
        ]),
    ]
    const csv = rows.map(r => r.map(c => `"${c.replace(/"/g, '""')}"`).join(',')).join('\n')
    const a = document.createElement('a')
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv)
    a.download = `serving-time-${dateFrom.value}-to-${dateTo.value}.csv`
    a.click()
    toast.success('CSV downloaded')
}

// ── Helpers ───────────────────────────────────────────────────────────────────
const fmtMin = (v: number | null) => v == null ? '—' : v < 60 ? `${v.toFixed(1)} min` : `${Math.floor(v / 60)}h ${Math.round(v % 60)}m`
const typeLabel = (t: string) => ({ dine_in: 'Dine In', takeout: 'Takeout', delivery: 'Delivery' }[t] ?? t)
const typeColor = (t: string) => ({ dine_in: 'bg-blue-500', takeout: 'bg-orange-500', delivery: 'bg-purple-500' }[t] ?? 'bg-muted')
const bucketColor = (b: string) => b === '0-5 min' ? 'bg-green-500' : b === '30+ min' ? 'bg-red-500' : 'bg-orange-500'
</script>

<template>
    <!-- Filter bar -->
    <div class="rounded-xl border bg-card shadow-sm p-4">
        <div class="flex flex-wrap gap-3 items-end">
            <div class="flex gap-1">
                <button v-for="[n, label] in [[7,'7D'],[30,'30D'],[90,'90D']] as const"
                    :key="n" @click="setRange(n)"
                    class="rounded-lg border px-3 py-2 text-xs font-semibold transition hover:bg-muted">
                    {{ label }}
                </button>
            </div>
            <div>
                <label class="text-xs font-medium text-muted-foreground block mb-1">From</label>
                <input v-model="dateFrom" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
            </div>
            <div>
                <label class="text-xs font-medium text-muted-foreground block mb-1">To</label>
                <input v-model="dateTo" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
            </div>
            <button @click="load" :disabled="loading"
                class="rounded-lg bg-primary px-5 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 flex items-center gap-1.5">
                <RefreshCw v-if="loading" class="h-3.5 w-3.5 animate-spin" />
                <BarChart3 v-else class="h-3.5 w-3.5" />
                Generate
            </button>
            <button @click="exportCSV" class="rounded-lg border bg-background px-4 py-2 text-sm font-medium hover:bg-muted flex items-center gap-1.5">
                <Download class="h-3.5 w-3.5" /> Export CSV
            </button>
        </div>
    </div>

    <!-- KPI cards -->
    <div v-if="data?.summary" class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="rounded-xl border bg-card p-4 shadow-sm">
            <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1">
                <Timer class="h-3 w-3" /> Avg Serving Time
            </p>
            <p class="text-3xl font-black text-orange-500">
                {{ data.summary.avg_minutes != null ? data.summary.avg_minutes.toFixed(1) : '—' }}
                <span class="text-sm font-normal text-muted-foreground">min</span>
            </p>
        </div>
        <div class="rounded-xl border bg-card p-4 shadow-sm">
            <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1">
                <TrendingDown class="h-3 w-3 text-green-500" /> Fastest Order
            </p>
            <p class="text-3xl font-black text-green-600">
                {{ data.summary.min_minutes != null ? data.summary.min_minutes.toFixed(1) : '—' }}
                <span class="text-sm font-normal text-muted-foreground">min</span>
            </p>
        </div>
        <div class="rounded-xl border bg-card p-4 shadow-sm">
            <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1">
                <TrendingUp class="h-3 w-3 text-red-500" /> Slowest Order
            </p>
            <p class="text-3xl font-black text-red-500">
                {{ data.summary.max_minutes != null ? data.summary.max_minutes.toFixed(1) : '—' }}
                <span class="text-sm font-normal text-muted-foreground">min</span>
            </p>
        </div>
        <div class="rounded-xl border bg-card p-4 shadow-sm">
            <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1">
                <Clock class="h-3 w-3" /> Orders Measured
            </p>
            <p class="text-3xl font-black">{{ data.summary.total_orders.toLocaleString() }}</p>
            <p class="text-xs text-muted-foreground mt-0.5">completed with timestamp</p>
        </div>
    </div>

    <!-- Daily avg chart -->
    <div v-if="data" class="rounded-xl border bg-card shadow-sm p-4">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-bold text-sm flex items-center gap-2">
                <Timer class="h-4 w-4 text-orange-500" />
                Average Serving Time per Day
            </h2>
            <span class="text-xs text-muted-foreground">order created → completed · shaded band = min/max range</span>
        </div>

        <div v-if="chart && data.daily.some(d => d.count > 0)"
            class="relative" @mouseleave="tooltipIdx = null">
            <svg :viewBox="`0 0 ${chart.W} ${chart.H}`" class="w-full" style="height:220px; overflow:visible">
                <!-- Grid lines + Y labels -->
                <line v-for="t in chart.ticks" :key="t.y"
                    :x1="chart.padL" :y1="t.y" :x2="chart.W - 16" :y2="t.y"
                    stroke="currentColor" stroke-opacity="0.07" />
                <text v-for="t in chart.ticks" :key="'yl'+t.y"
                    :x="chart.padL - 5" :y="t.y + 4"
                    text-anchor="end" class="fill-current text-muted-foreground" font-size="10">
                    {{ t.label }}
                </text>

                <!-- Min-max range shading per bar -->
                <rect v-for="(bar, i) in chart.bars.filter(b => b.hasData)" :key="'range'+i"
                    :x="bar.cx - bar.barW / 2" :width="bar.barW"
                    :y="bar.rangeY1" :height="Math.max(0, bar.rangeY2 - bar.rangeY1)"
                    fill="rgb(249,115,22)" opacity="0.18" rx="2" />

                <!-- Avg bars -->
                <rect v-for="(bar, i) in chart.bars" :key="'bar'+i"
                    v-if="bar.hasData"
                    :x="bar.cx - bar.barW / 2" :width="bar.barW"
                    :y="bar.barY" :height="bar.barH"
                    fill="rgb(249,115,22)" opacity="0.85" rx="2"
                    class="cursor-pointer"
                    :opacity="tooltipIdx === i ? '1' : '0.85'"
                    @mouseover="tooltipIdx = i" />

                <!-- Avg trend line -->
                <path v-if="chart.linePath" :d="chart.linePath"
                    fill="none" stroke="rgb(234,88,12)" stroke-width="2"
                    stroke-linejoin="round" stroke-linecap="round" opacity="0.7" />

                <!-- X-axis labels -->
                <text v-for="bar in chart.bars.filter(b => b.showLabel)" :key="'xl'+bar.date"
                    :x="bar.labelX" :y="bar.labelY"
                    text-anchor="middle" class="fill-current text-muted-foreground" font-size="9">
                    {{ bar.label }}
                </text>

                <!-- Baseline -->
                <line :x1="chart.padL" :y1="chart.baselineY" :x2="chart.W - 16" :y2="chart.baselineY"
                    stroke="currentColor" stroke-opacity="0.15" />
            </svg>

            <!-- Hover tooltip -->
            <div v-if="tooltipIdx != null && chart.bars[tooltipIdx]?.hasData"
                class="absolute bottom-10 bg-popover border rounded-lg px-3 py-2 text-xs shadow-lg pointer-events-none z-20 -translate-x-1/2"
                :style="{ left: `${((chart.bars[tooltipIdx].cx / chart.W) * 100).toFixed(1)}%` }">
                <p class="font-semibold text-muted-foreground mb-1">
                    {{ new Date(chart.bars[tooltipIdx].date + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' }) }}
                </p>
                <p class="font-bold text-orange-500 text-base">{{ chart.bars[tooltipIdx].avg?.toFixed(1) }} min avg</p>
                <p class="text-muted-foreground">
                    Range: {{ chart.bars[tooltipIdx].min?.toFixed(1) }} – {{ chart.bars[tooltipIdx].max?.toFixed(1) }} min
                </p>
                <p class="text-muted-foreground">{{ chart.bars[tooltipIdx].count }} orders completed</p>
            </div>
        </div>

        <div v-else class="py-14 text-center text-sm text-muted-foreground">
            No completed orders with timing data in this period.
        </div>
    </div>

    <!-- By order type + Distribution -->
    <div v-if="data" class="grid grid-cols-1 sm:grid-cols-2 gap-4">

        <!-- By order type -->
        <div class="rounded-xl border bg-card shadow-sm p-4">
            <h3 class="font-bold text-sm mb-5 flex items-center gap-2">
                <Clock class="h-4 w-4 text-muted-foreground" /> By Order Type
            </h3>
            <div v-if="data.by_order_type.length" class="space-y-5">
                <div v-for="bt in data.by_order_type" :key="bt.type">
                    <div class="flex items-baseline justify-between mb-1.5">
                        <span class="text-sm font-semibold">{{ typeLabel(bt.type) }}</span>
                        <span class="text-xl font-black text-orange-500">
                            {{ bt.avg_minutes.toFixed(1) }}<span class="text-xs text-muted-foreground font-normal"> min</span>
                        </span>
                    </div>
                    <div class="h-2.5 rounded-full bg-muted overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-500"
                            :class="typeColor(bt.type)"
                            :style="{ width: `${Math.min(100, (bt.avg_minutes / typeMax) * 100)}%` }" />
                    </div>
                    <div class="flex justify-between text-xs text-muted-foreground mt-1">
                        <span>{{ bt.count.toLocaleString() }} orders</span>
                        <span>{{ bt.min_minutes.toFixed(1) }} – {{ bt.max_minutes.toFixed(1) }} min range</span>
                    </div>
                </div>
            </div>
            <div v-else class="py-10 text-center text-sm text-muted-foreground">No data for this period.</div>
        </div>

        <!-- Distribution histogram -->
        <div class="rounded-xl border bg-card shadow-sm p-4">
            <h3 class="font-bold text-sm mb-5 flex items-center gap-2">
                <BarChart3 class="h-4 w-4 text-muted-foreground" /> Time Distribution
            </h3>
            <div v-if="data.distribution.some(d => d.count > 0)" class="space-y-3">
                <div v-for="d in data.distribution" :key="d.bucket" class="space-y-0.5">
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-mono text-muted-foreground w-16 shrink-0">{{ d.bucket }}</span>
                        <div class="flex-1 mx-3 h-6 rounded bg-muted overflow-hidden relative">
                            <div class="absolute inset-y-0 left-0 rounded transition-all duration-500 flex items-center pl-1.5"
                                :class="bucketColor(d.bucket)"
                                :style="{ width: `${Math.max(d.count > 0 ? 3 : 0, (d.count / distMax) * 100)}%` }">
                                <span v-if="d.count > 0 && (d.count / distMax) > 0.15"
                                    class="text-white text-[10px] font-bold whitespace-nowrap">
                                    {{ d.count.toLocaleString() }}
                                </span>
                            </div>
                        </div>
                        <span class="font-semibold w-10 text-right shrink-0">{{ d.count.toLocaleString() }}</span>
                    </div>
                </div>

                <div class="pt-2 border-t flex items-center gap-4 text-xs text-muted-foreground flex-wrap">
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block w-2.5 h-2.5 rounded-sm bg-green-500"></span> Fast (&lt;5 min)
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block w-2.5 h-2.5 rounded-sm bg-orange-500"></span> Normal
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block w-2.5 h-2.5 rounded-sm bg-red-500"></span> Slow (&gt;30 min)
                    </span>
                </div>
            </div>
            <div v-else class="py-10 text-center text-sm text-muted-foreground">No data for this period.</div>
        </div>
    </div>

    <!-- Loading state -->
    <div v-if="loading" class="rounded-xl border bg-card shadow-sm py-16 text-center text-sm text-muted-foreground">
        <RefreshCw class="h-5 w-5 animate-spin mx-auto mb-2 text-orange-500" />
        Loading serving time data…
    </div>
</template>
