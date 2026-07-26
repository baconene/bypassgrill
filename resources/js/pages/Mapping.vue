<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { Map, RefreshCw, Users, ChevronLeft, ChevronRight, LayoutGrid, CalendarDays, AlignLeft, Eye, Settings2 } from 'lucide-vue-next'
import { toast } from 'vue-sonner'
import StatCards from './mapping/StatCards.vue'
import Legend from './mapping/Legend.vue'
import DateNavigator from './mapping/DateNavigator.vue'
import StallMap from './mapping/StallMap.vue'
import CalendarView from './mapping/CalendarView.vue'
import TimelineView from './mapping/TimelineView.vue'
import ScheduleDrawer from './mapping/ScheduleDrawer.vue'
import ScheduleModal from './mapping/ScheduleModal.vue'
import TenantModal from './mapping/TenantModal.vue'
import LandViewModal from './mapping/LandViewModal.vue'
import PlotSettingsModal from './mapping/PlotSettingsModal.vue'
import type { PlotSettings } from './mapping/PlotSettingsModal.vue'
import type { StallDay, RentalTenant, RentalSchedule, Stats } from './mapping/types'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Stall Mapping', href: '/mapping' },
        ],
    },
})

type ViewMode = 'map' | 'calendar' | 'timeline'

// ─── State ────────────────────────────────────────────────────────────────────
const view         = ref<ViewMode>('map')
const selectedDate = ref(new Date().toISOString().slice(0, 10))
const calYear      = ref(new Date().getFullYear())
const calMonth     = ref(new Date().getMonth() + 1)

const tlDateFrom = ref(new Date().toISOString().slice(0, 10))
const tlDateTo   = computed(() => {
    const d = new Date(tlDateFrom.value + 'T00:00:00')
    d.setDate(d.getDate() + 13)
    return d.toISOString().slice(0, 10)
})

// Data
const stalls        = ref<StallDay[]>([])
const stallsLoading = ref(false)
const calendarDays  = ref<Record<string, any>>({})
const calLoading    = ref(false)
const timelineData  = ref<{ stalls: any[]; date_from: string; date_to: string } | null>(null)
const tlLoading     = ref(false)
const tenants       = ref<RentalTenant[]>([])
const stats         = ref<Stats | null>(null)

// Drawer
const drawerOpen  = ref(false)
const drawerStall = ref<StallDay | null>(null)

// Schedule modal
const scheduleModalOpen = ref(false)
const editSchedule      = ref<RentalSchedule | null>(null)
const scheduleSaving    = ref(false)

// Tenant modal
const tenantModalOpen  = ref(false)
const editTenant       = ref<RentalTenant | null>(null)
const tenantSaving     = ref(false)
const showTenantsPanel = ref(false)

// Land view modal
const landViewOpen = ref(false)

// Plot settings
const SETTINGS_KEY = 'mapping_plot_settings'
const defaultSettings: PlotSettings = { showDimensions: true, showTenantNames: true, showStallNumbers: true, showStatusLabel: true }
const loadStoredSettings = (): PlotSettings => {
    try {
        const raw = localStorage.getItem(SETTINGS_KEY)
        return raw ? { ...defaultSettings, ...JSON.parse(raw) } : { ...defaultSettings }
    } catch { return { ...defaultSettings } }
}
const plotSettings     = ref<PlotSettings>(loadStoredSettings())
const plotSettingsOpen = ref(false)

watch(plotSettings, (v) => {
    try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(v)) } catch {}
}, { deep: true })

// ─── API helpers ─────────────────────────────────────────────────────────────
const apiFetch = async (url: string, options?: RequestInit) => {
    const res = await fetch(url, {
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        ...options,
    })
    if (!res.ok) {
        const err = await res.json().catch(() => ({}))
        throw new Error(err.message ?? `HTTP ${res.status}`)
    }
    return res.status === 204 ? {} : res.json()
}

// ─── Loaders ─────────────────────────────────────────────────────────────────
const loadStalls = async () => {
    stallsLoading.value = true
    try {
        stalls.value = await apiFetch(`/api/v1/rental/schedules/day?date=${selectedDate.value}`)
    } catch (e: any) {
        toast.error(e.message)
    } finally {
        stallsLoading.value = false
    }
}

const loadCalendar = async () => {
    calLoading.value = true
    try {
        calendarDays.value = await apiFetch(`/api/v1/rental/schedules/calendar?year=${calYear.value}&month=${calMonth.value}`)
    } catch (e: any) {
        toast.error(e.message)
    } finally {
        calLoading.value = false
    }
}

const loadTimeline = async () => {
    tlLoading.value = true
    try {
        timelineData.value = await apiFetch(`/api/v1/rental/schedules/timeline?date_from=${tlDateFrom.value}&date_to=${tlDateTo.value}`)
    } catch (e: any) {
        toast.error(e.message)
    } finally {
        tlLoading.value = false
    }
}

const loadTenants = async () => {
    try { tenants.value = await apiFetch('/api/v1/rental/tenants') } catch {}
}
const loadStats = async () => {
    try { stats.value = await apiFetch('/api/v1/rental/stats') } catch {}
}

const refreshAll = () => {
    loadStalls(); loadStats(); loadTenants()
    if (view.value === 'calendar') loadCalendar()
    if (view.value === 'timeline') loadTimeline()
}

// ─── Watchers ─────────────────────────────────────────────────────────────────
watch(selectedDate, loadStalls)

// Batch year+month changes — fire loadCalendar only once per flush
watch([calYear, calMonth], () => loadCalendar(), { flush: 'post' })

watch(tlDateFrom, loadTimeline)

watch(view, (v) => {
    if (v === 'calendar') { loadCalendar(); loadStalls() }
    if (v === 'timeline') loadTimeline()
})

// ─── Drawer ───────────────────────────────────────────────────────────────────
const openDrawer = (stall: StallDay) => {
    drawerStall.value = stall
    drawerOpen.value  = true
}

// ─── Calendar date click — filter stall grid in place ────────────────────────
const onCalendarDateClick = (date: string) => {
    selectedDate.value = date
    // Sync calendar month/year if user clicked a date from a different month (shouldn't happen but guard it)
    const d = new Date(date + 'T00:00:00')
    if (d.getFullYear() !== calYear.value || d.getMonth() + 1 !== calMonth.value) {
        calYear.value  = d.getFullYear()
        calMonth.value = d.getMonth() + 1
    }
    // Stay on calendar view — stalls re-load automatically via watch(selectedDate)
}

// ─── Schedule CRUD ────────────────────────────────────────────────────────────
const openNewSchedule = () => {
    editSchedule.value = null
    scheduleModalOpen.value = true
}

const openEditSchedule = (s: RentalSchedule) => {
    drawerOpen.value        = false
    editSchedule.value      = s
    scheduleModalOpen.value = true
}

const saveSchedule = async (data: Partial<RentalSchedule> & { stall_id?: number }) => {
    scheduleSaving.value = true
    try {
        if (editSchedule.value) {
            await apiFetch(`/api/v1/rental/schedules/${editSchedule.value.id}`, { method: 'PUT', body: JSON.stringify(data) })
            toast.success('Schedule updated')
        } else {
            await apiFetch('/api/v1/rental/schedules', { method: 'POST', body: JSON.stringify(data) })
            toast.success('Schedule created')
        }
        scheduleModalOpen.value = false
        drawerOpen.value        = false
        refreshAll()
    } catch (e: any) {
        toast.error(e.message)
    } finally {
        scheduleSaving.value = false
    }
}

const deleteSchedule = async (id: number) => {
    if (!confirm('Delete this schedule?')) return
    try {
        await apiFetch(`/api/v1/rental/schedules/${id}`, { method: 'DELETE' })
        toast.success('Schedule deleted')
        scheduleModalOpen.value = false
        drawerOpen.value        = false
        refreshAll()
    } catch (e: any) {
        toast.error(e.message)
    }
}

// ─── Tenant CRUD ──────────────────────────────────────────────────────────────
const openNewTenant   = () => { editTenant.value = null; tenantModalOpen.value = true }
const openEditTenant  = (t: RentalTenant) => { editTenant.value = t; tenantModalOpen.value = true }

const saveTenant = async (data: Partial<RentalTenant>) => {
    tenantSaving.value = true
    try {
        if (editTenant.value) {
            await apiFetch(`/api/v1/rental/tenants/${editTenant.value.id}`, { method: 'PUT', body: JSON.stringify(data) })
            toast.success('Tenant updated')
        } else {
            await apiFetch('/api/v1/rental/tenants', { method: 'POST', body: JSON.stringify(data) })
            toast.success('Tenant created')
        }
        tenantModalOpen.value = false
        loadTenants()
    } catch (e: any) {
        toast.error(e.message)
    } finally {
        tenantSaving.value = false
    }
}

const deleteTenant = async (id: number) => {
    if (!confirm('Delete this tenant? This cannot be undone.')) return
    try {
        await apiFetch(`/api/v1/rental/tenants/${id}`, { method: 'DELETE' })
        toast.success('Tenant deleted')
        tenantModalOpen.value = false
        loadTenants()
    } catch (e: any) {
        toast.error(e.message)
    }
}

// ─── Timeline ────────────────────────────────────────────────────────────────
const onTimelineScheduleClick = (s: RentalSchedule) => openEditSchedule(s)

const tlShift = (days: number) => {
    const d = new Date(tlDateFrom.value + 'T00:00:00')
    d.setDate(d.getDate() + days)
    tlDateFrom.value = d.toISOString().slice(0, 10)
}

// ─── Formatted selected date for display ─────────────────────────────────────
const fmtSelectedDate = computed(() => {
    const d = new Date(selectedDate.value + 'T00:00:00')
    const today = new Date(); today.setHours(0,0,0,0)
    if (d.getTime() === today.getTime()) return 'Today'
    return d.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' })
})

onMounted(() => {
    loadStalls()
    loadStats()
    loadTenants()
})
</script>

<template>
<div class="p-4 sm:p-6 space-y-5 max-w-screen-xl mx-auto">

    <!-- Page header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="flex items-center gap-2.5">
            <div class="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center">
                <Map class="w-4 h-4 text-primary" />
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-lg font-bold text-foreground leading-tight">Stall Mapping</h1>
                    <button
                        @click="landViewOpen = true"
                        class="flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs text-muted-foreground hover:text-foreground hover:bg-accent transition-colors border"
                        title="View land plot"
                    >
                        <Eye class="w-3.5 h-3.5" />
                        <span class="hidden sm:inline">Plot</span>
                    </button>
                    <button
                        @click="plotSettingsOpen = true"
                        class="flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs text-muted-foreground hover:text-foreground hover:bg-accent transition-colors border"
                        title="Plot settings"
                    >
                        <Settings2 class="w-3.5 h-3.5" />
                        <span class="hidden sm:inline">Settings</span>
                    </button>
                </div>
                <p class="text-xs text-muted-foreground">Food stall rental scheduler</p>
            </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            <!-- View toggle -->
            <div class="flex rounded-lg border overflow-hidden text-sm">
                <button @click="view = 'map'"
                    :class="['px-3 py-1.5 transition-colors flex items-center gap-1.5',
                        view === 'map' ? 'bg-primary text-primary-foreground' : 'hover:bg-accent text-foreground']">
                    <LayoutGrid class="w-3.5 h-3.5" /> Map
                </button>
                <button @click="view = 'calendar'"
                    :class="['px-3 py-1.5 transition-colors flex items-center gap-1.5 border-l',
                        view === 'calendar' ? 'bg-primary text-primary-foreground' : 'hover:bg-accent text-foreground']">
                    <CalendarDays class="w-3.5 h-3.5" /> Calendar
                </button>
                <button @click="view = 'timeline'"
                    :class="['px-3 py-1.5 transition-colors flex items-center gap-1.5 border-l',
                        view === 'timeline' ? 'bg-primary text-primary-foreground' : 'hover:bg-accent text-foreground']">
                    <AlignLeft class="w-3.5 h-3.5" /> Timeline
                </button>
            </div>

            <!-- Tenants panel toggle -->
            <button
                @click="showTenantsPanel = !showTenantsPanel"
                :class="['flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm transition-colors',
                    showTenantsPanel ? 'bg-primary text-primary-foreground' : 'hover:bg-accent']"
            >
                <Users class="w-3.5 h-3.5" />
                Tenants
            </button>

            <button
                @click="refreshAll"
                class="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm hover:bg-accent transition-colors"
            >
                <RefreshCw class="w-3.5 h-3.5" />
                Refresh
            </button>
        </div>
    </div>

    <!-- Stats -->
    <StatCards :stats="stats" />

    <!-- Tenants panel (collapsible) -->
    <Transition
        enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0"
        leave-from-class="opacity-100 translate-y-0" leave-to-class="opacity-0 -translate-y-2"
        enter-active-class="transition-all duration-200"
        leave-active-class="transition-all duration-150"
    >
        <div v-if="showTenantsPanel" class="rounded-xl border bg-card p-4 space-y-3">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-foreground">Tenants</p>
                <button @click="openNewTenant"
                    class="rounded-lg bg-primary text-primary-foreground px-3 py-1.5 text-xs font-medium hover:bg-primary/90 transition-colors">
                    + New Tenant
                </button>
            </div>
            <div v-if="tenants.length === 0" class="text-sm text-muted-foreground py-2">No tenants yet.</div>
            <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                <button
                    v-for="t in tenants" :key="t.id"
                    @click="openEditTenant(t)"
                    class="text-left rounded-lg border bg-muted/20 px-3 py-2.5 hover:bg-accent transition-colors"
                >
                    <p class="text-sm font-medium text-foreground truncate">{{ t.business_name || t.name }}</p>
                    <p v-if="t.business_name" class="text-xs text-muted-foreground truncate">{{ t.name }}</p>
                    <p v-if="t.contact_number" class="text-xs text-muted-foreground">{{ t.contact_number }}</p>
                </button>
            </div>
        </div>
    </Transition>

    <!-- ── MAP VIEW ─────────────────────────────────────────────────────────── -->
    <div v-if="view === 'map'" class="space-y-3">
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 justify-between">
            <DateNavigator :date="selectedDate" @update:date="selectedDate = $event" />
            <Legend />
        </div>
        <StallMap :stalls="stalls" :loading="stallsLoading" @stall-click="openDrawer" />
    </div>

    <!-- ── CALENDAR VIEW ─────────────────────────────────────────────────────── -->
    <div v-else-if="view === 'calendar'" class="space-y-4">
        <div class="grid grid-cols-1 lg:grid-cols-[auto,1fr] gap-4 items-start">

            <!-- Calendar on the left -->
            <div class="w-full lg:w-72 xl:w-80 flex-shrink-0">
                <CalendarView
                    :year="calYear"
                    :month="calMonth"
                    :days="calendarDays"
                    :loading="calLoading"
                    :selected-date="selectedDate"
                    @update:year="calYear = $event"
                    @update:month="calMonth = $event"
                    @date-click="onCalendarDateClick"
                />
                <p class="text-xs text-muted-foreground mt-2 px-1">
                    Click a date to view stall status for that day.
                </p>
            </div>

            <!-- Stall grid on the right — filtered by selected date -->
            <div class="space-y-3 min-w-0">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-foreground">
                            Stall status for
                            <span class="text-primary">{{ fmtSelectedDate }}</span>
                        </p>
                        <p class="text-xs text-muted-foreground">{{ selectedDate }}</p>
                    </div>
                    <Legend />
                </div>
                <StallMap :stalls="stalls" :loading="stallsLoading" @stall-click="openDrawer" />
            </div>
        </div>
    </div>

    <!-- ── TIMELINE VIEW ─────────────────────────────────────────────────────── -->
    <div v-else-if="view === 'timeline'" class="space-y-3">
        <div class="flex items-center gap-2">
            <button @click="tlShift(-7)" class="rounded-lg border p-1.5 hover:bg-accent transition-colors">
                <ChevronLeft class="w-4 h-4" />
            </button>
            <span class="text-sm text-muted-foreground font-medium">{{ tlDateFrom }} – {{ tlDateTo }}</span>
            <button @click="tlShift(7)" class="rounded-lg border p-1.5 hover:bg-accent transition-colors">
                <ChevronRight class="w-4 h-4" />
            </button>
        </div>
        <TimelineView
            :stalls="timelineData?.stalls ?? []"
            :date-from="tlDateFrom"
            :date-to="tlDateTo"
            :loading="tlLoading"
            @schedule-click="onTimelineScheduleClick"
        />
    </div>

</div>

<!-- ── MODALS & DRAWERS ───────────────────────────────────────────────────── -->

<ScheduleDrawer
    :open="drawerOpen"
    :stall="drawerStall"
    @close="drawerOpen = false"
    @new-schedule="openNewSchedule"
    @edit-schedule="openEditSchedule"
/>

<ScheduleModal
    :open="scheduleModalOpen"
    :stall="drawerStall"
    :schedule="editSchedule"
    :tenants="tenants"
    :saving="scheduleSaving"
    @close="scheduleModalOpen = false"
    @save="saveSchedule"
    @delete="deleteSchedule"
/>

<TenantModal
    :open="tenantModalOpen"
    :tenant="editTenant"
    :saving="tenantSaving"
    @close="tenantModalOpen = false"
    @save="saveTenant"
    @delete="deleteTenant"
/>

<LandViewModal
    :open="landViewOpen"
    :initial-date="selectedDate"
    :settings="plotSettings"
    @close="landViewOpen = false"
/>

<PlotSettingsModal
    :open="plotSettingsOpen"
    :settings="plotSettings"
    @close="plotSettingsOpen = false"
    @update:settings="plotSettings = $event"
    @stalls-updated="refreshAll"
/>

</template>
