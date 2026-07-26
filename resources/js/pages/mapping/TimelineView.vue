<script setup lang="ts">
import { computed } from 'vue'
import { Loader2 } from 'lucide-vue-next'
import type { RentalSchedule } from './types'

interface TimelineStall {
    id: number
    number: number
    label: string
    is_active: boolean
    schedules: RentalSchedule[]
}

const props = defineProps<{
    stalls: TimelineStall[]
    dateFrom: string
    dateTo: string
    loading: boolean
}>()

const emit = defineEmits<{ 'schedule-click': [s: RentalSchedule] }>()

const dates = computed(() => {
    const out: string[] = []
    const cursor = new Date(props.dateFrom + 'T00:00:00')
    const end    = new Date(props.dateTo + 'T00:00:00')
    while (cursor <= end) {
        out.push(cursor.toISOString().slice(0, 10))
        cursor.setDate(cursor.getDate() + 1)
    }
    return out
})

const today = new Date().toISOString().slice(0, 10)

const schedulesOnDate = (stall: TimelineStall, date: string) =>
    stall.schedules.filter(s => s.start_date <= date && s.end_date >= date && s.status !== 'cancelled')

const scheduleColor = (status: string) => {
    switch (status) {
        case 'confirmed':   return 'bg-blue-500 text-white'
        case 'reserved':    return 'bg-amber-400 text-amber-900'
        case 'maintenance': return 'bg-red-400 text-white'
        default:            return 'bg-muted text-muted-foreground'
    }
}

const isStart = (s: RentalSchedule, date: string) => s.start_date === date
const isEnd   = (s: RentalSchedule, date: string) => s.end_date === date

const fmtDate = (d: string) => {
    const dt = new Date(d + 'T00:00:00')
    return dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
}
</script>

<template>
<div class="rounded-xl border bg-card overflow-hidden">
    <div v-if="loading" class="flex items-center justify-center py-12">
        <Loader2 class="w-5 h-5 animate-spin text-muted-foreground" />
    </div>

    <div v-else class="overflow-x-auto">
        <table class="w-full text-xs border-collapse min-w-[600px]">
            <thead>
                <tr class="border-b">
                    <th class="text-left px-3 py-2 font-medium text-muted-foreground w-20 min-w-[80px] sticky left-0 bg-card z-10">Stall</th>
                    <th
                        v-for="date in dates"
                        :key="date"
                        :class="[
                            'text-center px-1 py-2 font-medium text-muted-foreground min-w-[44px]',
                            date === today ? 'bg-primary/10 text-primary' : '',
                        ]"
                    >
                        {{ fmtDate(date) }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="stall in stalls" :key="stall.id" class="border-b last:border-0">
                    <td class="px-3 py-1.5 font-medium text-foreground sticky left-0 bg-card z-10 whitespace-nowrap">
                        {{ stall.label }}
                    </td>
                    <td
                        v-for="date in dates"
                        :key="date"
                        :class="['text-center p-0.5 align-middle', date === today ? 'bg-primary/5' : '']"
                    >
                        <template v-for="s in schedulesOnDate(stall, date)" :key="s.id">
                            <button
                                @click="emit('schedule-click', s)"
                                :class="[
                                    'w-full rounded px-1 py-0.5 text-[10px] font-medium truncate transition-opacity hover:opacity-80',
                                    scheduleColor(s.status),
                                    isStart(s, date) ? 'rounded-l-full' : 'rounded-l-none',
                                    isEnd(s, date)   ? 'rounded-r-full' : 'rounded-r-none',
                                ]"
                                :title="s.tenant?.name ?? s.tenant?.business_name ?? ''"
                            >
                                <span v-if="isStart(s, date)">
                                    {{ s.tenant?.business_name || s.tenant?.name || '—' }}
                                </span>
                                <span v-else>&nbsp;</span>
                            </button>
                        </template>
                        <span v-if="!schedulesOnDate(stall, date).length" class="block w-full h-5"></span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
</template>
