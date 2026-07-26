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
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    if (dt.getTime() === today.getTime()) return 'Today'
    return dt.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
<div class="flex items-center gap-2">
    <button
        @click="emit('update:date', addDays(date, -1))"
        class="rounded-lg border p-1.5 hover:bg-accent transition-colors"
        aria-label="Previous day"
    >
        <ChevronLeft class="w-4 h-4" />
    </button>
    <div class="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium min-w-32 justify-center">
        <Calendar class="w-3.5 h-3.5 text-muted-foreground" />
        {{ fmt(date) }}
    </div>
    <button
        @click="emit('update:date', addDays(date, 1))"
        class="rounded-lg border p-1.5 hover:bg-accent transition-colors"
        aria-label="Next day"
    >
        <ChevronRight class="w-4 h-4" />
    </button>
    <button
        v-if="date !== new Date().toISOString().slice(0, 10)"
        @click="emit('update:date', new Date().toISOString().slice(0, 10))"
        class="rounded-lg border px-2.5 py-1.5 text-xs hover:bg-accent transition-colors"
    >
        Today
    </button>
</div>
</template>
