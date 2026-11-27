<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import api from '@/utils/api';

type Sample = { days: number; net: number | null };
type Weather = { rainy: boolean | null; rain_mm: number | null };
type Review = {
    date: string; weekday: string; net_sales: number; has_receipts: boolean; payday: boolean; weather: Weather;
    previous_week: { date: string; net_sales: number | null; change_pct: number | null; payday: boolean; weather: Weather; headcount: number | null; staff_hours: number | null };
    typical: Sample & { change_pct: number | null };
    same_payday_status: Sample; different_payday_status: Sample;
    same_payday_and_weather: Sample; different_weather_same_payday: Sample;
    can_edit_staffing: boolean;
    staffing: {
        headcount: number | null; staff_hours: number | null; net_per_hour: number | null;
        matched_days: number; typical_per_hour: number | null; typical_headcount: number | null; typical_staff_hours: number | null;
        fewer_staff: Sample; more_staff: Sample;
    };
};

const manila = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' });
const latest = manila.format(new Date(Date.now() - 86400000));
const latestTradingDay = new Date(`${latest}T00:00:00Z`);
while (![0, 5, 6].includes(latestTradingDay.getUTCDay())) latestTradingDay.setUTCDate(latestTradingDay.getUTCDate() - 1);
const date = ref(latestTradingDay.toISOString().slice(0, 10));
const report = ref<Review>();
const failed = ref(false);
const attempt = ref(0);
const headcount = ref<number | ''>('');
const hours = ref<number | ''>('');
const saving = ref(false);
const saveMessage = ref('');
const open = ref(false);
const moneyFormat = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
const money = (n: number | null) => n === null ? '—' : moneyFormat.format(n);
const rain = (weather: Weather) => weather.rainy === null ? 'Weather unavailable' : weather.rainy ? 'Rain observed' : 'Dry';
const difference = (n: number | null) => n === null ? '—' : `${n > 0 ? '+' : ''}${n.toFixed(1)}%`;
const direction = computed(() => {
    const pct = report.value?.typical.change_pct;
    if (pct == null) return 'Limited comparable sales history';
    if (pct === 0) return 'In line with typical sales';
    return pct > 0 ? 'Above typical sales' : 'Below typical sales';
});
const scale = computed(() => Math.max(1, Math.abs(report.value?.net_sales ?? 0),
    Math.abs(report.value?.previous_week.net_sales ?? 0), Math.abs(report.value?.typical.net ?? 0)));
const barWidth = (n: number | null) => `${Math.abs(n ?? 0) / scale.value * 100}%`;
const observations = computed(() => {
    const data = report.value;
    if (!data) return [];
    function compare(first: Sample, second: Sample, label: string) {
        if (first.days < 2 || second.days < 2 || first.net === null || second.net === null) return null;
        const gap = first.net - second.net;
        return gap === 0 ? `${label} had the same median net sales.`
            : `${label} had ${money(Math.abs(gap))} ${gap > 0 ? 'higher' : 'lower'} median net sales.`;
    }

    return [
        compare(data.payday ? data.same_payday_status : data.different_payday_status,
            data.payday ? data.different_payday_status : data.same_payday_status, 'Paydays compared with regular dates'),
        data.weather.rainy === null ? null : compare(
            data.weather.rainy ? data.same_payday_and_weather : data.different_weather_same_payday,
            data.weather.rainy ? data.different_weather_same_payday : data.same_payday_and_weather,
            'Rainy dates compared with dry dates of the same payday status'),
    ].filter(text => text !== null);
});
const contexts = computed(() => {
    const data = report.value;
    if (!data) return [];
    return [
        { label: 'Same payday status', value: data.same_payday_status },
        { label: 'Opposite payday status', value: data.different_payday_status },
        { label: 'Same payday status and weather', value: data.same_payday_and_weather },
        { label: 'Same payday status, different weather', value: data.different_weather_same_payday },
    ];
});

watch([date, attempt, open], async ([day, , expanded], _last, onCleanup) => {
    report.value = undefined;
    failed.value = false;
    if (!expanded || !day || day > latest) return;
    const request = new AbortController();
    onCleanup(() => request.abort());
    try {
        const { data } = await api.get<Review>('/api/v1/reports/operating-review', {
            params: { date: day }, signal: request.signal, timeout: 20000,
        });
        if (request.signal.aborted) return;
        report.value = data;
        headcount.value = data.staffing.headcount ?? '';
        hours.value = data.staffing.staff_hours ?? '';
    } catch {
        if (!request.signal.aborted) failed.value = true;
    }
});

async function saveStaffing() {
    if (!headcount.value || !hours.value || saving.value) return;
    const day = date.value;
    saving.value = true;
    saveMessage.value = '';
    try {
        await api.put('/api/v1/reports/operating-staffing', { date: day, headcount: headcount.value, staff_hours: hours.value });
        if (date.value === day) {
            saveMessage.value = 'Staffing saved.';
            attempt.value++;
        }
    } catch (error) {
        const response = error as { response?: { data?: { message?: string } } };
        if (date.value === day) saveMessage.value = response.response?.data?.message ?? 'Could not save staffing.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <section aria-label="Net sales drivers" class="min-w-0 rounded-xl border bg-card">
        <button type="button" class="flex min-h-12 w-full flex-wrap items-center justify-between gap-2 p-4 text-left"
            :aria-expanded="open" aria-controls="sales-driver-detail" @click="open = !open">
            <span><strong class="block text-base">Net sales drivers</strong><span class="text-sm text-muted-foreground">Compare weather, payday and staffing for a completed day.</span></span>
            <span class="font-semibold text-[#ad3b19]">{{ open ? 'Hide review' : 'Open review' }}</span>
        </button>

        <div v-if="open" id="sales-driver-detail" class="grid min-w-0 gap-4 border-t p-4">
            <label class="grid w-fit gap-1 text-sm font-semibold">Trading date
                <input v-model="date" type="date" :max="latest" class="min-h-11 rounded-md border bg-background px-3" @change="saveMessage = ''" />
            </label>
            <template v-if="report">
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <article class="rounded-md bg-muted p-3">
                        <h3 class="text-sm font-semibold">Selected day</h3>
                        <p class="text-xl font-bold">{{ money(report.net_sales) }}</p>
                        <div aria-hidden="true" class="my-1 h-1 w-full rounded bg-[#ded7cb]"><div class="h-full rounded bg-[#ad3b19]" :style="{ width: barWidth(report.net_sales) }"></div></div>
                        <p class="text-xs text-muted-foreground">{{ report.payday ? 'Payday' : 'Regular date' }} · {{ rain(report.weather) }}</p>
                        <p v-if="!report.has_receipts" class="text-xs text-muted-foreground">No positive payments recorded.</p>
                    </article>
                    <article class="rounded-md bg-muted p-3">
                        <h3 class="text-sm font-semibold">Same day last week</h3>
                        <p class="text-xl font-bold">{{ money(report.previous_week.net_sales) }}</p>
                        <div aria-hidden="true" class="my-1 h-1 w-full rounded bg-[#ded7cb]"><div class="h-full rounded bg-[#ad3b19]" :style="{ width: barWidth(report.previous_week.net_sales) }"></div></div>
                        <p class="text-xs text-muted-foreground">{{ report.previous_week.date }} · {{ difference(report.previous_week.change_pct) }} change</p>
                        <p class="text-xs text-muted-foreground">{{ report.previous_week.payday ? 'Payday' : 'Regular date' }} · {{ rain(report.previous_week.weather) }}</p>
                    </article>
                    <article class="rounded-md bg-muted p-3">
                        <h3 class="text-sm font-semibold">Typical {{ report.weekday }}</h3>
                        <p class="text-xl font-bold">{{ money(report.typical.net) }}</p>
                        <div aria-hidden="true" class="my-1 h-1 w-full rounded bg-[#ded7cb]"><div class="h-full rounded bg-[#ad3b19]" :style="{ width: barWidth(report.typical.net) }"></div></div>
                        <p class="text-xs text-muted-foreground">{{ difference(report.typical.change_pct) }} change · {{ report.typical.days }} prior dates</p>
                    </article>
                </div>
                <div>
                    <h3 class="font-bold">{{ direction }}</h3>
                    <p class="mt-1 text-sm text-muted-foreground">Net sales are payment collections after refunds. Each comparison uses the median of prior dates with recorded sales, up to 12 weeks back.</p>
                    <dl class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <div v-for="context in contexts" :key="context.label" class="rounded-md border p-2 text-sm">
                            <dt>{{ context.label }}</dt><dd class="font-bold">{{ money(context.value.net) }} <span class="font-normal text-muted-foreground">· {{ context.value.days }} dates</span></dd>
                        </div>
                    </dl>
                    <ul v-if="observations.length" class="mt-3 grid list-disc gap-1 pl-5 text-sm">
                        <li v-for="note in observations" :key="note">{{ note }}</li>
                    </ul>
                    <p class="mt-2 text-sm text-muted-foreground">Weather comparisons use observed rain during trading hours. Small groups provide limited evidence, and these comparisons show associations.</p>
                    <a v-if="report.weather.rainy !== null || report.previous_week.weather.rainy !== null || report.same_payday_and_weather.days || report.different_weather_same_payday.days" class="mt-1 inline-block text-xs text-[#ad3b19] underline" href="https://www.visualcrossing.com/" target="_blank" rel="noopener noreferrer">Weather Data Provided by Visual Crossing</a>
                </div>

                <div class="border-t pt-3">
                    <h3 class="font-bold">Staffing and sales</h3>
                    <p class="mt-1 text-sm text-muted-foreground">Staff-hours add up the hours worked by everyone on shift.</p>
                    <dl class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <div class="rounded-md bg-muted p-2 text-sm"><dt>Selected day</dt><dd class="font-bold">{{ report.staffing.headcount ?? '—' }} staff · {{ report.staffing.staff_hours ?? '—' }} hours</dd><dd>{{ money(report.staffing.net_per_hour) }} per staff-hour</dd></div>
                        <div class="rounded-md bg-muted p-2 text-sm"><dt>Last week</dt><dd class="font-bold">{{ report.previous_week.headcount ?? '—' }} staff · {{ report.previous_week.staff_hours ?? '—' }} hours</dd></div>
                        <div class="rounded-md bg-muted p-2 text-sm"><dt>Similar logged days</dt><dd class="font-bold">{{ report.staffing.typical_headcount ?? '—' }} staff · {{ report.staffing.typical_staff_hours ?? '—' }} hours</dd><dd>{{ money(report.staffing.typical_per_hour) }} per staff-hour · {{ report.staffing.matched_days }} dates</dd></div>
                    </dl>
                    <p v-if="report.staffing.matched_days < 2" class="mt-2 text-sm text-muted-foreground">Record more shifts with the same weekday, payday status and weather to establish a staffing comparison.</p>
                    <dl v-else class="mt-2 grid grid-cols-2 gap-2 text-sm">
                        <div><dt>Similar dates with fewer staff</dt><dd>{{ money(report.staffing.fewer_staff.net) }} net · {{ report.staffing.fewer_staff.days }} dates</dd></div>
                        <div><dt>Similar dates with more staff</dt><dd>{{ money(report.staffing.more_staff.net) }} net · {{ report.staffing.more_staff.days }} dates</dd></div>
                    </dl>
                    <form v-if="report.can_edit_staffing" class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="saveStaffing">
                        <label class="grid gap-1 text-sm">Actual headcount<input v-model.number="headcount" type="number" min="1" max="100" step="1" required class="min-h-11 w-32 rounded-md border bg-background px-2" /></label>
                        <label class="grid gap-1 text-sm">Total staff-hours<input v-model.number="hours" type="number" min="0.01" :max="headcount ? headcount * 24 : 2400" step="0.01" required class="min-h-11 w-32 rounded-md border bg-background px-2" /></label>
                        <button type="submit" class="min-h-11 rounded-md bg-[#ad3b19] px-4 text-sm font-bold text-white disabled:opacity-60" :disabled="saving">{{ saving ? 'Saving…' : 'Save staffing' }}</button>
                    </form>
                    <p v-if="saveMessage" role="status" class="mt-2 text-sm">{{ saveMessage }}</p>
                </div>
            </template>
            <p v-else role="status" class="text-sm text-muted-foreground">
                <template v-if="failed">Could not load the review. <button type="button" class="min-h-11 px-2 text-[#ad3b19] underline" @click="attempt++">Try again</button></template>
                <template v-else>{{ !date || date > latest ? 'Choose a completed date.' : 'Loading sales and staffing…' }}</template>
            </p>
        </div>
    </section>
</template>
