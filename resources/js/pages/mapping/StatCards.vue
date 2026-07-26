<script setup lang="ts">
import type { Stats } from './types'

defineProps<{ stats: Stats | null }>()

const fmt = (n: number) =>
    new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(n)
</script>

<template>
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
    <div class="rounded-xl border bg-card p-4">
        <p class="text-xs text-muted-foreground">Available Today</p>
        <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">
            {{ stats?.today.available ?? '—' }}
        </p>
    </div>
    <div class="rounded-xl border bg-card p-4">
        <p class="text-xs text-muted-foreground">Occupied</p>
        <p class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-1">
            {{ stats?.today.occupied ?? '—' }}
        </p>
    </div>
    <div class="rounded-xl border bg-card p-4">
        <p class="text-xs text-muted-foreground">Reserved</p>
        <p class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-1">
            {{ stats?.today.reserved ?? '—' }}
        </p>
    </div>
    <div class="rounded-xl border bg-card p-4">
        <p class="text-xs text-muted-foreground">Month Revenue</p>
        <p class="text-xl font-bold text-foreground mt-1 truncate">
            {{ stats ? fmt(stats.month_revenue) : '—' }}
        </p>
    </div>
</div>
</template>
