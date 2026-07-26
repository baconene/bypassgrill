<script setup lang="ts">
import { computed } from 'vue'
import { User, Calendar, Wrench, Ban } from 'lucide-vue-next'
import type { StallDay } from './types'

const props = defineProps<{ stall: StallDay }>()
const emit = defineEmits<{ click: [stall: StallDay] }>()

const statusConfig = computed(() => {
    switch (props.stall.display_status) {
        case 'occupied':     return { ring: 'ring-blue-400',    bg: 'bg-blue-50 dark:bg-blue-950/40',    dot: 'bg-blue-500',    label: 'Occupied',     icon: User }
        case 'reserved':     return { ring: 'ring-amber-400',   bg: 'bg-amber-50 dark:bg-amber-950/40',  dot: 'bg-amber-500',   label: 'Reserved',     icon: Calendar }
        case 'maintenance':  return { ring: 'ring-red-400',     bg: 'bg-red-50 dark:bg-red-950/40',     dot: 'bg-red-500',     label: 'Maintenance',  icon: Wrench }
        case 'inactive':     return { ring: 'ring-border',      bg: 'bg-muted/30',                      dot: 'bg-muted-foreground/30', label: 'Inactive', icon: Ban }
        default:             return { ring: 'ring-emerald-400', bg: 'bg-emerald-50 dark:bg-emerald-950/40', dot: 'bg-emerald-500', label: 'Available', icon: null }
    }
})
</script>

<template>
<div
    role="button"
    @click="emit('click', stall)"
    :class="[
        'relative rounded-2xl border ring-2 p-4 cursor-pointer select-none transition-all duration-150',
        'hover:shadow-md hover:scale-[1.02] active:scale-[0.99]',
        statusConfig.ring, statusConfig.bg,
    ]"
>
    <div class="flex items-start justify-between mb-3">
        <div>
            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Stall {{ stall.number }}</p>
            <p class="font-bold text-foreground text-base leading-tight">{{ stall.label }}</p>
        </div>
        <span :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', statusConfig.bg, 'border']">
            <span :class="['w-1.5 h-1.5 rounded-full', statusConfig.dot]"></span>
            {{ statusConfig.label }}
        </span>
    </div>

    <template v-if="stall.schedule">
        <div class="space-y-1 text-sm">
            <p class="font-semibold text-foreground truncate">{{ stall.schedule.tenant?.business_name || stall.schedule.tenant?.name }}</p>
            <p v-if="stall.schedule.tenant?.business_name" class="text-xs text-muted-foreground truncate">{{ stall.schedule.tenant?.name }}</p>
            <p class="text-xs text-muted-foreground">
                {{ stall.schedule.start_date }} – {{ stall.schedule.end_date }}
            </p>
            <p v-if="stall.schedule.price > 0" class="text-xs font-medium text-foreground">
                ₱{{ Number(stall.schedule.price).toLocaleString() }}
            </p>
        </div>
    </template>
    <template v-else>
        <p class="text-sm text-muted-foreground">No booking</p>
    </template>
</div>
</template>
