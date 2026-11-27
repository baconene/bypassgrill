<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import api from '@/utils/api';

type Hour = { hour: number; sales: number; rain_mm: number | null; temp_c: number | null };
type Insights = {
    paid_sales: number;
    payments: number;
    through: string | null;
    weekday_rank: { days: number; rank: number | null; lowest: number | null; is_lowest: boolean; typical_low: number | null; typical_high: number | null; change_pct: number | null };
    near_payday: boolean; payday_distance: number; lower_demand_signal: boolean;
    payday: { is_payday: boolean; weekday: string; baseline_days: number; baseline_sales: number | null; change_pct: number | null };
    weather: { hours: Hour[]; wet_hours: number; dry_hours: number; wet_avg: number | null; dry_avg: number | null };
};

const props = defineProps<{ initialDate: string }>();
const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date());
const date = ref(props.initialDate || today);
const report = ref<Insights>();
const error = ref(false);
const attempt = ref(0);
const selection = ref<number>();
const selected = computed(() => report.value?.weather.hours.find(hour => hour.hour === selection.value));
const scale = computed(() => Math.max(1, ...(report.value?.weather.hours.map(hour => Math.abs(hour.sales)) ?? [])));
const pesos = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
const money = (value: number | null) => value === null ? '—' : pesos.format(value);
const label = (hour: number) => `${String(hour).padStart(2, '0')}:00`;
const rain = (hour: Hour) => hour.rain_mm === null ? 'unknown' : hour.rain_mm >= 0.1 ? 'rainy' : 'dry';
const rainColor = { unknown: '#777268', rainy: '#285c81', dry: '#ad3b19' };
const rainDifference = computed(() => {
    const weather = report.value?.weather;
    return weather && weather.wet_hours >= 2 && weather.dry_hours >= 2 && weather.wet_avg != null &&
        weather.dry_avg != null && weather.dry_avg > 0 ? (weather.wet_avg / weather.dry_avg - 1) * 100 : null;
});
const percentage = (value: number) => `${value > 0 ? '+' : ''}${value.toFixed(1)}%`;

watch([date, attempt], async ([day], _last, onCleanup) => {
    report.value = undefined;
    selection.value = undefined;
    error.value = false;
    if (!day || day > today) return;
    const request = new AbortController();
    onCleanup(() => request.abort());
    try {
        const { data } = await api.get<Insights>('/api/v1/reports/sales-insights', {
            params: { date: day }, signal: request.signal, timeout: 12000,
        });
        if (!request.signal.aborted) report.value = data;
    } catch {
        if (!request.signal.aborted) error.value = true;
    }
}, { immediate: true });
</script>

<template>
    <section aria-label="Daily sales insights" class="grid min-w-0 gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-card p-4">
            <div>
                <h2 class="font-bold">Daily sales insights</h2>
                <p class="text-sm text-muted-foreground">Weather and scheduled payday comparisons</p>
            </div>
            <label class="grid gap-1 text-xs font-semibold">Report date
                <input v-model="date" :max="today" type="date" class="min-h-11 rounded-md border bg-background px-3 text-sm" />
            </label>
        </div>

        <div v-if="report" class="grid min-w-0 gap-4 lg:grid-cols-2">
            <article class="rounded-lg border bg-card p-4">
                <h3 class="font-bold">Paid sales</h3>
                <p class="mt-1 text-sm text-muted-foreground">{{ report.through ? `Completed hours through ${report.through} Manila time.` : 'Payments recorded on the selected date.' }}</p>
                <p class="mt-3 text-2xl font-bold tabular-nums">{{ money(report.paid_sales) }}</p>
                <p class="text-sm text-muted-foreground">{{ report.payments }} payments</p>
            </article>

            <article class="rounded-lg border bg-card p-4">
                <h3 class="font-bold">{{ report.weekday_rank.is_lowest ? `Lowest recorded ${report.payday.weekday}` : report.payday.is_payday ? 'Scheduled payday' : report.near_payday ? 'Near payday' : 'Away from payday' }}</h3>
                <p class="mt-1 text-sm text-muted-foreground">{{ report.payday_distance }} days from the nearest scheduled payday.</p>
                <dl class="mt-3 grid grid-cols-2 gap-3 border-t pt-3 text-sm">
                    <div><dt>Typical {{ report.payday.weekday }}</dt><dd class="font-bold tabular-nums">{{ money(report.weekday_rank.typical_low) }} – {{ money(report.weekday_rank.typical_high) }}</dd></div>
                    <div><dt>Recorded low</dt><dd class="font-bold tabular-nums">{{ money(report.weekday_rank.lowest) }}</dd></div>
                    <div v-if="report.weekday_rank.rank !== null"><dt>Rank from lowest</dt><dd class="font-bold">{{ report.weekday_rank.rank }} of {{ report.weekday_rank.days }}</dd></div>
                    <div v-if="report.weekday_rank.change_pct !== null"><dt>Vs. start of typical range</dt><dd class="font-bold">{{ percentage(report.weekday_rank.change_pct) }}</dd></div>
                </dl>
                <p class="mt-2 text-xs text-muted-foreground">{{ report.weekday_rank.days }} completed {{ report.payday.weekday }} dates with payments. Typical is the 80th–100th percentile{{ report.through ? ' through the same hour' : '' }}.</p>
                <p v-if="report.lower_demand_signal" class="mt-2 text-sm font-semibold text-[#ad3b19]">Lower-demand conditions · Trading-hour rain and more than 3 days from payday.</p>
            </article>

            <article class="min-w-0 rounded-lg border bg-card p-4 lg:col-span-2">
                <h3 class="font-bold">Sales by trading hour</h3>
                <p class="mt-1 text-sm text-muted-foreground">Select a bar for paid sales, rainfall and temperature.</p>
                <template v-if="report.weather.hours.length">
                    <div class="mt-3 flex gap-2 overflow-x-auto pb-2">
                        <button v-for="hour in report.weather.hours" :key="hour.hour" type="button"
                            :aria-pressed="hour.hour === selection" :aria-label="`${label(hour.hour)}, ${money(hour.sales)}, ${rain(hour)} weather`"
                            class="flex min-w-15 flex-1 flex-col items-center rounded-md border border-transparent p-1 aria-pressed:border-[#ad3b19] aria-pressed:bg-muted focus-visible:outline-2 focus-visible:outline-[#ad3b19]"
                            @click="selection = hour.hour">
                            <span class="flex h-24 items-end border-b px-2" aria-hidden="true"><span class="w-5 rounded-t-sm"
                                :style="{ backgroundColor: rainColor[rain(hour)], height: `${Math.max(2, Math.abs(hour.sales) / scale * 100)}%` }"></span></span>
                            <span class="py-1 text-xs tabular-nums">{{ label(hour.hour) }}</span>
                        </button>
                    </div>
                    <p v-if="selected" aria-live="polite" class="mb-3 rounded-md bg-muted p-2 text-sm font-semibold">
                        {{ label(selected.hour) }} · {{ money(selected.sales) }}
                        · {{ selected.rain_mm == null ? 'Weather unavailable' : `${selected.rain_mm} mm of rain` }}
                        <span v-if="selected.temp_c != null"> · {{ Math.round(selected.temp_c) }}°C</span>
                    </p>
                    <template v-if="report.weather.wet_hours + report.weather.dry_hours">
                        <p class="text-xs text-muted-foreground">Blue = rainy · Orange = dry · Gray = unknown</p>
                        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-md bg-muted p-3"><dt>Rainy ({{ report.weather.wet_hours }} h)</dt><dd class="font-bold">{{ money(report.weather.wet_avg) }}<span v-if="report.weather.wet_avg != null">/hr</span></dd></div>
                            <div class="rounded-md bg-muted p-3"><dt>Dry ({{ report.weather.dry_hours }} h)</dt><dd class="font-bold">{{ money(report.weather.dry_avg) }}<span v-if="report.weather.dry_avg != null">/hr</span></dd></div>
                        </dl>
                        <p v-if="rainDifference != null" class="mt-3 text-sm">Hourly sales in rainy weather differed by {{ percentage(rainDifference) }} compared with dry hours on this date.</p>
                        <a class="mt-2 inline-block text-xs text-[#ad3b19] underline" href="https://www.visualcrossing.com/" target="_blank" rel="noopener noreferrer">Weather Data Provided by Visual Crossing</a>
                    </template>
                    <p v-else class="text-sm text-muted-foreground">Observed weather is unavailable for these hours.</p>
                </template>
                <p v-else class="mt-3 text-sm text-muted-foreground">No trading-hour comparison for this date yet.</p>
            </article>
        </div>
        <div v-else role="status" class="rounded-lg border bg-card p-4 text-sm">
            <template v-if="error">
                Could not load sales insights.
                <button type="button" class="min-h-11 px-3 font-semibold text-[#ad3b19] underline" @click="attempt++">Try again</button>
            </template>
            <template v-else>{{ !date || date > today ? 'Choose today or an earlier date.' : 'Loading sales insights…' }}</template>
        </div>
    </section>
</template>
