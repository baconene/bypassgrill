<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import api from '@/utils/api';

type Prediction = {
    date: string; as_of: string; estimate: number | null; actual: number | null;
    error: number | null; baseline_error: number | null; sample_days: number;
    result: 'hit' | 'miss' | 'no_sales_record' | 'no_estimate' | 'pending';
    payday_distance: number; rain_chance: number | null;
    special_day: SpecialDay | null; uplift: number | null; model_version: string;
    range_low: number | null; range_high: number | null;
    observed_rain_mm: number | null; weather_source: 'forecast' | 'history' | 'unavailable';
};
type SpecialDay = 'payday_long_weekend' | 'long_weekend' | 'holiday' | 'near_payday';
type Report = {
    hits: number; misses: number; no_estimate: number; in_range: number; ranged: number; mean_error: number | null; mean_bias: number | null; baseline_mean_error: number | null;
    first_run: string | null; last_run: string | null; run_days: number; tolerance_pct: number; can_run_replay: boolean;
    predictions: { data: Prediction[]; current_page: number; last_page: number };
};

const type = ref<'replay' | 'live'>('replay');
const asOf = ref('');
const page = ref(1);
const refresh = ref(0);
const report = ref<Report>();
const loadError = ref(false);
const running = ref(false);
const exporting = ref(false);
const replayStatus = ref('');
const pesos = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
const money = (value: number | null) => value === null ? '—' : pesos.format(value);
const monthDay = new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', timeZone: 'UTC' });
const dayLabel = (date: string) => monthDay.format(new Date(date + 'T00:00:00Z'));
const specialLabel: Record<SpecialDay, string> = {
    payday_long_weekend: 'Payday + long weekend', long_weekend: 'Long weekend', holiday: 'Holiday', near_payday: 'Near payday',
};
const pct = (uplift: number) => `${uplift >= 1 ? '+' : '−'}${Math.round(Math.abs(uplift - 1) * 100)}%`;
const status = { hit: 'Hit', miss: 'Miss', no_sales_record: 'Closed / no sales', no_estimate: 'No estimate', pending: 'Pending' };

watch([type, asOf, page, refresh], async ([mode, date, current], _old, onCleanup) => {
    report.value = undefined;
    loadError.value = false;
    const request = new AbortController();
    onCleanup(() => request.abort());
    try {
        const { data } = await api.get<Report>('/api/v1/reports/predictions', {
            params: { type: mode, as_of: date || undefined, page: current },
            signal: request.signal, timeout: 15000,
        });
        if (!request.signal.aborted) report.value = data;
    } catch {
        if (!request.signal.aborted) loadError.value = true;
    }
}, { immediate: true });

async function exportCsv() {
    exporting.value = true;

    try {
        const { data } = await api.get<Blob>('/api/v1/reports/predictions/export', {
            params: { type: type.value, as_of: asOf.value || undefined }, responseType: 'blob', timeout: 30000,
        });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(data);
        link.download = `predictions-${type.value}-${asOf.value || 'latest'}.csv`;
        link.click();
        URL.revokeObjectURL(link.href);
    } catch {
        replayStatus.value = 'Could not export the predictions. Try again.';
    } finally {
        exporting.value = false;
    }
}

// The replay is complete once it reaches its last day; after that the button rebuilds it instead.
const replayComplete = computed(() => report.value?.last_run === '2026-11-28');

async function replay() {
    const fresh = replayComplete.value;

    if (fresh && !confirm('Re-run the June replay?\n\nThis rebuilds every saved replay forecast from the sales recorded now and replaces the current results.')) {
        return;
    }

    running.value = true;
    replayStatus.value = '';
    try {
        const { data } = await api.post<{ saved: number; replaced: number; through: string }>(
            '/api/v1/reports/predictions/replay', { fresh }, { timeout: 90000 },
        );
        replayStatus.value = data.saved
            ? `${fresh ? 'Re-ran the replay: saved' : 'Saved'} ${data.saved.toLocaleString('en-PH')} replay forecasts through ${data.through}.`
            : `Replay already saved through ${data.through}.`;
        asOf.value = '';
        page.value = 1;
        refresh.value++;
    } catch {
        replayStatus.value = 'Replay could not be confirmed. Refresh the report to check whether it finished.';
    } finally {
        running.value = false;
    }
}

const chartRows = computed(() => (report.value?.predictions.data ?? []).slice().sort((a, b) => a.date.localeCompare(b.date)));
const chartAmounts = computed(() => chartRows.value.flatMap(row => [row.estimate, row.actual]).filter(value => value !== null));
const chartBottom = computed(() => Math.min(0, ...chartAmounts.value));
const chartTop = computed(() => Math.max(1, ...chartAmounts.value));
const chartWidth = computed(() => Math.max(1, chartRows.value.length) * 66);
const plot = (value: number) => 120 - (value - chartBottom.value) / (chartTop.value - chartBottom.value) * 120;
function trace(name: 'estimate' | 'actual') {
    let connected = false;
    return chartRows.value.map((row, i) => {
        const value = row[name];
        if (value === null) {
            connected = false;
            return '';
        }
        const segment = `${connected ? 'L' : 'M'}${i * 66 + 33},${plot(value)}`;
        connected = true;
        return segment;
    }).join(' ');
}
</script>

<template>
    <section class="grid min-w-0 gap-3" aria-label="Prediction results">
        <div class="rounded-lg border bg-card p-4">
            <h2 class="text-lg font-bold">Predictions</h2>
            <div class="mt-2 flex flex-wrap items-end gap-3">
                <div class="flex gap-1" aria-label="Forecast source">
                    <button v-for="mode in (['replay', 'live'] as const)" :key="mode" type="button"
                        :aria-pressed="type === mode" class="min-h-11 rounded border px-3 text-sm aria-pressed:bg-muted aria-pressed:font-bold"
                        @click="type = mode; page = 1; asOf = ''">{{ mode === 'replay' ? 'June replay' : 'Live forecasts' }}</button>
                </div>
                <label class="grid gap-1 text-xs font-semibold">Forecast as of
                    <input v-model="asOf" type="date" :min="report?.first_run ?? undefined" :max="report?.last_run ?? undefined"
                        class="min-h-11 rounded border bg-background px-2 text-sm" @change="page = 1" />
                </label>
                <button v-if="asOf" type="button" class="min-h-11 text-sm text-[#ad3b19] underline" @click="asOf = ''; page = 1">Latest per service date</button>
                <button type="button" class="min-h-11 rounded border px-3 text-sm font-semibold disabled:opacity-60" :disabled="exporting" @click="exportCsv">
                    {{ exporting ? 'Exporting…' : 'Export CSV' }}</button>
            </div>
            <p class="mt-2 text-sm text-muted-foreground">
                {{ type === 'replay'
                    ? 'Simulated daily from June 1, 2026. Each forecast uses sales already recorded before that date. Earlier rain observations inform the historical outlook.'
                    : 'Forecasts saved by the daily production batch.' }}
            </p>
            <p v-if="report" class="mt-1 text-xs text-muted-foreground">
                {{ report.first_run ? `${report.first_run} to ${report.last_run} · ${report.run_days} forecast runs` : 'No forecast runs saved yet.' }}
            </p>
            <button v-if="type === 'replay' && report?.can_run_replay" type="button"
                class="mt-2 min-h-11 rounded bg-[#ad3b19] px-3 text-sm font-bold text-white disabled:opacity-60"
                :disabled="running" @click="replay">
                {{ running ? 'Running replay…' : replayComplete ? 'Re-run replay' : 'Run June replay' }}</button>
            <p v-if="type === 'replay' && report?.can_run_replay && replayComplete && !running" class="mt-1 text-xs text-muted-foreground">
                Rebuilds the replay from the sales recorded now. Takes up to a minute.</p>
            <p v-if="replayStatus" role="status" class="mt-1 text-sm">{{ replayStatus }}</p>
        </div>

        <template v-if="report">
            <dl class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                <div class="rounded border bg-card p-3"><dt class="text-sm">Hits</dt><dd class="text-lg font-bold">{{ report.hits }} / {{ report.hits + report.misses }}</dd></div>
                <div class="rounded border bg-card p-3"><dt class="text-sm">Misses</dt><dd class="text-lg font-bold">{{ report.misses }}</dd></div>
                <div class="rounded border bg-card p-3"><dt class="text-sm">Average absolute error</dt><dd class="text-lg font-bold">{{ money(report.mean_error) }}</dd></div>
                <div class="rounded border bg-card p-3"><dt class="text-sm">Weekday baseline error</dt><dd class="text-lg font-bold">{{ money(report.baseline_mean_error) }}</dd></div>
                <div class="rounded border bg-card p-3"><dt class="text-sm">In likely range</dt>
                    <dd class="text-lg font-bold">{{ report.ranged ? `${report.in_range} / ${report.ranged}` : '—' }}</dd>
                    <dd class="text-xs text-muted-foreground">Aims for about 6 in 10</dd>
                </div>
                <div class="rounded border bg-card p-3"><dt class="text-sm">Average bias</dt>
                    <dd class="text-lg font-bold">{{ money(report.mean_bias) }}</dd>
                    <dd v-if="report.mean_bias" class="text-xs text-muted-foreground">
                        {{ report.mean_bias > 0 ? 'Forecasts usually run low' : 'Forecasts usually run high' }}</dd>
                </div>
            </dl>
            <p v-if="asOf && !report.hits && !report.misses && !report.no_estimate" role="note"
                class="rounded border border-dashed p-3 text-sm text-muted-foreground">
                Forecasts made on {{ dayLabel(asOf) }} are for that day and the days after it, which haven't been scored yet.
                Pick an earlier date, or use <button type="button" class="text-[#ad3b19] underline" @click="asOf = ''; page = 1">latest per service date</button>
                to see how each day's forecast turned out.</p>
            <p class="text-sm text-muted-foreground">A hit is within {{ report.tolerance_pct }}% of the estimate. The likely range is where most past days landed around a similar forecast, so it shows how far a normal day can swing.
                {{ report.no_estimate }} completed dates had sales but too little history for an estimate. Days the store was closed are left out of training and scoring.</p>
            <div v-if="chartAmounts.length" class="min-w-0 rounded-lg border bg-card p-4">
                <h3 class="font-bold">Forecast vs. actual net sales</h3>
                <p class="mt-1 text-xs text-muted-foreground">Orange forecast · blue actual · {{ money(chartBottom) }}–{{ money(chartTop) }}</p>
                <div class="mt-2 min-w-0 overflow-x-auto pb-1">
                    <div :style="{ width: chartWidth + 'px' }">
                        <svg role="img" aria-label="Forecast and actual sales for the dates on this page" class="block w-full overflow-visible" :viewBox="'0 0 ' + chartWidth + ' 120'" height="120">
                            <line v-for="y in [30, 60, 90, 120]" :key="y" x1="0" :x2="chartWidth" :y1="y" :y2="y" stroke="#ded7cb" />
                            <path :d="trace('estimate')" fill="none" stroke="#ad3b19" stroke-width="2" />
                            <path :d="trace('actual')" fill="none" stroke="#285c81" stroke-width="2" />
                            <g v-for="(row, i) in chartRows" :key="row.date">
                                <circle v-if="row.estimate !== null" :cx="i * 66 + 33" :cy="plot(row.estimate)" r="3" fill="#ad3b19" />
                                <circle v-if="row.actual !== null" :cx="i * 66 + 33" :cy="plot(row.actual)" r="3" fill="#285c81" />
                            </g>
                        </svg>
                        <div class="mt-1 flex text-center text-xs text-muted-foreground">
                            <time v-for="row in chartRows" :key="row.date" :datetime="row.date" class="w-[66px] shrink-0">{{ dayLabel(row.date) }}</time>
                        </div>
                    </div>
                </div>
            </div>
            <div v-if="report.predictions.data.length" class="grid gap-2 sm:grid-cols-2">
                <article v-for="row in report.predictions.data" :key="row.date" class="rounded border bg-card p-3 text-sm">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <strong>{{ row.date }}
                            <span v-if="row.special_day" class="ml-1 rounded bg-[#f2e5d8] px-1.5 py-0.5 text-xs font-bold text-[#ad3b19]">
                                {{ specialLabel[row.special_day] }}<template v-if="row.uplift !== null">{{ ' ' + pct(row.uplift) }}</template></span></strong>
                        <span class="font-semibold" :class="row.result === 'hit' ? 'text-emerald-800' : row.result === 'miss' ? 'text-[#ad3b19]' : 'text-muted-foreground'">{{ status[row.result] }}</span>
                    </div>
                    <p class="text-xs text-muted-foreground">As of {{ row.as_of }} · {{ row.sample_days }} training dates for this weekday</p>
                    <dl class="mt-2 grid grid-cols-2 gap-2">
                        <div><dt>Forecast</dt><dd class="font-bold">{{ money(row.estimate) }}</dd>
                            <dd v-if="row.range_low !== null && row.range_high !== null" class="text-xs text-muted-foreground">Likely {{ money(row.range_low) }}–{{ money(row.range_high) }}</dd></div>
                        <div><dt>Actual</dt><dd class="font-bold">{{ money(row.actual) }}</dd></div>
                        <div><dt>Actual minus forecast</dt><dd>{{ money(row.error) }}</dd></div>
                        <div><dt>Weekday baseline error</dt><dd>{{ money(row.baseline_error === null ? null : Math.abs(row.baseline_error)) }}</dd></div>
                    </dl>
                    <p class="mt-2 text-xs text-muted-foreground">{{ row.payday_distance }} days from payday ·
                        {{ row.weather_source === 'history' ? 'Historical rain frequency' : 'Rain outlook' }}
                        {{ row.rain_chance === null ? '—' : Math.round(row.rain_chance) + '%' }} ·
                        Observed rain {{ row.observed_rain_mm === null ? '—' : row.observed_rain_mm + ' mm' }}
                    </p>
                </article>
            </div>
            <p v-else class="rounded border bg-card p-4 text-sm">No predictions for this view.</p>
            <nav v-if="report.predictions.last_page > 1" aria-label="Prediction pages" class="flex items-center justify-between gap-2">
                <button type="button" :disabled="page <= 1" class="min-h-11 rounded border px-3 disabled:opacity-50" @click="page--">Previous</button>
                <span class="text-sm">{{ page }} / {{ report.predictions.last_page }}</span>
                <button type="button" :disabled="page >= report.predictions.last_page" class="min-h-11 rounded border px-3 disabled:opacity-50" @click="page++">Next</button>
            </nav>
            <a v-if="report.predictions.data.some(row => row.rain_chance !== null || row.observed_rain_mm !== null)"
                href="https://www.visualcrossing.com/" target="_blank" rel="noopener noreferrer" class="w-fit text-xs text-[#ad3b19] underline">Weather Data Provided by Visual Crossing</a>
        </template>
        <p v-else role="status" class="rounded border bg-card p-4 text-sm">
            <template v-if="loadError">Could not load predictions. <button type="button" class="min-h-11 text-[#ad3b19] underline" @click="refresh++">Try again</button></template>
            <template v-else>Loading predictions…</template>
        </p>
    </section>
</template>
