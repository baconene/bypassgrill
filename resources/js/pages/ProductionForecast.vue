<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import api from '@/utils/api';
import ForecastAccuracy from '@/pages/reports/ForecastAccuracy.vue';
import MonthlyForecastChart from '@/pages/reports/MonthlyForecastChart.vue';
import ForecastHistoryChart from '@/pages/reports/ForecastHistoryChart.vue';

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Production forecast', href: '/production-forecast' }] } });

type Day = {
    date: string; payday: boolean; rain_chance: number | null; sample_days: number;
    near_payday: boolean; payday_distance: number;
    special_day: SpecialDay | null; holiday: string | null; uplift: number | null;
    orders: number | null; typical_orders: number | null;
    weekday_rank: { days: number; lowest: number | null; typical_low: number | null; typical_high: number | null };
    units: number | null; weekday_units: number | null; net_sales: number | null;
    net_sales_samples: { date: string; net_sales: number; condition: 'clear' | 'cloud' | 'rain' | null; rain_mm: number | null; rainy_hours: number | null; near_payday: boolean }[];
    products: { id: number; name: string; suggested: number; busy: number }[];
};

type SpecialDay = 'payday_long_weekend' | 'long_weekend' | 'holiday' | 'near_payday';
type Model = {
    training_days: number; weather_days: number; test_days: number; weekday_mae: number | null; context_mae: number | null;
    factor_mae: number | null; use_context: boolean; mode: 'baseline' | 'context' | 'factors'; bias: number;
};
const specialLabel: Record<SpecialDay, string> = {
    payday_long_weekend: 'Payday + long weekend', long_weekend: 'Long weekend', holiday: 'Holiday', near_payday: 'Near payday',
};
const pct = (uplift: number) => `${uplift >= 1 ? '+' : '−'}${Math.round(Math.abs(uplift - 1) * 100)}%`;
const modelLabel = { baseline: 'Using the weekday baseline.', context: 'Weather and payday matching are active.',
    factors: 'Special-day factors are active: holidays, long weekends and paydays, with recent weeks counting more.' };
type Month = InstanceType<typeof MonthlyForecastChart>['$props']['days'];
type Accuracy = InstanceType<typeof ForecastAccuracy>['$props']['accuracy'];
const days = ref<Day[]>([]);
const month = ref<Month>([]);
const accuracy = ref<Accuracy>();
const model = ref<Model>();
const selected = ref(0);
const day = computed(() => days.value[selected.value]);
const state = ref<'loading' | 'error' | 'ready'>('loading');
const scope = new AbortController();
const pesos = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
const dateLabel = (date: string) => new Date(`${date}T00:00:00Z`).toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' });
function plan(day: Day) {
    if (day.units === null || !day.weekday_units) return 'Collect more sales history';
    if (day.units >= day.weekday_units * 1.15) return 'Prep above typical';
    if (day.units <= day.weekday_units * 0.85) return 'Prep below typical';
    return 'Prep near typical';
}

async function load() {
    state.value = 'loading';
    try {
        const { data } = await api.get<{ days: Day[]; model: Model; month: Month; accuracy: Accuracy }>('/api/v1/reports/production-forecast', { signal: scope.signal, timeout: 26000 });
        days.value = data.days;
        model.value = data.model;
        month.value = data.month ?? [];
        accuracy.value = data.accuracy;
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
            <MonthlyForecastChart v-if="month.length" :days="month" />

            <nav aria-label="Service dates" class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <button v-for="(entry, i) in days" :key="entry.date" type="button" :aria-pressed="selected === i"
                    class="rounded-lg border border-[#ded7cb] bg-[#fffcf6] p-3 text-left aria-pressed:border-[#ad3b19] aria-pressed:ring-1 aria-pressed:ring-[#ad3b19]"
                    @click="selected = i">
                    <span class="block font-bold">{{ dateLabel(entry.date) }}</span>
                    <span class="block text-sm text-[#68665f]">{{ plan(entry) }}<span v-if="entry.payday"> · Payday</span></span>
                    <span v-if="entry.special_day && entry.special_day !== 'near_payday'" class="mt-1 inline-block rounded bg-[#f2e5d8] px-1.5 py-0.5 text-xs font-bold text-[#ad3b19]">
                        {{ specialLabel[entry.special_day] }}<template v-if="entry.uplift !== null">{{ ' ' + pct(entry.uplift) }}</template></span>
                </button>
            </nav>

            <section :key="day.date" class="grid gap-3 rounded-lg border border-[#ded7cb] bg-[#fffcf6] p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-lg font-bold">{{ dateLabel(day.date) }} · {{ plan(day) }}</h2>
                    <strong class="text-sm text-[#ad3b19]">{{ day.payday ? 'Scheduled payday' : day.near_payday ? 'Near payday' : `${day.payday_distance} days from payday` }}</strong>
                </div>
                <p v-if="day.special_day && day.special_day !== 'near_payday'" class="rounded-md border border-[#e4c4ab] bg-[#f2e5d8] p-3 text-sm">
                    <strong>{{ specialLabel[day.special_day] }}<template v-if="day.holiday"> · {{ day.holiday }}</template>.</strong>
                    <template v-if="day.uplift !== null && day.uplift > 1"> Past days like this sold about {{ pct(day.uplift) }} more, so sales and prep below are raised to match.</template>
                    <template v-else-if="day.uplift !== null && day.uplift < 1"> Past days like this sold about {{ pct(day.uplift) }} less, so sales and prep below are lowered to match.</template>
                    <template v-else> Not enough past days like this yet to adjust the estimate. Watch for a busier shift.</template>
                </p>
                <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-md bg-[#f6f2e9] p-3"><dt class="text-sm">Estimated units</dt><dd class="text-lg font-bold">{{ day.units === null ? '—' : Math.ceil(day.units) }}</dd></div>
                    <div class="rounded-md bg-[#f6f2e9] p-3"><dt class="text-sm">Estimated paid orders</dt><dd class="text-lg font-bold">{{ day.orders === null ? '—' : Math.round(day.orders) }} <span v-if="day.typical_orders !== null" class="block text-xs font-normal">Typical: {{ Math.round(day.typical_orders) }}</span></dd></div>
                    <div class="rounded-md bg-[#f6f2e9] p-3"><dt class="text-sm">Estimated net sales</dt><dd class="text-lg font-bold">{{ day.net_sales === null ? '—' : pesos.format(day.net_sales) }}</dd></div>
                    <div class="rounded-md bg-[#f6f2e9] p-3"><dt class="text-sm">Peak hourly rain chance</dt><dd class="text-lg font-bold">{{ day.rain_chance === null ? 'Unavailable' : `${Math.round(day.rain_chance)}%` }}</dd></div>
                </dl>
                <p v-if="day.weekday_rank.days" class="text-sm text-[#68665f]">
                    Typical net sales (80th–100th percentile): {{ pesos.format(day.weekday_rank.typical_low!) }} – {{ pesos.format(day.weekday_rank.typical_high!) }}.
                    Recorded low: {{ pesos.format(day.weekday_rank.lowest!) }} across {{ day.weekday_rank.days }} same-weekday dates.
                </p>
                <div v-if="model" class="text-sm text-[#68665f]">
                    <p>{{ model.training_days }} service dates in training · {{ model.weather_days }} with observed weather · {{ day.sample_days }} matching this weekday.</p>
                    <p>{{ modelLabel[model.mode ?? (model.use_context ? 'context' : 'baseline')] }}</p>
                    <p v-if="model.test_days && model.weekday_mae !== null && model.context_mae !== null">
                        Average backtest error over {{ model.test_days }} dates · Weekday {{ pesos.format(model.weekday_mae) }} · Weather + payday {{ pesos.format(model.context_mae) }}<template v-if="model.factor_mae !== null"> · Special-day factors {{ pesos.format(model.factor_mae) }}</template>
                    </p>
                    <p v-if="model.weather_days < model.training_days">Historical weather is loaded in batches. Dates with missing weather still count toward the sales forecast.</p>
                </div>
                <a v-if="day.rain_chance !== null" href="https://www.visualcrossing.com/" target="_blank" rel="noopener noreferrer" class="w-fit text-xs text-[#ad3b19] underline">Weather Data Provided by Visual Crossing</a>
            </section>

            <ForecastHistoryChart :key="day.date" :samples="day.net_sales_samples" />

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
            <ForecastAccuracy v-if="accuracy" :accuracy="accuracy" />
        </template>
    </main>
</template>
