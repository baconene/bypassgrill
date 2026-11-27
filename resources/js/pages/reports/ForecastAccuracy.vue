<script setup lang="ts">
type Accuracy = {
    last_captured_at: string | null;
    tolerance_pct: number;
    hits: number;
    scored: number;
    days: {
        date: string;
        as_of: string;
        estimate: number | null;
        actual: number | null;
        error: number | null;
        baseline_error: number | null;
        result: 'hit' | 'miss' | 'no_sales_record' | 'no_estimate';
        payday_distance: number;
        weather_source: 'forecast' | 'history' | 'unavailable';
        rain_chance: number | null;
        observed_rain_mm: number | null;
    }[];
};

defineProps<{ accuracy: Accuracy }>();

const pesos = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
const money = (value: number | null) => value === null ? '—' : pesos.format(value);
const labels = { hit: 'Hit', miss: 'Miss', no_sales_record: 'No sales record', no_estimate: 'No estimate' };
const sources = { forecast: 'Live peak rain chance', history: 'Historical rain frequency', unavailable: 'Rain outlook' };
</script>

<template>
    <section aria-labelledby="forecast-accuracy-heading" class="min-w-0 rounded-lg border border-[#ded7cb] bg-[#fffcf6] p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="forecast-accuracy-heading" class="text-lg font-bold">Saved forecast accuracy</h2>
            <strong v-if="accuracy.scored" class="rounded bg-[#f0e9dc] px-2 py-1 text-sm">{{ accuracy.hits }} hits / {{ accuracy.scored }} scored</strong>
        </div>
        <p class="mt-1 text-sm text-[#68665f]">A hit means actual net sales were within {{ accuracy.tolerance_pct }}% of the forecast. The score uses the last forecast saved before each shift across the latest 30 service dates.</p>
        <p class="mt-1 text-xs text-[#68665f]">{{ accuracy.last_captured_at ? 'Last capture: ' + accuracy.last_captured_at.slice(0,16) + ' Manila time' : 'Waiting for the first daily capture.' }}</p>
        <div v-if="accuracy.days.length" class="mt-3 grid min-w-0 gap-2 sm:grid-cols-2">
            <article v-for="day in accuracy.days" :key="day.date" class="rounded border border-[#ded7cb] p-3 text-sm">
                <div class="flex flex-wrap items-baseline justify-between gap-1">
                    <h3 class="font-bold">{{ day.date }}</h3><span class="font-semibold" :class="day.result === 'hit' ? 'text-emerald-800' : day.result === 'miss' ? 'text-[#ad3b19]' : 'text-[#68665f]'">{{ labels[day.result] }}</span>
                </div>
                <p class="text-xs text-[#68665f]">Forecast saved {{ day.as_of }}</p>
                <dl class="mt-2 grid grid-cols-2 gap-2">
                    <div><dt>Estimate</dt><dd class="font-bold">{{ money(day.estimate) }}</dd></div>
                    <div><dt>Actual</dt><dd class="font-bold">{{ money(day.actual) }}</dd></div>
                    <div><dt>Forecast error</dt><dd>{{ money(day.error === null ? null : Math.abs(day.error)) }}</dd></div>
                    <div><dt>Weekday baseline error</dt><dd>{{ money(day.baseline_error === null ? null : Math.abs(day.baseline_error)) }}</dd></div>
                </dl>
                <p class="mt-2 text-xs text-[#68665f]">
                    {{ sources[day.weather_source] }} {{ day.rain_chance === null ? '—' : Math.round(day.rain_chance) + '%' }} ·
                    Observed {{ day.observed_rain_mm === null ? '—' : day.observed_rain_mm + ' mm' }} ·
                    {{ day.payday_distance }} days from payday
                </p>
            </article>
        </div>
        <p v-else class="mt-2 text-sm text-[#68665f]">Results appear after saved forecasts reach their service dates and the daily batch checks actual sales.</p>
        <a v-if="accuracy.days.some(day => day.rain_chance !== null || day.observed_rain_mm !== null)" href="https://www.visualcrossing.com/" target="_blank" rel="noopener noreferrer" class="mt-2 inline-block text-xs text-[#ad3b19] underline">Weather Data Provided by Visual Crossing</a>
    </section>
</template>
