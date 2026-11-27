<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import api from '@/utils/api';

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Production forecast', href: '/production-forecast' }] } });

type Day = {
    date: string; payday: boolean; rain_chance: number | null; sample_days: number;
    basis: 'weekday' | 'weekday_payday' | 'weekday_payday_weather' | 'weekday_payday_adjusted';
    units: number | null; weekday_units: number | null; net_sales: number | null;
    products: { id: number; name: string; suggested: number; busy: number }[];
};

const days = ref<Day[]>([]);
const selected = ref(0);
const day = computed(() => days.value[selected.value]);
const state = ref<'loading' | 'error' | 'ready'>('loading');
const scope = new AbortController();
const pesos = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
const dateLabel = (date: string) => new Date(`${date}T00:00:00Z`).toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' });
const basis = {
    weekday: 'Same weekday. Weather and payday samples were too limited for a closer match.',
    weekday_payday: 'Same weekday and payday status. Weather samples were too limited for a closer match.',
    weekday_payday_adjusted: 'Typical sales for this weekday, adjusted using past paydays across Friday–Sunday. Weather was not used in this estimate.',
    weekday_payday_weather: 'Same weekday, payday status and comparable trading-hour weather.',
};

function plan(day: Day) {
    if (day.units === null || !day.weekday_units) return 'Collect more sales history';
    if (day.units >= day.weekday_units * 1.15) return 'Prep above typical';
    if (day.units <= day.weekday_units * 0.85) return 'Prep below typical';
    return 'Prep near typical';
}

async function load() {
    state.value = 'loading';
    try {
        const { data } = await api.get<{ days: Day[] }>('/api/v1/reports/production-forecast', { signal: scope.signal, timeout: 26000 });
        days.value = data.days;
        state.value = 'ready';
    } catch {
        if (!scope.signal.aborted) state.value = 'error';
    }
}

onMounted(load);
onUnmounted(() => scope.abort());
</script>

<template>
    <Head title="Production forecast" />
    <main class="grid min-h-full min-w-0 content-start gap-4 bg-[#f6f2e9] p-4 text-[#24231e] sm:p-8">
        <header>
            <Link href="/dashboard" class="text-sm font-semibold text-[#ad3b19]">← Dashboard</Link>
            <h1 class="mt-2 text-3xl font-extrabold">Production forecast</h1>
            <p class="mt-1 text-sm text-[#68665f]">Plan stock for your next three Friday–Sunday service dates.</p>
        </header>

        <p v-if="state === 'loading'" role="status" class="rounded-lg border border-[#ded7cb] bg-[#fffcf6] p-4">Checking sales history and weather…</p>
        <div v-else-if="state === 'error'" role="status" class="rounded-lg border border-[#ded7cb] bg-[#fffcf6] p-4">
            Forecast unavailable. <button type="button" class="min-h-11 px-2 text-[#ad3b19] underline" @click="load">Try again</button>
        </div>
        <template v-else-if="day">
            <nav aria-label="Service dates" class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <button v-for="(entry, i) in days" :key="entry.date" type="button" :aria-pressed="selected === i"
                    class="rounded-lg border border-[#ded7cb] bg-[#fffcf6] p-3 text-left aria-pressed:border-[#ad3b19] aria-pressed:ring-1 aria-pressed:ring-[#ad3b19]"
                    @click="selected = i">
                    <span class="block font-bold">{{ dateLabel(entry.date) }}</span>
                    <span class="block text-sm text-[#68665f]">{{ plan(entry) }}<span v-if="entry.payday"> · Payday</span></span>
                </button>
            </nav>

            <section :key="day.date" class="grid gap-3 rounded-lg border border-[#ded7cb] bg-[#fffcf6] p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-lg font-bold">{{ dateLabel(day.date) }} · {{ plan(day) }}</h2>
                    <strong class="text-sm text-[#ad3b19]">{{ day.payday ? 'Scheduled payday' : 'Regular date' }}</strong>
                </div>
                <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-md bg-[#f6f2e9] p-3"><dt class="text-sm">Estimated units</dt><dd class="text-lg font-bold">{{ day.units === null ? '—' : Math.ceil(day.units) }}</dd></div>
                    <div class="rounded-md bg-[#f6f2e9] p-3"><dt class="text-sm">Typical weekday</dt><dd class="text-lg font-bold">{{ day.weekday_units === null ? '—' : Math.ceil(day.weekday_units) }}</dd></div>
                    <div class="rounded-md bg-[#f6f2e9] p-3"><dt class="text-sm">Estimated net sales</dt><dd class="text-lg font-bold">{{ day.net_sales === null ? '—' : pesos.format(day.net_sales) }}</dd></div>
                    <div class="rounded-md bg-[#f6f2e9] p-3"><dt class="text-sm">Peak hourly rain chance</dt><dd class="text-lg font-bold">{{ day.rain_chance === null ? 'Unavailable' : `${Math.round(day.rain_chance)}%` }}</dd></div>
                </dl>
                <p class="text-sm text-[#68665f]">{{ day.sample_days }} comparable dates. {{ basis[day.basis] }}</p>
                <a v-if="day.rain_chance !== null" href="https://www.visualcrossing.com/" target="_blank" rel="noopener noreferrer" class="w-fit text-xs text-[#ad3b19] underline">Weather Data Provided by Visual Crossing</a>
            </section>

            <section class="rounded-lg border border-[#ded7cb] bg-[#fffcf6] p-4">
                <h2 class="text-lg font-bold">Suggested stock by product</h2>
                <p class="mt-1 text-sm text-[#68665f]">Compare these demand targets with ready-to-sell stock before producing. Busy-day units use the 75th percentile of comparable sales.</p>
                <table v-if="day.products.length" class="mt-3 w-full text-left text-sm">
                    <thead class="text-[#68665f]"><tr class="border-b border-[#ded7cb]"><th class="py-2">Product</th><th class="px-2 text-right">Target</th><th class="text-right">Busy day</th></tr></thead>
                    <tbody>
                        <tr v-for="product in day.products" :key="product.id" class="border-b border-[#ded7cb] last:border-0">
                            <th scope="row" class="py-2 text-left font-medium">{{ product.name }}</th><td class="px-2 text-right font-bold tabular-nums">{{ product.suggested }}</td><td class="text-right tabular-nums">{{ product.busy }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="mt-3 text-sm text-[#68665f]">There isn’t enough paid order history to estimate product quantities for this day.</p>
            </section>
        </template>
    </main>
</template>
