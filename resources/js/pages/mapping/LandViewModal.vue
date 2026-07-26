<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { X, ChevronLeft, ChevronRight, Loader2 } from 'lucide-vue-next'
import type { StallDay, DisplayStatus, CalendarDay } from './types'
import type { PlotSettings } from './PlotSettingsModal.vue'

const props = defineProps<{
    open: boolean
    initialDate: string
    settings: PlotSettings
}>()
const emit = defineEmits<{ close: [] }>()

// ─── Internal state ───────────────────────────────────────────────────────────
const viewDate   = ref(props.initialDate)
const viewStalls = ref<StallDay[]>([])
const loadingDay = ref(false)
const calDays    = ref<Record<string, CalendarDay>>({})

const TODAY = () => new Date().toISOString().slice(0, 10)

// ─── API ─────────────────────────────────────────────────────────────────────
const apiFetch = async (url: string) => {
    const res = await fetch(url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    })
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    return res.json()
}

const loadDay = async () => {
    loadingDay.value = true
    try {
        viewStalls.value = await apiFetch(`/api/v1/rental/schedules/day?date=${viewDate.value}`)
    } catch {} finally {
        loadingDay.value = false
    }
}

const loadCalendarMonth = async (year: number, month: number) => {
    const key = `${year}-${String(month).padStart(2, '0')}`
    if (calDays.value[`${key}-01`] !== undefined) return
    try {
        const data = await apiFetch(`/api/v1/rental/schedules/calendar?year=${year}&month=${month}`)
        Object.assign(calDays.value, data)
    } catch {}
}

// ─── Open / date watchers ────────────────────────────────────────────────────
watch(() => props.open, (v) => {
    if (!v) return
    viewDate.value = props.initialDate
    calDays.value  = {}
    loadDay()
    const d = new Date(props.initialDate + 'T00:00:00')
    loadCalendarMonth(d.getFullYear(), d.getMonth() + 1)
}, { immediate: false })

watch(viewDate, (date) => {
    loadDay()
    const d = new Date(date + 'T00:00:00')
    loadCalendarMonth(d.getFullYear(), d.getMonth() + 1)
    const prev = new Date(d); prev.setDate(0)
    const next = new Date(d); next.setDate(1); next.setMonth(next.getMonth() + 1)
    loadCalendarMonth(prev.getFullYear(), prev.getMonth() + 1)
    loadCalendarMonth(next.getFullYear(), next.getMonth() + 1)
})

// ─── Navigation ──────────────────────────────────────────────────────────────
const shiftDate = (n: number) => {
    const d = new Date(viewDate.value + 'T00:00:00')
    d.setDate(d.getDate() + n)
    viewDate.value = d.toISOString().slice(0, 10)
}

const goToday = () => { viewDate.value = TODAY() }

// ─── 7-day strip ─────────────────────────────────────────────────────────────
const stripDates = computed(() =>
    Array.from({ length: 7 }, (_, i) => {
        const d = new Date(viewDate.value + 'T00:00:00')
        d.setDate(d.getDate() + (i - 3))
        return d.toISOString().slice(0, 10)
    })
)

const stripDots = (date: string): string[] => {
    const day = calDays.value[date]
    if (!day) return Array(5).fill('unknown')
    const dots: string[] = []
    for (let i = 0; i < day.occupied;    i++) dots.push('occupied')
    for (let i = 0; i < day.reserved;    i++) dots.push('reserved')
    for (let i = 0; i < day.maintenance; i++) dots.push('maintenance')
    while (dots.length < 5) dots.push('available')
    return dots.slice(0, 5)
}

const dotColor = (status: string) => {
    switch (status) {
        case 'occupied':    return '#3b82f6'
        case 'reserved':    return '#f59e0b'
        case 'maintenance': return '#ef4444'
        case 'available':   return '#34d399'
        default:            return '#d1d5db'
    }
}

const stripDayLabel = (date: string) =>
    new Date(date + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'short' })

const stripDayNum = (date: string) => parseInt(date.slice(8))

// ─── Display helpers ──────────────────────────────────────────────────────────
const fmtViewDate = computed(() => {
    const today     = TODAY()
    const d         = new Date(today + 'T00:00:00')
    const tomorrow  = new Date(d); tomorrow.setDate(d.getDate() + 1)
    const yesterday = new Date(d); yesterday.setDate(d.getDate() - 1)
    if (viewDate.value === today)     return 'Today'
    if (viewDate.value === tomorrow.toISOString().slice(0, 10))  return 'Tomorrow'
    if (viewDate.value === yesterday.toISOString().slice(0, 10)) return 'Yesterday'
    return new Date(viewDate.value + 'T00:00:00').toLocaleDateString('en-PH', {
        weekday: 'short', month: 'short', day: 'numeric', year: 'numeric',
    })
})

const isPast   = computed(() => viewDate.value < TODAY())
const isFuture = computed(() => viewDate.value > TODAY())
const isToday  = computed(() => viewDate.value === TODAY())

// ─── SVG layout ──────────────────────────────────────────────────────────────
const PX      = 50
const LAND_W  = 11 * PX
const LAND_H  = 3  * PX
const STALL_W = LAND_W / 5
const PAD_L   = 50
const PAD_T   = 30
const PAD_B   = 44
const SVG_W   = LAND_W + PAD_L + 10
const SVG_H   = LAND_H + PAD_T + PAD_B

const STATUS_PALETTE: Record<DisplayStatus, { fill: string; stroke: string; text: string; label: string }> = {
    available:   { fill: '#d1fae5', stroke: '#34d399', text: '#065f46', label: 'Available'   },
    occupied:    { fill: '#bfdbfe', stroke: '#3b82f6', text: '#1e3a8a', label: 'Occupied'    },
    reserved:    { fill: '#fef3c7', stroke: '#f59e0b', text: '#78350f', label: 'Reserved'    },
    maintenance: { fill: '#fee2e2', stroke: '#ef4444', text: '#7f1d1d', label: 'Maintenance' },
    inactive:    { fill: '#f3f4f6', stroke: '#d1d5db', text: '#9ca3af', label: 'Inactive'    },
}

const stallX = (i: number) => PAD_L + i * STALL_W

const tenantLine = (stall: StallDay) => {
    const t = stall.schedule?.tenant
    if (!t) return ''
    return (t.business_name || t.name || '').slice(0, 13)
}

const badgeTextColor = (status: DisplayStatus) =>
    status === 'reserved' ? '#78350f' : status === 'inactive' ? '#9ca3af' : '#ffffff'
</script>

<template>
<Teleport to="body">
    <Transition
        enter-from-class="opacity-0" enter-to-class="opacity-100"
        leave-from-class="opacity-100" leave-to-class="opacity-0"
        enter-active-class="transition-opacity duration-200"
        leave-active-class="transition-opacity duration-150"
    >
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-4 bg-black/60 backdrop-blur-sm"
            @click.self="emit('close')"
        >
            <Transition
                enter-from-class="opacity-0 translate-y-4 sm:scale-95 sm:translate-y-0"
                enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                leave-to-class="opacity-0 translate-y-4 sm:scale-95 sm:translate-y-0"
                enter-active-class="transition-all duration-200"
                leave-active-class="transition-all duration-150"
            >
                <div v-if="open"
                    class="w-full sm:max-w-2xl rounded-t-2xl sm:rounded-2xl bg-card border-t sm:border shadow-2xl flex flex-col"
                    style="max-height: 92dvh;"
                >
                    <!-- ── Header ──────────────────────────────────────────── -->
                    <div class="flex items-center justify-between px-4 sm:px-5 py-3.5 border-b flex-shrink-0">
                        <div>
                            <p class="font-semibold text-foreground">Land Overview</p>
                            <p class="text-xs text-muted-foreground">11 × 3 metres · 5 stalls</p>
                        </div>
                        <button type="button" @click="emit('close')"
                            class="p-2 rounded-lg hover:bg-accent transition-colors touch-manipulation">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto">
                        <div class="px-4 sm:px-5 py-4 space-y-4">

                            <!-- ── Date controller ─────────────────────────── -->
                            <div class="space-y-2">
                                <!-- Prev / label / Next -->
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="shiftDate(-1)"
                                        class="rounded-xl border p-2.5 hover:bg-accent active:scale-95 transition-all touch-manipulation flex-shrink-0"
                                        aria-label="Previous day">
                                        <ChevronLeft class="w-4 h-4" />
                                    </button>

                                    <button type="button" @click="goToday"
                                        class="flex-1 text-center min-w-0 py-1 rounded-xl hover:bg-accent/50 active:bg-accent transition-colors touch-manipulation"
                                        :title="isToday ? 'Viewing today' : 'Jump to today'"
                                    >
                                        <p class="text-sm font-semibold leading-tight" :class="[
                                            isPast ? 'text-muted-foreground' : isFuture ? 'text-blue-600 dark:text-blue-400' : 'text-foreground'
                                        ]">{{ fmtViewDate }}</p>
                                        <p class="text-[11px] text-muted-foreground mt-0.5">{{ viewDate }}</p>
                                    </button>

                                    <button type="button" @click="shiftDate(1)"
                                        class="rounded-xl border p-2.5 hover:bg-accent active:scale-95 transition-all touch-manipulation flex-shrink-0"
                                        aria-label="Next day">
                                        <ChevronRight class="w-4 h-4" />
                                    </button>
                                </div>

                                <!-- Badge row -->
                                <div v-if="!isToday" class="flex items-center justify-center gap-2">
                                    <span v-if="isPast"
                                        class="text-[10px] rounded-full bg-muted px-2.5 py-0.5 text-muted-foreground font-medium">
                                        past
                                    </span>
                                    <span v-if="isFuture"
                                        class="text-[10px] rounded-full bg-blue-100 dark:bg-blue-900/40 px-2.5 py-0.5 text-blue-600 dark:text-blue-400 font-medium">
                                        upcoming
                                    </span>
                                    <button type="button" @click="goToday"
                                        class="text-[11px] rounded-full border px-3 py-0.5 hover:bg-accent transition-colors touch-manipulation font-medium">
                                        Back to Today
                                    </button>
                                </div>
                            </div>

                            <!-- ── 7-day strip ─────────────────────────────── -->
                            <div class="flex gap-1.5 overflow-x-auto pb-0.5 -mx-1 px-1">
                                <button
                                    v-for="date in stripDates"
                                    :key="date"
                                    type="button"
                                    @click="viewDate = date"
                                    class="flex-1 min-w-[44px] flex flex-col items-center gap-1 rounded-xl px-1 py-2.5 transition-all border touch-manipulation"
                                    :class="[
                                        date === viewDate
                                            ? 'bg-primary text-primary-foreground border-primary shadow-sm'
                                            : date === TODAY()
                                                ? 'border-primary/40 bg-primary/5 hover:bg-primary/10'
                                                : 'border-transparent hover:bg-accent',
                                    ]"
                                >
                                    <span class="font-medium text-[10px] leading-none"
                                        :class="date === viewDate ? 'text-primary-foreground' : 'text-muted-foreground'">
                                        {{ stripDayLabel(date) }}
                                    </span>
                                    <span class="font-bold text-xs leading-none"
                                        :class="date === viewDate ? 'text-primary-foreground' : 'text-foreground'">
                                        {{ stripDayNum(date) }}
                                    </span>
                                    <div class="flex gap-0.5 mt-0.5">
                                        <span
                                            v-for="(dot, di) in stripDots(date)"
                                            :key="di"
                                            class="inline-block w-1.5 h-1.5 rounded-full"
                                            :style="{
                                                backgroundColor: date === viewDate ? 'rgba(255,255,255,0.7)' : dotColor(dot)
                                            }"
                                        />
                                    </div>
                                </button>
                            </div>

                            <!-- ── SVG plot ─────────────────────────────────── -->
                            <div class="relative">
                                <div v-if="loadingDay"
                                    class="absolute inset-0 flex items-center justify-center bg-card/80 rounded-xl z-10">
                                    <Loader2 class="w-6 h-6 animate-spin text-primary" />
                                </div>
                                <div class="w-full overflow-x-auto rounded-xl" :class="{ 'opacity-40': loadingDay }">
                                    <svg
                                        :viewBox="`0 0 ${SVG_W} ${SVG_H}`"
                                        class="w-full"
                                        style="min-width: 320px; font-family: system-ui, sans-serif;"
                                    >
                                        <defs>
                                            <marker id="lv-ah"  viewBox="0 0 8 8" refX="4" refY="4" markerWidth="5" markerHeight="5" orient="auto"><path d="M0,1 L7,4 L0,7 Z" fill="#9ca3af"/></marker>
                                            <marker id="lv-ahr" viewBox="0 0 8 8" refX="4" refY="4" markerWidth="5" markerHeight="5" orient="auto-start-reverse"><path d="M0,1 L7,4 L0,7 Z" fill="#9ca3af"/></marker>
                                            <marker id="lv-av"  viewBox="0 0 8 8" refX="4" refY="4" markerWidth="5" markerHeight="5" orient="auto"><path d="M1,0 L4,7 L7,0 Z" fill="#9ca3af"/></marker>
                                            <marker id="lv-avr" viewBox="0 0 8 8" refX="4" refY="4" markerWidth="5" markerHeight="5" orient="auto-start-reverse"><path d="M1,0 L4,7 L7,0 Z" fill="#9ca3af"/></marker>
                                        </defs>

                                        <!-- Outer land boundary -->
                                        <rect :x="PAD_L" :y="PAD_T" :width="LAND_W" :height="LAND_H"
                                            fill="#f8fafc" stroke="#94a3b8" stroke-width="2" rx="3"/>

                                        <!-- Entrance -->
                                        <text :x="PAD_L + LAND_W / 2" :y="PAD_T - 8"
                                            text-anchor="middle" font-size="9" fill="#6b7280" font-weight="500"
                                        >⬇  ENTRANCE</text>

                                        <!-- Stalls -->
                                        <g v-for="(stall, i) in viewStalls" :key="stall.id">
                                            <rect
                                                :x="stallX(i) + 1" :y="PAD_T + 1"
                                                :width="STALL_W - 2" :height="LAND_H - 2"
                                                :fill="STATUS_PALETTE[stall.display_status].fill"
                                                :stroke="STATUS_PALETTE[stall.display_status].stroke"
                                                stroke-width="1.5" rx="2"
                                            />

                                            <!-- Number badge -->
                                            <g v-if="settings.showStallNumbers">
                                                <circle
                                                    :cx="stallX(i) + STALL_W / 2" :cy="PAD_T + 22" r="11"
                                                    :fill="STATUS_PALETTE[stall.display_status].stroke" opacity="0.85"
                                                />
                                                <text
                                                    :x="stallX(i) + STALL_W / 2" :y="PAD_T + 27"
                                                    text-anchor="middle" font-size="11" font-weight="700"
                                                    :fill="badgeTextColor(stall.display_status)"
                                                >{{ stall.number }}</text>
                                            </g>

                                            <!-- Stall label -->
                                            <text
                                                :x="stallX(i) + STALL_W / 2"
                                                :y="settings.showStallNumbers ? PAD_T + 50 : PAD_T + 30"
                                                text-anchor="middle" font-size="9" font-weight="600"
                                                :fill="STATUS_PALETTE[stall.display_status].text" opacity="0.8"
                                            >{{ stall.label }}</text>

                                            <!-- Status label -->
                                            <text v-if="settings.showStatusLabel"
                                                :x="stallX(i) + STALL_W / 2"
                                                :y="settings.showStallNumbers ? PAD_T + 66 : PAD_T + 46"
                                                text-anchor="middle" font-size="8" font-weight="600"
                                                :fill="STATUS_PALETTE[stall.display_status].text"
                                            >{{ STATUS_PALETTE[stall.display_status].label }}</text>

                                            <!-- Tenant name -->
                                            <text v-if="settings.showTenantNames && tenantLine(stall)"
                                                :x="stallX(i) + STALL_W / 2"
                                                :y="PAD_T + LAND_H - 16"
                                                text-anchor="middle" font-size="9"
                                                :fill="STATUS_PALETTE[stall.display_status].text"
                                            >{{ tenantLine(stall) }}</text>

                                            <!-- Width label -->
                                            <text v-if="settings.showDimensions"
                                                :x="stallX(i) + STALL_W / 2" :y="PAD_T + LAND_H + 15"
                                                text-anchor="middle" font-size="8" fill="#6b7280"
                                            >2.2m</text>

                                            <!-- Vertical dividers -->
                                            <line v-if="i > 0"
                                                :x1="stallX(i)" :y1="PAD_T"
                                                :x2="stallX(i)" :y2="PAD_T + LAND_H"
                                                stroke="#94a3b8" stroke-width="1" stroke-dasharray="5,4"
                                            />
                                        </g>

                                        <!-- Dimension arrows -->
                                        <g v-if="settings.showDimensions">
                                            <line :x1="PAD_L" :y1="PAD_T + LAND_H + 28" :x2="PAD_L + LAND_W" :y2="PAD_T + LAND_H + 28"
                                                stroke="#9ca3af" stroke-width="1" marker-end="url(#lv-ah)" marker-start="url(#lv-ahr)"/>
                                            <text :x="PAD_L + LAND_W / 2" :y="PAD_T + LAND_H + 41"
                                                text-anchor="middle" font-size="10" fill="#6b7280" font-weight="600">11 m</text>
                                            <line :x1="PAD_L - 16" :y1="PAD_T" :x2="PAD_L - 16" :y2="PAD_T + LAND_H"
                                                stroke="#9ca3af" stroke-width="1" marker-end="url(#lv-av)" marker-start="url(#lv-avr)"/>
                                            <text
                                                :x="PAD_L - 30" :y="PAD_T + LAND_H / 2"
                                                text-anchor="middle" font-size="10" fill="#6b7280" font-weight="600"
                                                :transform="`rotate(-90, ${PAD_L - 30}, ${PAD_T + LAND_H / 2})`"
                                            >3 m</text>
                                        </g>
                                    </svg>
                                </div>
                            </div>

                            <!-- ── Legend ─────────────────────────────────── -->
                            <div class="flex flex-wrap gap-x-4 gap-y-1.5 pb-2">
                                <span v-for="(palette, key) in STATUS_PALETTE" :key="key"
                                    class="flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <span class="inline-block w-3 h-3 rounded-sm border flex-shrink-0"
                                        :style="{ backgroundColor: palette.fill, borderColor: palette.stroke }"></span>
                                    {{ palette.label }}
                                </span>
                            </div>

                        </div>
                    </div>
                </div>
            </Transition>
        </div>
    </Transition>
</Teleport>
</template>
