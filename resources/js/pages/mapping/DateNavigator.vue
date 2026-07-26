<script setup lang="ts">
import { ChevronLeft, ChevronRight, Calendar } from 'lucide-vue-next'

const props = defineProps<{ date: string }>()
const emit = defineEmits<{ 'update:date': [v: string] }>()

const addDays = (d: string, n: number) => {
    const dt = new Date(d + 'T00:00:00')
    dt.setDate(dt.getDate() + n)
    return dt.toISOString().slice(0, 10)
}

const fmt = (d: string) => {
    const dt = new Date(d + 'T00:00:00')
    const today = new Date(); today.setHours(0, 0, 0, 0)
    if (dt.getTime() === today.getTime()) return 'Today'
    return dt.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
}

const todayStr = () => new Date().toISOString().slice(0, 10)
</script>

<template>
<div class="flex items-center gap-1.5">
    <button
        type="button"
        @click="emit('update:date', addDays(date, -1))"
        class="rounded-xl border p-2.5 hover:bg-accent active:scale-95 transition-all touch-manipulation"
        aria-label="Previous day"
    >
        <ChevronLeft class="w-4 h-4" />
    </button>
    <div class="flex items-center gap-1.5 rounded-xl border px-3 py-2 text-sm font-medium justify-center min-w-0">
        <Calendar class="w-3.5 h-3.5 text-muted-foreground flex-shrink-0" />
        <span class="truncate">{{ fmt(date) }}</span>
    </div>
    <button
        type="button"
        @click="emit('update:date', addDays(date, 1))"
        class="rounded-xl border p-2.5 hover:bg-accent active:scale-95 transition-all touch-manipulation"
        aria-label="Next day"
    >
        <ChevronRight class="w-4 h-4" />
    </button>
    <button
        v-if="date !== todayStr()"
        type="button"
        @click="emit('update:date', todayStr())"
        class="rounded-xl border px-3 py-2 text-sm hover:bg-accent transition-colors touch-manipulation whitespace-nowrap"
    >
        Today
    </button>
</div>
</template>
