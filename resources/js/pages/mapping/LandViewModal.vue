<script setup lang="ts">
import { computed } from 'vue'
import { X } from 'lucide-vue-next'
import type { StallDay, DisplayStatus } from './types'

const props = defineProps<{
    open: boolean
    stalls: StallDay[]
    date: string
}>()
const emit = defineEmits<{ close: [] }>()

// ─── Layout: 11m wide × 3m deep, 5 equal stalls ──────────────────────────────
// SVG coordinate space: 1 unit = 1m. We'll render at W=550, H=150 (50px/m)
const PX   = 50          // pixels per metre
const LAND_W = 11 * PX   // 550
const LAND_H = 3  * PX   // 150
const N      = 5
const STALL_W = LAND_W / N  // 110
const PAD_L  = 50   // left margin for depth label
const PAD_T  = 30   // top margin for entrance label
const PAD_B  = 44   // bottom margin for width labels + arrow
const SVG_W  = LAND_W + PAD_L + 10
const SVG_H  = LAND_H + PAD_T + PAD_B

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
    return (t.business_name || t.name || '').slice(0, 14)
}

const fmtDate = (d: string) => {
    const dt = new Date(d + 'T00:00:00')
    return dt.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
}
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
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
            @click.self="emit('close')"
        >
            <Transition
                enter-from-class="opacity-0 scale-95 translate-y-2"
                enter-to-class="opacity-100 scale-100 translate-y-0"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 scale-95"
                enter-active-class="transition-all duration-200"
                leave-active-class="transition-all duration-150"
            >
                <div v-if="open" class="w-full max-w-2xl rounded-2xl bg-card border shadow-2xl overflow-hidden">
                    <!-- Header -->
                    <div class="flex items-center justify-between px-5 py-4 border-b">
                        <div>
                            <p class="font-semibold text-foreground">Land Overview</p>
                            <p class="text-xs text-muted-foreground">11 × 3 metres · 5 stalls · {{ fmtDate(date) }}</p>
                        </div>
                        <button @click="emit('close')" class="p-1.5 rounded-lg hover:bg-accent transition-colors">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <div class="p-5 space-y-4">
                        <!-- SVG plot -->
                        <div class="w-full overflow-x-auto">
                            <svg
                                :viewBox="`0 0 ${SVG_W} ${SVG_H}`"
                                class="w-full"
                                style="min-width: 380px; font-family: system-ui, sans-serif;"
                            >
                                <defs>
                                    <marker id="lv-arrow-h" viewBox="0 0 8 8" refX="4" refY="4" markerWidth="6" markerHeight="6" orient="auto">
                                        <path d="M0,1 L7,4 L0,7 Z" fill="#9ca3af"/>
                                    </marker>
                                    <marker id="lv-arrow-h-rev" viewBox="0 0 8 8" refX="4" refY="4" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                                        <path d="M0,1 L7,4 L0,7 Z" fill="#9ca3af"/>
                                    </marker>
                                    <marker id="lv-arrow-v" viewBox="0 0 8 8" refX="4" refY="4" markerWidth="6" markerHeight="6" orient="auto">
                                        <path d="M1,0 L4,7 L7,0 Z" fill="#9ca3af"/>
                                    </marker>
                                    <marker id="lv-arrow-v-rev" viewBox="0 0 8 8" refX="4" refY="4" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                                        <path d="M1,0 L4,7 L7,0 Z" fill="#9ca3af"/>
                                    </marker>
                                </defs>

                                <!-- Outer land boundary -->
                                <rect
                                    :x="PAD_L" :y="PAD_T"
                                    :width="LAND_W" :height="LAND_H"
                                    fill="#f8fafc" stroke="#94a3b8" stroke-width="2" rx="3"
                                />

                                <!-- Stall rectangles -->
                                <g v-for="(stall, i) in stalls" :key="stall.id">
                                    <rect
                                        :x="stallX(i) + 1" :y="PAD_T + 1"
                                        :width="STALL_W - 2" :height="LAND_H - 2"
                                        :fill="STATUS_PALETTE[stall.display_status].fill"
                                        :stroke="STATUS_PALETTE[stall.display_status].stroke"
                                        stroke-width="1.5"
                                        rx="2"
                                    />

                                    <!-- Stall number badge -->
                                    <circle
                                        :cx="stallX(i) + STALL_W / 2"
                                        :cy="PAD_T + 22"
                                        r="11"
                                        :fill="STATUS_PALETTE[stall.display_status].stroke"
                                        opacity="0.85"
                                    />
                                    <text
                                        :x="stallX(i) + STALL_W / 2"
                                        :y="PAD_T + 27"
                                        text-anchor="middle"
                                        font-size="11"
                                        font-weight="700"
                                        :fill="stall.display_status === 'reserved' ? '#78350f' : '#fff'"
                                    >{{ stall.number }}</text>

                                    <!-- Stall label -->
                                    <text
                                        :x="stallX(i) + STALL_W / 2"
                                        :y="PAD_T + 54"
                                        text-anchor="middle"
                                        font-size="9"
                                        font-weight="600"
                                        :fill="STATUS_PALETTE[stall.display_status].text"
                                        opacity="0.7"
                                    >{{ stall.label }}</text>

                                    <!-- Status label -->
                                    <text
                                        :x="stallX(i) + STALL_W / 2"
                                        :y="PAD_T + 70"
                                        text-anchor="middle"
                                        font-size="8"
                                        font-weight="600"
                                        :fill="STATUS_PALETTE[stall.display_status].text"
                                        text-transform="uppercase"
                                    >{{ STATUS_PALETTE[stall.display_status].label }}</text>

                                    <!-- Tenant name (if any) -->
                                    <text
                                        v-if="tenantLine(stall)"
                                        :x="stallX(i) + STALL_W / 2"
                                        :y="PAD_T + 90"
                                        text-anchor="middle"
                                        font-size="9"
                                        :fill="STATUS_PALETTE[stall.display_status].text"
                                    >{{ tenantLine(stall) }}</text>

                                    <!-- Stall width label at bottom -->
                                    <text
                                        :x="stallX(i) + STALL_W / 2"
                                        :y="PAD_T + LAND_H + 15"
                                        text-anchor="middle"
                                        font-size="8"
                                        fill="#6b7280"
                                    >2.2m</text>

                                    <!-- Vertical dividers -->
                                    <line v-if="i > 0"
                                        :x1="stallX(i)" :y1="PAD_T"
                                        :x2="stallX(i)" :y2="PAD_T + LAND_H"
                                        stroke="#94a3b8" stroke-width="1" stroke-dasharray="5,4"
                                    />
                                </g>

                                <!-- Entrance arrow at top -->
                                <text
                                    :x="PAD_L + LAND_W / 2" :y="PAD_T - 8"
                                    text-anchor="middle" font-size="9" fill="#6b7280" font-weight="500"
                                >⬇  ENTRANCE</text>

                                <!-- Total width dimension line -->
                                <line
                                    :x1="PAD_L" :y1="PAD_T + LAND_H + 28"
                                    :x2="PAD_L + LAND_W" :y2="PAD_T + LAND_H + 28"
                                    stroke="#9ca3af" stroke-width="1"
                                    marker-end="url(#lv-arrow-h)"
                                    marker-start="url(#lv-arrow-h-rev)"
                                />
                                <text
                                    :x="PAD_L + LAND_W / 2" :y="PAD_T + LAND_H + 42"
                                    text-anchor="middle" font-size="10" fill="#6b7280" font-weight="600"
                                >11 m</text>

                                <!-- Depth dimension line -->
                                <line
                                    :x1="PAD_L - 16" :y1="PAD_T"
                                    :x2="PAD_L - 16" :y2="PAD_T + LAND_H"
                                    stroke="#9ca3af" stroke-width="1"
                                    marker-end="url(#lv-arrow-v)"
                                    marker-start="url(#lv-arrow-v-rev)"
                                />
                                <text
                                    :x="PAD_L - 28"
                                    :y="PAD_T + LAND_H / 2"
                                    text-anchor="middle" font-size="10" fill="#6b7280" font-weight="600"
                                    :transform="`rotate(-90, ${PAD_L - 28}, ${PAD_T + LAND_H / 2})`"
                                >3 m</text>
                            </svg>
                        </div>

                        <!-- Legend -->
                        <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                            <span
                                v-for="(palette, key) in STATUS_PALETTE"
                                :key="key"
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <span
                                    class="inline-block w-3 h-3 rounded-sm border"
                                    :style="{ backgroundColor: palette.fill, borderColor: palette.stroke }"
                                ></span>
                                {{ palette.label }}
                            </span>
                        </div>

                        <!-- Per-stall summary -->
                        <div class="grid grid-cols-5 gap-1.5">
                            <div
                                v-for="stall in stalls"
                                :key="stall.id"
                                class="rounded-lg border p-2 text-center space-y-0.5"
                                :style="{ borderColor: STATUS_PALETTE[stall.display_status].stroke, backgroundColor: STATUS_PALETTE[stall.display_status].fill + '80' }"
                            >
                                <p class="text-xs font-bold" :style="{ color: STATUS_PALETTE[stall.display_status].text }">{{ stall.label }}</p>
                                <p class="text-[10px] font-medium truncate" :style="{ color: STATUS_PALETTE[stall.display_status].text }">
                                    {{ STATUS_PALETTE[stall.display_status].label }}
                                </p>
                                <p v-if="stall.schedule?.tenant" class="text-[10px] truncate" :style="{ color: STATUS_PALETTE[stall.display_status].text }">
                                    {{ stall.schedule.tenant.business_name || stall.schedule.tenant.name }}
                                </p>
                                <p v-else class="text-[10px]" :style="{ color: STATUS_PALETTE[stall.display_status].text + '80' }">—</p>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </div>
    </Transition>
</Teleport>
</template>
