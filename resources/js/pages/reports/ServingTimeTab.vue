<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import {
    RefreshCw, BarChart3, Timer, TrendingUp, TrendingDown, Clock,
    Download, Pencil, Check, X as XIcon, ChevronUp, ChevronDown,
    ChevronLeft, ChevronRight, List,
} from 'lucide-vue-next'

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
interface OrderRow {
    id: number
    order_type: string
    customer_name: string | null
    table_number: string | null
    created_at: string
    completed_at: string
    serving_seconds: number
    serving_minutes: number
    total_amount: number
}
interface OrderPage {
    data: OrderRow[]
    current_page: number
    last_page: number
    total: number
    per_page: number
}

// ── State ─────────────────────────────────────────────────────────────────────
const toManilaDate = (d: Date) => d.toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' })
const manilaToday = () => toManilaDate(new Date())
const daysAgo = (n: number) => toManilaDate(new Date(Date.now() - n * 864e5))

const dateFrom = ref(daysAgo(29))
const dateTo   = ref(manilaToday())
const data     = ref<ServingData | null>(null)
const loading  = ref(true)
const error    = ref<string | null>(null)
const hoverIdx = ref<number | null>(null)

// Orders table state
const orders          = ref<OrderPage | null>(null)
const ordersLoading   = ref(false)
const ordersPage      = ref(1)
const sortBy          = ref<'serving_seconds' | 'created_at' | 'order_type' | 'total_amount'>('serving_seconds')
const sortDir         = ref<'asc' | 'desc'>('desc')
const selectedBucket  = ref<string | null>(null)

// Inline edit state
const editingId      = ref<number | null>(null)
const editingMinutes = ref('')
const editSaving     = ref(false)

// Range presets
const presets = [
    { n: 7,  label: '7D'  },
    { n: 30, label: '30D' },
    { n: 90, label: '90D' },
]

const setRange = (n: number) => {
    dateFrom.value = daysAgo(n - 1)
    dateTo.value   = manilaToday()
    load()
}

// ── Load ──────────────────────────────────────────────────────────────────────
const loadOrders = async () => {
    ordersLoading.value = true
    try {
        const res = await api.get('/api/v1/reports/serving-time-orders', {
            params: {
                date_from: dateFrom.value,
                date_to: dateTo.value,
                bucket: selectedBucket.value ?? undefined,
                sort_by: sortBy.value,
                sort_dir: sortDir.value,
                page: ordersPage.value,
            },
        })
        orders.value = res.data
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to load orders')
    } finally {
        ordersLoading.value = false
    }
}

const load = async () => {
    loading.value = true
    error.value   = null
    try {
        const res = await api.get('/api/v1/reports/serving-time', {
            params: { date_from: dateFrom.value, date_to: dateTo.value },
        })
        data.value = res.data
        selectedBucket.value = null
        ordersPage.value = 1
        loadOrders()
    } catch (err: any) {
        const msg = err.response?.data?.message ?? 'Failed to load serving time data'
        error.value = msg
        toast.error(msg)
    } finally {
        loading.value = false
    }
}

onMounted(load)

// ── Orders interactions ────────────────────────────────────────────────────────
const selectBucket = (bucket: string) => {
    selectedBucket.value = selectedBucket.value === bucket ? null : bucket
    ordersPage.value = 1
    loadOrders()
}

const clearBucket = () => {
    selectedBucket.value = null
    ordersPage.value = 1
    loadOrders()
}

const toggleSort = (col: typeof sortBy.value) => {
    if (sortBy.value === col) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    } else {
        sortBy.value = col
        sortDir.value = 'desc'
    }
    ordersPage.value = 1
    loadOrders()
}

const prevPage = () => {
    if (ordersPage.value > 1) { ordersPage.value--; loadOrders() }
}
const nextPage = () => {
    if (orders.value && ordersPage.value < orders.value.last_page) { ordersPage.value++; loadOrders() }
}

// ── Inline edit ───────────────────────────────────────────────────────────────
const startEdit = (row: OrderRow) => {
    editingId.value      = row.id
    editingMinutes.value = row.serving_minutes.toFixed(1)
}
const cancelEdit = () => { editingId.value = null }

const saveEdit = async (row: OrderRow) => {
    const mins = parseFloat(editingMinutes.value)
    if (isNaN(mins) || mins <= 0) { toast.error('Enter a valid number of minutes'); return }
    editSaving.value = true
    try {
        const res = await api.patch(`/api/v1/reports/serving-time-orders/${row.id}`, {
            serving_minutes: mins,
        })
        if (orders.value) {
            const idx = orders.value.data.findIndex(r => r.id === row.id)
            if (idx !== -1) {
                orders.value.data[idx].serving_minutes = res.data.serving_minutes
                orders.value.data[idx].serving_seconds = res.data.serving_seconds
                orders.value.data[idx].completed_at    = res.data.completed_at
            }
        }
        editingId.value = null
        toast.success('Serving time updated')
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to update serving time')
    } finally {
        editSaving.value = false
    }
}

// ── Chart geometry ────────────────────────────────────────────────────────────
interface ChartBar {
    cx: number; barW: number
    barY: number; barH: number
    rangeY1: number; rangeY2: number
    hasData: boolean; count: number
    avg: number | null; min: number | null; max: number | null
    date: string; labelX: number; labelY: number; label: string; showLabel: boolean
}
interface ChartData {
    bars: ChartBar[]; activeBars: ChartBar[]
    ticks: { y: number; label: string }[]
    baselineY: number; padL: number; linePath: string; W: number; H: number
}

const chart = computed<ChartData | null>(() => {
    const daily = data.value?.daily ?? []
    if (!daily.length) return null

    const W = 820, H = 220, padL = 50, padR = 16, padT = 16, padB = 44
    const chartH = H - padT - padB
    const baselineY = padT + chartH

    const validVals = daily
        .filter(d => d.avg_minutes !== null && d.count > 0)
        .map(d => d.avg_minutes as number)

    if (!validVals.length) return null

    const maxVal = Math.max(...validVals, 5)
    const candidates = [1, 2, 5, 10, 15, 20, 30, 60]
    const step = candidates.find(s => Math.ceil(maxVal / s) <= 6) ?? 60
    const niceMax = Math.ceil(maxVal / step) * step

    const ticks = [0, 1, 2, 3, 4].map(i => ({
        y: padT + chartH - (i / 4) * chartH,
        label: `${Math.round((niceMax * i) / 4)}m`,
    }))

    const dayW     = (W - padL - padR) / daily.length
    const barW     = Math.max(2, Math.min(22, dayW * 0.55))
    const showEach = daily.length <= 14 ? 1 : daily.length <= 30 ? 3 : daily.length <= 60 ? 7 : 14

    const bars: ChartBar[] = daily.map((d, i) => {
        const cx   = padL + (i + 0.5) * dayW
        const has  = d.avg_minutes !== null && d.count > 0
        const h    = has ? Math.max(1, (d.avg_minutes! / niceMax) * chartH) : 0
        const minH = has && d.min_minutes !== null ? Math.max(0, (d.min_minutes / niceMax) * chartH) : h
        const maxH = has && d.max_minutes !== null ? Math.max(0, (d.max_minutes / niceMax) * chartH) : h
        return {
            cx, barW,
            barY: baselineY - h, barH: h,
            rangeY1: baselineY - maxH,
            rangeY2: baselineY - minH,
            hasData: has, count: d.count,
            avg: d.avg_minutes, min: d.min_minutes, max: d.max_minutes,
            date: d.date,
            labelX: cx, labelY: baselineY + 14,
            label: new Date(d.date + 'T00:00:00').toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }),
            showLabel: i % showEach === 0 || i === daily.length - 1,
        }
    })

    const activeBars = bars.filter(b => b.hasData)

    const linePath = activeBars.length >= 2
        ? activeBars.map((b, i) => `${i === 0 ? 'M' : 'L'} ${b.cx.toFixed(1)},${b.barY.toFixed(1)}`).join(' ')
        : ''

    return { bars, activeBars, ticks, baselineY, padL, linePath, W, H }
})

// ── Scales for breakdowns ─────────────────────────────────────────────────────
const typeMax = computed(() =>
    data.value?.by_order_type.reduce((m, t) => Math.max(m, t.avg_minutes), 0.01) ?? 0.01
)
const distMax = computed(() =>
    data.value?.distribution.reduce((m, d) => Math.max(m, d.count), 1) ?? 1
)

// ── Helpers ───────────────────────────────────────────────────────────────────
const formatMinutes = (m: number) => {
    const mins = Math.floor(m)
    const secs = Math.round((m - mins) * 60)
    if (secs === 60) return `${mins + 1}m`
    return secs > 0 ? `${mins}m ${secs}s` : `${mins}m`
}

const servingTimeColor = (m: number) =>
    m < 5  ? 'text-green-600 dark:text-green-400' :
    m > 30 ? 'text-red-500' :
    m > 15 ? 'text-orange-500' :
    'text-foreground'

const typeLabel = (t: string) =>
    ({ dine_in: 'Dine In', takeout: 'Takeout', delivery: 'Delivery' }[t] ?? t)

const typeColor = (t: string) =>
    ({ dine_in: 'bg-blue-500', takeout: 'bg-orange-500', delivery: 'bg-purple-500' }[t] ?? 'bg-muted-foreground')

const typeBadge = (t: string) =>
    ({ dine_in:  'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
       takeout:  'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
       delivery: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300' }[t]
    ?? 'bg-muted text-muted-foreground')

const bucketColor = (b: string) =>
    b === '0-5 min' ? 'bg-green-500' : b === '30+ min' ? 'bg-red-500' : 'bg-orange-500'

const bucketRingColor = (b: string) =>
    b === '0-5 min' ? 'ring-green-400' : b === '30+ min' ? 'ring-red-400' : 'ring-orange-400'

const tooltipBar = computed(() =>
    hoverIdx.value != null && chart.value ? chart.value.bars[hoverIdx.value] ?? null : null
)
const tooltipLeft = computed(() =>
    tooltipBar.value && chart.value ? `${((tooltipBar.value.cx / chart.value.W) * 100).toFixed(1)}%` : '0%'
)

const sortIcon = (col: string, dir: 'asc' | 'desc') =>
    sortBy.value === col && sortDir.value === dir ? 'text-foreground' : 'text-muted-foreground/30'

const fmtDate = (iso: string) =>
    new Date(iso).toLocaleString('en-PH', {
        timeZone: 'Asia/Manila', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })

// ── Export ────────────────────────────────────────────────────────────────────
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
    const a   = document.createElement('a')
    a.href    = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv)
    a.download = `serving-time-${dateFrom.value}-to-${dateTo.value}.csv`
    a.click()
    toast.success('CSV downloaded')
}
</script>

<template>
<div class="space-y-5">

    <!-- Filter bar -->
    <div class="rounded-xl border bg-card shadow-sm p-4">
        <div class="flex flex-wrap gap-3 items-end">
            <div class="flex gap-1">
                <button v-for="p in presets" :key="p.n"
                    @click="setRange(p.n)"
                    class="rounded-lg border px-3 py-2 text-xs font-semibold transition hover:bg-muted">
                    {{ p.label }}
                </button>
            </div>
            <div>
                <label class="text-xs font-medium text-muted-foreground block mb-1">From</label>
                <input v-model="dateFrom" type="date"
                    class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
            </div>
            <div>
                <label class="text-xs font-medium text-muted-foreground block mb-1">To</label>
                <input v-model="dateTo" type="date"
                    class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
            </div>
            <button @click="load" :disabled="loading"
                class="rounded-lg bg-primary px-5 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 flex items-center gap-1.5">
                <RefreshCw v-if="loading" class="h-3.5 w-3.5 animate-spin" />
                <BarChart3 v-else class="h-3.5 w-3.5" />
                Generate
            </button>
            <button @click="exportCSV"
                class="rounded-lg border bg-background px-4 py-2 text-sm font-medium hover:bg-muted flex items-center gap-1.5">
                <Download class="h-3.5 w-3.5" /> Export CSV
            </button>
        </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="rounded-xl border bg-card shadow-sm py-16 text-center">
        <RefreshCw class="h-5 w-5 animate-spin mx-auto mb-2 text-orange-500" />
        <p class="text-sm text-muted-foreground">Loading serving time data…</p>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="rounded-xl border border-red-200 bg-red-50 dark:bg-red-950/20 dark:border-red-800 p-6 text-center">
        <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ error }}</p>
        <button @click="load" class="mt-3 text-xs underline text-red-500">Retry</button>
    </div>

    <!-- No data -->
    <div v-else-if="data && data.summary.total_orders === 0"
        class="rounded-xl border bg-card shadow-sm py-16 text-center">
        <Timer class="h-8 w-8 mx-auto mb-3 text-muted-foreground/40" />
        <p class="font-semibold mb-1">No serving time data for this period</p>
        <p class="text-sm text-muted-foreground">Serving time is measured from order creation to completion.</p>
    </div>

    <!-- Content -->
    <div v-else-if="data" class="space-y-5">

        <!-- KPI cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
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
        <div class="rounded-xl border bg-card shadow-sm p-4">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-bold text-sm flex items-center gap-2">
                    <Timer class="h-4 w-4 text-orange-500" />
                    Average Serving Time per Day
                </h2>
                <span class="text-xs text-muted-foreground hidden sm:block">
                    created → completed · shaded band = min/max range
                </span>
            </div>

            <div v-if="chart" class="relative" @mouseleave="hoverIdx = null">
                <svg :viewBox="`0 0 ${chart.W} ${chart.H}`"
                    class="w-full" style="height:220px; overflow:visible">

                    <line v-for="t in chart.ticks" :key="t.y"
                        :x1="chart.padL" :y1="t.y" :x2="chart.W - 16" :y2="t.y"
                        stroke="currentColor" stroke-opacity="0.07" />
                    <text v-for="t in chart.ticks" :key="'yl' + t.y"
                        :x="chart.padL - 5" :y="t.y + 4"
                        text-anchor="end" class="fill-current text-muted-foreground" font-size="10">
                        {{ t.label }}
                    </text>

                    <rect v-for="bar in chart.activeBars" :key="'rng-' + bar.date"
                        :x="bar.cx - bar.barW / 2" :width="bar.barW"
                        :y="bar.rangeY1" :height="Math.max(0, bar.rangeY2 - bar.rangeY1)"
                        fill="rgb(249,115,22)" fill-opacity="0.18" rx="2" />

                    <rect v-for="(bar, i) in chart.activeBars" :key="'bar-' + bar.date"
                        :x="bar.cx - bar.barW / 2" :width="bar.barW"
                        :y="bar.barY" :height="bar.barH"
                        rx="2"
                        :fill-opacity="hoverIdx === i ? '1' : '0.85'"
                        fill="rgb(249,115,22)"
                        style="cursor:pointer"
                        @mouseover="hoverIdx = i" />

                    <path v-if="chart.linePath" :d="chart.linePath"
                        fill="none" stroke="rgb(234,88,12)" stroke-width="2"
                        stroke-linejoin="round" stroke-linecap="round" stroke-opacity="0.7" />

                    <text v-for="bar in chart.bars.filter(b => b.showLabel)" :key="'xl-' + bar.date"
                        :x="bar.labelX" :y="bar.labelY"
                        text-anchor="middle" class="fill-current text-muted-foreground" font-size="9">
                        {{ bar.label }}
                    </text>

                    <line :x1="chart.padL" :y1="chart.baselineY"
                        :x2="chart.W - 16" :y2="chart.baselineY"
                        stroke="currentColor" stroke-opacity="0.15" />
                </svg>

                <div v-if="tooltipBar"
                    class="absolute bottom-10 bg-popover border rounded-lg px-3 py-2 text-xs shadow-lg pointer-events-none z-20 -translate-x-1/2"
                    :style="{ left: tooltipLeft }">
                    <p class="font-semibold text-muted-foreground mb-1">
                        {{ new Date(tooltipBar.date + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' }) }}
                    </p>
                    <p class="font-bold text-orange-500 text-base">{{ tooltipBar.avg?.toFixed(1) }} min avg</p>
                    <p class="text-muted-foreground">Range: {{ tooltipBar.min?.toFixed(1) }} – {{ tooltipBar.max?.toFixed(1) }} min</p>
                    <p class="text-muted-foreground">{{ tooltipBar.count }} orders completed</p>
                </div>
            </div>

            <div v-else class="py-14 text-center text-sm text-muted-foreground">
                No completed orders with serving time data in this period.
            </div>
        </div>

        <!-- By order type + Distribution -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

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
                            <span>{{ bt.min_minutes.toFixed(1) }}–{{ bt.max_minutes.toFixed(1) }} min range</span>
                        </div>
                    </div>
                </div>
                <div v-else class="py-10 text-center text-sm text-muted-foreground">No data for this period.</div>
            </div>

            <!-- Distribution histogram — click bars to filter orders table -->
            <div class="rounded-xl border bg-card shadow-sm p-4">
                <h3 class="font-bold text-sm mb-1 flex items-center gap-2">
                    <BarChart3 class="h-4 w-4 text-muted-foreground" /> Time Distribution
                </h3>
                <p class="text-xs text-muted-foreground mb-4">Click a bar to filter the orders table below</p>

                <div v-if="data.distribution.some(d => d.count > 0)" class="space-y-2">
                    <div v-for="d in data.distribution" :key="d.bucket"
                        role="button"
                        @click="selectBucket(d.bucket)"
                        :class="[
                            'flex items-center text-xs rounded-lg px-2 py-1.5 -mx-2 cursor-pointer transition-all select-none',
                            selectedBucket === d.bucket
                                ? `ring-2 ${bucketRingColor(d.bucket)} bg-muted/60`
                                : 'hover:bg-muted/40'
                        ]">
                        <span class="font-mono text-muted-foreground w-[4.5rem] shrink-0">{{ d.bucket }}</span>
                        <div class="flex-1 mx-3 h-6 rounded bg-muted overflow-hidden relative">
                            <div class="absolute inset-y-0 left-0 rounded transition-all duration-500 flex items-center pl-1.5"
                                :class="bucketColor(d.bucket)"
                                :style="{ width: `${d.count > 0 ? Math.max(3, (d.count / distMax) * 100) : 0}%` }">
                                <span v-if="d.count > 0 && (d.count / distMax) > 0.15"
                                    class="text-white text-[10px] font-bold whitespace-nowrap">
                                    {{ d.count.toLocaleString() }}
                                </span>
                            </div>
                        </div>
                        <span class="font-semibold w-10 text-right shrink-0">{{ d.count.toLocaleString() }}</span>
                        <!-- Selected indicator -->
                        <span v-if="selectedBucket === d.bucket" class="ml-2 shrink-0 text-[10px] font-bold text-primary">▼</span>
                    </div>

                    <!-- Legend -->
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

        <!-- ── Orders table ──────────────────────────────────────────────────── -->
        <div class="rounded-xl border bg-card shadow-sm overflow-hidden">

            <!-- Table header -->
            <div class="px-4 py-3 border-b flex items-center justify-between flex-wrap gap-2 bg-muted/20">
                <div class="flex items-center gap-2 flex-wrap">
                    <h3 class="font-bold text-sm flex items-center gap-2">
                        <List class="h-4 w-4 text-muted-foreground" />
                        Orders
                    </h3>
                    <!-- Active bucket filter badge -->
                    <span v-if="selectedBucket"
                        class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold bg-primary/10 text-primary border border-primary/20">
                        {{ selectedBucket }}
                        <button @click="clearBucket"
                            class="ml-0.5 rounded-full hover:bg-primary/20 p-0.5 transition-colors">
                            <XIcon class="h-2.5 w-2.5" />
                        </button>
                    </span>
                    <span v-else class="text-xs text-muted-foreground">All buckets</span>
                </div>
                <span v-if="orders" class="text-xs text-muted-foreground tabular-nums">
                    {{ orders.total.toLocaleString() }} order{{ orders.total !== 1 ? 's' : '' }}
                </span>
            </div>

            <!-- Loading skeleton -->
            <div v-if="ordersLoading" class="py-10 text-center">
                <RefreshCw class="h-4 w-4 animate-spin mx-auto text-muted-foreground" />
                <p class="text-xs text-muted-foreground mt-2">Loading orders…</p>
            </div>

            <!-- Empty state -->
            <div v-else-if="orders && orders.data.length === 0" class="py-10 text-center">
                <p class="text-sm text-muted-foreground">No orders found in this time range.</p>
            </div>

            <!-- Table -->
            <div v-else-if="orders" class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead>
                        <tr class="border-b text-xs text-muted-foreground bg-muted/10">
                            <th class="px-4 py-2.5 text-left font-medium">#</th>

                            <!-- Type — sortable -->
                            <th class="px-4 py-2.5 text-left font-medium">
                                <button @click="toggleSort('order_type')"
                                    class="flex items-center gap-1 hover:text-foreground transition-colors">
                                    Type
                                    <span class="inline-flex flex-col leading-none">
                                        <ChevronUp class="h-2.5 w-2.5" :class="sortIcon('order_type', 'asc')" />
                                        <ChevronDown class="h-2.5 w-2.5 -mt-px" :class="sortIcon('order_type', 'desc')" />
                                    </span>
                                </button>
                            </th>

                            <th class="px-4 py-2.5 text-left font-medium">Customer / Table</th>

                            <!-- Created — sortable -->
                            <th class="px-4 py-2.5 text-left font-medium">
                                <button @click="toggleSort('created_at')"
                                    class="flex items-center gap-1 hover:text-foreground transition-colors">
                                    Created
                                    <span class="inline-flex flex-col leading-none">
                                        <ChevronUp class="h-2.5 w-2.5" :class="sortIcon('created_at', 'asc')" />
                                        <ChevronDown class="h-2.5 w-2.5 -mt-px" :class="sortIcon('created_at', 'desc')" />
                                    </span>
                                </button>
                            </th>

                            <!-- Serving Time — sortable + editable -->
                            <th class="px-4 py-2.5 text-right font-medium">
                                <button @click="toggleSort('serving_seconds')"
                                    class="flex items-center gap-1 ml-auto hover:text-foreground transition-colors">
                                    Serving Time
                                    <span class="inline-flex flex-col leading-none">
                                        <ChevronUp class="h-2.5 w-2.5" :class="sortIcon('serving_seconds', 'asc')" />
                                        <ChevronDown class="h-2.5 w-2.5 -mt-px" :class="sortIcon('serving_seconds', 'desc')" />
                                    </span>
                                </button>
                            </th>

                            <!-- Total — sortable -->
                            <th class="px-4 py-2.5 text-right font-medium">
                                <button @click="toggleSort('total_amount')"
                                    class="flex items-center gap-1 ml-auto hover:text-foreground transition-colors">
                                    Total
                                    <span class="inline-flex flex-col leading-none">
                                        <ChevronUp class="h-2.5 w-2.5" :class="sortIcon('total_amount', 'asc')" />
                                        <ChevronDown class="h-2.5 w-2.5 -mt-px" :class="sortIcon('total_amount', 'desc')" />
                                    </span>
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in orders.data" :key="row.id"
                            class="hover:bg-muted/20 group transition-colors">

                            <!-- ID -->
                            <td class="px-4 py-3 text-muted-foreground font-mono text-xs whitespace-nowrap">
                                #{{ row.id }}
                            </td>

                            <!-- Type badge -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="typeBadge(row.order_type)">
                                    {{ typeLabel(row.order_type) }}
                                </span>
                            </td>

                            <!-- Customer / Table -->
                            <td class="px-4 py-3 text-muted-foreground max-w-[160px] truncate">
                                {{ row.customer_name ?? (row.table_number ? `Table ${row.table_number}` : '—') }}
                            </td>

                            <!-- Created at -->
                            <td class="px-4 py-3 text-muted-foreground text-xs whitespace-nowrap">
                                {{ fmtDate(row.created_at) }}
                            </td>

                            <!-- Serving time — inline editable -->
                            <td class="px-4 py-3 text-right">
                                <!-- Edit mode -->
                                <div v-if="editingId === row.id" class="flex items-center justify-end gap-1">
                                    <input
                                        v-model="editingMinutes"
                                        type="number" step="0.1" min="0.1" max="1440"
                                        class="w-20 rounded border bg-background px-2 py-1 text-sm text-right tabular-nums focus:outline-none focus:ring-2 focus:ring-primary"
                                        @keyup.enter="saveEdit(row)"
                                        @keyup.escape="cancelEdit"
                                        autofocus />
                                    <span class="text-xs text-muted-foreground shrink-0">min</span>
                                    <button @click="saveEdit(row)" :disabled="editSaving"
                                        class="rounded p-1 bg-green-500 text-white hover:bg-green-600 disabled:opacity-50 transition-colors">
                                        <Check class="h-3 w-3" />
                                    </button>
                                    <button @click="cancelEdit"
                                        class="rounded p-1 bg-muted hover:bg-muted/80 text-muted-foreground transition-colors">
                                        <XIcon class="h-3 w-3" />
                                    </button>
                                </div>

                                <!-- View mode -->
                                <div v-else class="flex items-center justify-end gap-1.5">
                                    <span :class="servingTimeColor(row.serving_minutes)"
                                        class="font-semibold tabular-nums">
                                        {{ formatMinutes(row.serving_minutes) }}
                                    </span>
                                    <button @click="startEdit(row)"
                                        title="Edit serving time"
                                        class="opacity-0 group-hover:opacity-100 transition-opacity rounded p-0.5 hover:bg-muted text-muted-foreground hover:text-foreground">
                                        <Pencil class="h-3 w-3" />
                                    </button>
                                </div>
                            </td>

                            <!-- Total amount -->
                            <td class="px-4 py-3 text-right font-medium tabular-nums whitespace-nowrap">
                                ₱{{ row.total_amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="orders && orders.last_page > 1"
                class="px-4 py-3 border-t flex items-center justify-between text-xs text-muted-foreground bg-muted/10">
                <span>
                    Page {{ orders.current_page }} of {{ orders.last_page }}
                    &nbsp;·&nbsp;
                    {{ orders.total.toLocaleString() }} total
                </span>
                <div class="flex items-center gap-1">
                    <button @click="prevPage" :disabled="orders.current_page === 1"
                        class="rounded border p-1.5 hover:bg-muted disabled:opacity-30 disabled:cursor-not-allowed transition-colors">
                        <ChevronLeft class="h-3.5 w-3.5" />
                    </button>
                    <span class="px-2 font-medium text-foreground">{{ orders.current_page }}</span>
                    <button @click="nextPage" :disabled="orders.current_page === orders.last_page"
                        class="rounded border p-1.5 hover:bg-muted disabled:opacity-30 disabled:cursor-not-allowed transition-colors">
                        <ChevronRight class="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>

        </div>
        <!-- ── End orders table ─────────────────────────────────────────────── -->

    </div>
</div>
</template>
