<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import {
    RefreshCw, BarChart3, Timer, TrendingUp, TrendingDown, Clock,
    Download, Pencil, Check, X as XIcon, ChevronUp, ChevronDown,
    ChevronLeft, ChevronRight, List, AlertCircle,
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
const orders         = ref<OrderPage | null>(null)
const ordersLoading  = ref(false)
const ordersPage     = ref(1)
const sortBy         = ref<'serving_seconds' | 'created_at' | 'order_type' | 'total_amount'>('serving_seconds')
const sortDir        = ref<'asc' | 'desc'>('desc')
const selectedBucket = ref<string | null>(null)

// Modal state
const modalOpen    = ref(false)
const modalRow     = ref<OrderRow | null>(null)
const modalMinutes = ref('')
const modalSaving  = ref(false)
const modalInput   = ref<HTMLInputElement | null>(null)

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
const prevPage = () => { if (ordersPage.value > 1) { ordersPage.value--; loadOrders() } }
const nextPage = () => {
    if (orders.value && ordersPage.value < orders.value.last_page) { ordersPage.value++; loadOrders() }
}

// ── Modal ─────────────────────────────────────────────────────────────────────
const openModal = async (row: OrderRow) => {
    modalRow.value     = row
    modalMinutes.value = row.serving_minutes.toFixed(1)
    modalOpen.value    = true
    await nextTick()
    modalInput.value?.focus()
    modalInput.value?.select()
}

const closeModal = () => {
    if (modalSaving.value) return
    modalOpen.value = false
    modalRow.value  = null
}

const saveModal = async () => {
    if (!modalRow.value) return
    const mins = parseFloat(modalMinutes.value)
    if (isNaN(mins) || mins <= 0) { toast.error('Enter a valid number of minutes'); return }
    modalSaving.value = true
    try {
        const res = await api.patch(`/api/v1/reports/serving-time-orders/${modalRow.value.id}`, {
            serving_minutes: mins,
        })
        if (orders.value) {
            const idx = orders.value.data.findIndex(r => r.id === modalRow.value!.id)
            if (idx !== -1) {
                orders.value.data[idx].serving_minutes = res.data.serving_minutes
                orders.value.data[idx].serving_seconds = res.data.serving_seconds
                orders.value.data[idx].completed_at    = res.data.completed_at
            }
        }
        toast.success(`Serving time updated to ${formatMinutes(res.data.serving_minutes)}`)
        closeModal()
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to update serving time')
    } finally {
        modalSaving.value = false
    }
}

// Escape key closes modal
const onKeydown = (e: KeyboardEvent) => { if (e.key === 'Escape') closeModal() }
onMounted(() => document.addEventListener('keydown', onKeydown))
onUnmounted(() => document.removeEventListener('keydown', onKeydown))

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
            rangeY1: baselineY - maxH, rangeY2: baselineY - minH,
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

// ── Modal computed ─────────────────────────────────────────────────────────────
const modalTimeBreakdown = computed(() => {
    const mins = parseFloat(modalMinutes.value)
    if (isNaN(mins) || mins <= 0) return null
    const totalSec = Math.round(mins * 60)
    const h = Math.floor(totalSec / 3600)
    const m = Math.floor((totalSec % 3600) / 60)
    const s = totalSec % 60
    const parts: string[] = []
    if (h > 0) parts.push(`${h}h`)
    if (m > 0) parts.push(`${m}m`)
    if (s > 0 || parts.length === 0) parts.push(`${s}s`)
    return parts.join(' ')
})

const modalTimeColor = computed(() => {
    const mins = parseFloat(modalMinutes.value)
    if (isNaN(mins)) return 'text-muted-foreground'
    return mins < 5 ? 'text-green-500' : mins > 30 ? 'text-red-500' : mins > 15 ? 'text-orange-500' : 'text-foreground'
})

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

const fmtDateFull = (iso: string) =>
    new Date(iso).toLocaleString('en-PH', {
        timeZone: 'Asia/Manila', weekday: 'short', month: 'short',
        day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit',
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
                        rx="2" :fill-opacity="hoverIdx === i ? '1' : '0.85'"
                        fill="rgb(249,115,22)" style="cursor:pointer"
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

            <!-- Distribution histogram — click bars to filter table -->
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
                        <span v-if="selectedBucket === d.bucket" class="ml-2 shrink-0 text-[10px] font-bold text-primary">▼</span>
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

        <!-- ── Orders table ──────────────────────────────────────────────────── -->
        <div class="rounded-xl border bg-card shadow-sm overflow-hidden">

            <!-- Header -->
            <div class="px-4 py-3 border-b flex items-center justify-between flex-wrap gap-2 bg-muted/20">
                <div class="flex items-center gap-2 flex-wrap">
                    <h3 class="font-bold text-sm flex items-center gap-2">
                        <List class="h-4 w-4 text-muted-foreground" /> Orders
                    </h3>
                    <span v-if="selectedBucket"
                        class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold bg-primary/10 text-primary border border-primary/20">
                        {{ selectedBucket }}
                        <button @click="clearBucket" class="ml-0.5 rounded-full hover:bg-primary/20 p-0.5 transition-colors">
                            <XIcon class="h-2.5 w-2.5" />
                        </button>
                    </span>
                    <span v-else class="text-xs text-muted-foreground">All buckets</span>
                </div>
                <div class="flex items-center gap-3">
                    <span v-if="orders" class="text-xs text-muted-foreground tabular-nums">
                        {{ orders.total.toLocaleString() }} order{{ orders.total !== 1 ? 's' : '' }}
                    </span>
                    <span class="text-xs text-muted-foreground hidden sm:flex items-center gap-1">
                        <Pencil class="h-3 w-3" /> Click a row to edit
                    </span>
                </div>
            </div>

            <!-- Loading -->
            <div v-if="ordersLoading" class="py-10 text-center">
                <RefreshCw class="h-4 w-4 animate-spin mx-auto text-muted-foreground" />
                <p class="text-xs text-muted-foreground mt-2">Loading orders…</p>
            </div>

            <!-- Empty -->
            <div v-else-if="orders && orders.data.length === 0" class="py-10 text-center">
                <p class="text-sm text-muted-foreground">No orders found in this range.</p>
            </div>

            <!-- Table -->
            <div v-else-if="orders" class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead>
                        <tr class="border-b text-xs text-muted-foreground bg-muted/10">
                            <th class="px-4 py-2.5 text-left font-medium">#</th>
                            <th class="px-4 py-2.5 text-left font-medium">
                                <button @click="toggleSort('order_type')" class="flex items-center gap-1 hover:text-foreground transition-colors">
                                    Type
                                    <span class="inline-flex flex-col leading-none">
                                        <ChevronUp class="h-2.5 w-2.5" :class="sortIcon('order_type', 'asc')" />
                                        <ChevronDown class="h-2.5 w-2.5 -mt-px" :class="sortIcon('order_type', 'desc')" />
                                    </span>
                                </button>
                            </th>
                            <th class="px-4 py-2.5 text-left font-medium">Customer / Table</th>
                            <th class="px-4 py-2.5 text-left font-medium">
                                <button @click="toggleSort('created_at')" class="flex items-center gap-1 hover:text-foreground transition-colors">
                                    Created
                                    <span class="inline-flex flex-col leading-none">
                                        <ChevronUp class="h-2.5 w-2.5" :class="sortIcon('created_at', 'asc')" />
                                        <ChevronDown class="h-2.5 w-2.5 -mt-px" :class="sortIcon('created_at', 'desc')" />
                                    </span>
                                </button>
                            </th>
                            <th class="px-4 py-2.5 text-right font-medium">
                                <button @click="toggleSort('serving_seconds')" class="flex items-center gap-1 ml-auto hover:text-foreground transition-colors">
                                    Serving Time
                                    <span class="inline-flex flex-col leading-none">
                                        <ChevronUp class="h-2.5 w-2.5" :class="sortIcon('serving_seconds', 'asc')" />
                                        <ChevronDown class="h-2.5 w-2.5 -mt-px" :class="sortIcon('serving_seconds', 'desc')" />
                                    </span>
                                </button>
                            </th>
                            <th class="px-4 py-2.5 text-right font-medium">
                                <button @click="toggleSort('total_amount')" class="flex items-center gap-1 ml-auto hover:text-foreground transition-colors">
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
                            @click="openModal(row)"
                            class="hover:bg-primary/5 cursor-pointer group transition-colors">

                            <td class="px-4 py-3 text-muted-foreground font-mono text-xs whitespace-nowrap">
                                #{{ row.id }}
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="typeBadge(row.order_type)">
                                    {{ typeLabel(row.order_type) }}
                                </span>
                            </td>

                            <td class="px-4 py-3 text-muted-foreground max-w-[160px] truncate">
                                {{ row.customer_name ?? (row.table_number ? `Table ${row.table_number}` : '—') }}
                            </td>

                            <td class="px-4 py-3 text-muted-foreground text-xs whitespace-nowrap">
                                {{ fmtDate(row.created_at) }}
                            </td>

                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <span :class="servingTimeColor(row.serving_minutes)" class="font-semibold tabular-nums">
                                        {{ formatMinutes(row.serving_minutes) }}
                                    </span>
                                    <Pencil class="h-3 w-3 text-muted-foreground/40 opacity-0 group-hover:opacity-100 transition-opacity shrink-0" />
                                </div>
                            </td>

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

<!-- ── Edit Serving Time Modal ──────────────────────────────────────────────── -->
<Teleport to="body">
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0">
        <div v-if="modalOpen && modalRow"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
            @click.self="closeModal">

            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0 scale-95 translate-y-2"
                enter-to-class="opacity-100 scale-100 translate-y-0"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100 scale-100 translate-y-0"
                leave-to-class="opacity-0 scale-95 translate-y-2">
                <div v-if="modalOpen"
                    class="w-full max-w-md rounded-2xl bg-card border shadow-2xl overflow-hidden"
                    @click.stop>

                    <!-- Modal header -->
                    <div class="flex items-start justify-between px-5 pt-5 pb-4 border-b">
                        <div>
                            <div class="flex items-center gap-2 mb-1">
                                <h2 class="font-bold text-base">Order #{{ modalRow.id }}</h2>
                                <span class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="typeBadge(modalRow.order_type)">
                                    {{ typeLabel(modalRow.order_type) }}
                                </span>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ modalRow.customer_name ?? (modalRow.table_number ? `Table ${modalRow.table_number}` : 'No customer info') }}
                            </p>
                        </div>
                        <button @click="closeModal"
                            class="rounded-lg p-1.5 hover:bg-muted text-muted-foreground hover:text-foreground transition-colors -mt-0.5 -mr-0.5">
                            <XIcon class="h-4 w-4" />
                        </button>
                    </div>

                    <!-- Order details -->
                    <div class="px-5 py-4 space-y-3">

                        <!-- Timestamps -->
                        <div class="grid grid-cols-2 gap-3 text-xs">
                            <div class="rounded-lg bg-muted/40 p-3">
                                <p class="text-muted-foreground mb-0.5 font-medium">Created</p>
                                <p class="font-semibold leading-snug">{{ fmtDateFull(modalRow.created_at) }}</p>
                            </div>
                            <div class="rounded-lg bg-muted/40 p-3">
                                <p class="text-muted-foreground mb-0.5 font-medium">Completed</p>
                                <p class="font-semibold leading-snug">{{ fmtDateFull(modalRow.completed_at) }}</p>
                            </div>
                        </div>

                        <!-- Current serving time display -->
                        <div class="rounded-xl border bg-muted/20 px-4 py-3 flex items-center justify-between">
                            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                                <Timer class="h-4 w-4" />
                                <span>Current serving time</span>
                            </div>
                            <span :class="servingTimeColor(modalRow.serving_minutes)" class="text-2xl font-black tabular-nums">
                                {{ formatMinutes(modalRow.serving_minutes) }}
                            </span>
                        </div>

                        <!-- Edit field -->
                        <div class="space-y-1.5">
                            <label class="text-xs font-semibold text-foreground block">
                                Adjust Serving Time
                            </label>
                            <div class="flex items-center gap-2">
                                <div class="relative flex-1">
                                    <input
                                        ref="modalInput"
                                        v-model="modalMinutes"
                                        type="number"
                                        step="0.1"
                                        min="0.1"
                                        max="1440"
                                        placeholder="e.g. 12.5"
                                        class="w-full rounded-lg border bg-background px-3 py-2.5 text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-primary pr-12"
                                        @keyup.enter="saveModal"
                                        @keyup.escape="closeModal" />
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground font-medium pointer-events-none">
                                        min
                                    </span>
                                </div>
                            </div>

                            <!-- Live preview -->
                            <p v-if="modalTimeBreakdown" class="text-xs text-muted-foreground flex items-center gap-1.5 pl-1">
                                <span class="text-muted-foreground/60">=</span>
                                <span :class="modalTimeColor" class="font-semibold">{{ modalTimeBreakdown }}</span>
                            </p>
                            <p v-else class="text-xs text-red-400 pl-1">Enter a valid duration in minutes</p>
                        </div>

                        <!-- Info note -->
                        <div class="rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 px-3 py-2.5 flex gap-2">
                            <AlertCircle class="h-3.5 w-3.5 text-amber-500 shrink-0 mt-0.5" />
                            <p class="text-xs text-amber-700 dark:text-amber-300 leading-relaxed">
                                This updates the <strong>completed_at</strong> timestamp of the order. The new time will be reflected in all serving time charts.
                            </p>
                        </div>
                    </div>

                    <!-- Modal footer -->
                    <div class="px-5 py-4 border-t bg-muted/10 flex items-center justify-end gap-2">
                        <button @click="closeModal" :disabled="modalSaving"
                            class="rounded-lg border px-4 py-2 text-sm font-medium hover:bg-muted disabled:opacity-50 transition-colors">
                            Cancel
                        </button>
                        <button @click="saveModal" :disabled="modalSaving || !modalTimeBreakdown"
                            class="rounded-lg bg-primary px-5 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-colors flex items-center gap-2">
                            <RefreshCw v-if="modalSaving" class="h-3.5 w-3.5 animate-spin" />
                            <Check v-else class="h-3.5 w-3.5" />
                            Save Changes
                        </button>
                    </div>

                </div>
            </Transition>
        </div>
    </Transition>
</Teleport>
</template>
