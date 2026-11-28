<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight, CalendarRange } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import api from '@/utils/api';

type SpecialDay = 'payday_long_weekend' | 'long_weekend' | 'holiday' | 'near_payday';
type Day = {
    date: string;
    net_sales: number | null;
    range_low: number | null;
    range_high: number | null;
    orders: number | null;
    rain_chance: number | null;
    special_day: SpecialDay | null;
    holiday: string | null;
    products: { id: number; name: string; suggested: number }[];
};

const day = ref<Day | null>(null);
const state = ref<'loading' | 'ready' | 'empty' | 'error'>('loading');
const scope = new AbortController();
const special: Record<SpecialDay, string> = {
    payday_long_weekend: 'Payday + long weekend',
    long_weekend: 'Long weekend',
    holiday: 'Holiday',
    near_payday: 'Near payday',
};
const todayKey = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date());
const isToday = computed(() => day.value?.date === todayKey);
const when = computed(() =>
    day.value
        ? new Date(day.value.date + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' })
        : '',
);
const peso = (n: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(n);
const prep = computed(() =>
    (day.value?.products ?? [])
        .filter((p) => p.suggested > 0)
        .sort((a, b) => b.suggested - a.suggested)
        .slice(0, 3),
);

onMounted(async () => {
    try {
        const { data } = await api.get<{ days: Day[] }>('/api/v1/reports/production-forecast', { signal: scope.signal, timeout: 26000 });
        day.value = data.days?.[0] ?? null;
        state.value = day.value ? 'ready' : 'empty';
    } catch {
        if (!scope.signal.aborted) {
state.value = 'error';
}
    }
});
onUnmounted(() => scope.abort());
</script>

<template>
    <Link href="/production-forecast" class="block min-w-0 rounded-lg border border-[#ded7cb] bg-[#fffcf6] p-4 hover:border-[#ad3b19]" aria-labelledby="forecast-card-title">
        <div class="flex items-center justify-between gap-2">
            <h2 id="forecast-card-title" class="flex items-center gap-2 text-sm font-bold">
                <CalendarRange :size="17" class="text-[#ad3b19]" aria-hidden="true" />
                {{ state === 'ready' ? (isToday ? 'Today’s forecast' : `Next service · ${when}`) : 'Sales forecast' }}
            </h2>
            <span class="flex items-center gap-0.5 text-xs font-semibold text-[#ad3b19]">Details <ArrowUpRight :size="13" aria-hidden="true" /></span>
        </div>

        <p v-if="state === 'loading'" role="status" class="mt-2 text-sm text-[#68665f]">Working out the forecast…</p>
        <p v-else-if="state === 'error'" class="mt-2 text-sm text-[#68665f]">Forecast unavailable right now. Open it for details.</p>
        <p v-else-if="state === 'empty' || day?.net_sales == null" class="mt-2 text-sm text-[#68665f]">Not enough sales history for a forecast yet.</p>
        <template v-else-if="day">
            <div class="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <strong class="text-2xl tabular-nums tracking-tight">{{ peso(day.net_sales) }}</strong>
                <span v-if="day.range_low !== null && day.range_high !== null" class="text-xs text-[#68665f]">likely {{ peso(day.range_low) }}–{{ peso(day.range_high) }}</span>
            </div>
            <p class="mt-1 flex flex-wrap gap-x-2 text-xs text-[#68665f]">
                <span v-if="day.orders !== null">~{{ Math.round(day.orders) }} orders</span>
                <span v-if="day.rain_chance !== null">· Rain {{ Math.round(day.rain_chance) }}%</span>
                <span v-if="day.special_day && day.special_day !== 'near_payday'" class="font-bold text-[#ad3b19]">· {{ special[day.special_day] }}<template v-if="day.holiday"> ({{ day.holiday }})</template></span>
            </p>
            <p v-if="prep.length" class="mt-2 truncate text-sm">
                <span class="font-semibold">Prep:</span> {{ prep.map((p) => `${Math.ceil(p.suggested)} ${p.name}`).join(' · ') }}
            </p>
        </template>
    </Link>
</template>
