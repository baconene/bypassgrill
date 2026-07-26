<script setup lang="ts">
import { computed } from 'vue'
import { ChevronLeft, ChevronRight, Loader2 } from 'lucide-vue-next'
import type { CalendarDay } from './types'

const props = defineProps<{
    year: number
    month: number
    days: Record<string, CalendarDay>
    loading: boolean
    selectedDate?: string
}>()

const emit = defineEmits<{
    'update:year': [v: number]
    'update:month': [v: number]
    'date-click': [date: string]
}>()

const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December']
const DOW = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']

const calendarCells = computed(() => {
    const first = new Date(props.year, props.month - 1, 1)
    const last  = new Date(props.year, props.month, 0)
    const cells: Array<{ date: string | null; day: CalendarDay | null }> = []

    for (let i = 0; i < first.getDay(); i++) cells.push({ date: null, day: null })

    for (let d = 1; d <= last.getDate(); d++) {
        const date = `${props.year}-${String(props.month).padStart(2, '0')}-${String(d).padStart(2, '0')}`
        cells.push({ date, day: props.days[date] ?? null })
    }
    return cells
})

const prevMonth = () => {
    if (props.month === 1) { emit('update:year', props.year - 1); emit('update:month', 12) }
    else emit('update:month', props.month - 1)
}
const nextMonth = () => {
    if (props.month === 12) { emit('update:year', props.year + 1); emit('update:month', 1) }
    else emit('update:month', props.month + 1)
}

const today = new Date().toISOString().slice(0, 10)

const cellBg = (cell: { date: string | null; day: CalendarDay | null }) => {
    if (!cell.date) return ''
    if (cell.date === props.selectedDate) return 'bg-primary text-primary-foreground'
    if (!cell.day) return 'hover:bg-accent'
    if (cell.day.occupied > 0)    return 'bg-blue-100 dark:bg-blue-900/30 hover:bg-blue-200 dark:hover:bg-blue-900/50'
    if (cell.day.reserved > 0)    return 'bg-amber-100 dark:bg-amber-900/30 hover:bg-amber-200 dark:hover:bg-amber-900/50'
    if (cell.day.maintenance > 0) return 'bg-red-100 dark:bg-red-900/30 hover:bg-red-200 dark:hover:bg-red-900/50'
    return 'bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/40'
}
</script>

<template>
<div class="rounded-xl border bg-card overflow-hidden">
    <!-- Header -->
    <div class="flex items-center justify-between px-4 py-3 border-b">
        <button @click="prevMonth" class="p-1.5 rounded hover:bg-accent transition-colors">
            <ChevronLeft class="w-4 h-4" />
        </button>
        <span class="font-semibold text-sm">{{ MONTHS[month - 1] }} {{ year }}</span>
        <button @click="nextMonth" class="p-1.5 rounded hover:bg-accent transition-colors">
            <ChevronRight class="w-4 h-4" />
        </button>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
        <Loader2 class="w-5 h-5 animate-spin text-muted-foreground" />
    </div>

    <div v-else class="p-3">
        <!-- Day of week headers -->
        <div class="grid grid-cols-7 mb-1">
            <div v-for="d in DOW" :key="d" class="text-center text-xs font-medium text-muted-foreground py-1">{{ d }}</div>
        </div>

        <!-- Calendar cells -->
        <div class="grid grid-cols-7 gap-1">
            <div
                v-for="(cell, i) in calendarCells"
                :key="i"
                :class="[
                    'aspect-square rounded-lg flex flex-col items-center justify-center text-xs transition-colors',
                    cell.date ? ['cursor-pointer select-none', cellBg(cell)] : 'pointer-events-none opacity-0',
                    cell.date && cell.date !== selectedDate && cell.date === today ? 'ring-2 ring-primary/60' : '',
                ]"
                @click="cell.date && emit('date-click', cell.date)"
            >
                <template v-if="cell.date">
                    <span :class="['font-medium leading-none', cell.date === selectedDate ? 'text-primary-foreground' : cell.date === today ? 'text-primary' : 'text-foreground']">
                        {{ parseInt(cell.date.slice(8)) }}
                    </span>
                    <div v-if="cell.day && cell.date !== selectedDate" class="flex gap-0.5 mt-0.5">
                        <span v-if="cell.day.occupied > 0"    class="w-1 h-1 rounded-full bg-blue-500"></span>
                        <span v-if="cell.day.reserved > 0"    class="w-1 h-1 rounded-full bg-amber-500"></span>
                        <span v-if="cell.day.maintenance > 0" class="w-1 h-1 rounded-full bg-red-500"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
</template>
