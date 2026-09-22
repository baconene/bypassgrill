<script setup lang="ts">
import { Download, RefreshCw, Users } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import api from '@/utils/api';

defineProps<{ employees: { id: number; name: string }[] }>();

interface Row {
    employee_id: number | null;
    name: string;
    position: string | null;
    payments: number;
    days_worked: number;
    gross: number;
    deductions: number;
    amount: number;
}
interface Report {
    period: { start: string; end: string };
    total: number;
    gross_total: number;
    deductions_total: number;
    payment_count: number;
    employee_count: number;
    unassigned: number;
    employees: Row[];
    daily: { date: string; amount: number }[];
}
interface Preset {
    key: string;
    label: string;
    range: () => [string, string];
}

// Manila calendar dates, so "this month" doesn't slip before 8am
const today = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
}).format(new Date());
const firstOfMonth = (iso: string) => `${iso.slice(0, 7)}-01`;
const shiftDays = (iso: string, days: number) => {
    const date = new Date(`${iso}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
};
const daySpan = (from: string, to: string) =>
    Math.round(
        (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) /
            86400000,
    ) + 1;

const presets: Preset[] = [
    {
        key: 'month',
        label: 'This month',
        range: () => [firstOfMonth(today), today],
    },
    {
        key: 'last-month',
        label: 'Last month',
        range: () => {
            const end = shiftDays(firstOfMonth(today), -1);

            return [firstOfMonth(end), end];
        },
    },
    {
        key: '90-days',
        label: 'Last 90 days',
        range: () => [shiftDays(today, -89), today],
    },
    {
        key: 'year',
        label: 'This year',
        range: () => [`${today.slice(0, 4)}-01-01`, today],
    },
];

const start = ref(firstOfMonth(today));
const end = ref(today);
const employee = ref('');
const report = ref<Report | null>(null);
const busy = ref(false);
const error = ref('');
const applied = ref('');
const filterKey = computed(() =>
    [start.value, end.value, employee.value].join('|'),
);
const stale = computed(() => applied.value !== filterKey.value);
const activePreset = computed(
    () =>
        presets.find((preset) => {
            const [from, to] = preset.range();

            return from === start.value && to === end.value;
        })?.key ?? null,
);

const money = (amount: number) =>
    new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(amount ?? 0);
const compactMoney = (amount: number) =>
    new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(amount ?? 0);
const dayLabel = new Intl.DateTimeFormat('en-PH', {
    month: 'short',
    day: 'numeric',
    timeZone: 'UTC',
});
const monthLabel = new Intl.DateTimeFormat('en-PH', {
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});
const longDate = new Intl.DateTimeFormat('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    timeZone: 'UTC',
});
const fmtDate = (iso: string) => longDate.format(new Date(`${iso}T12:00:00Z`));

let requestId = 0;

async function load() {
    if (!start.value || !end.value || end.value < start.value) {
        error.value =
            'Choose a valid period, with the end date on or after the start date.';

        return;
    }

    const id = ++requestId;
    const requestedKey = filterKey.value;
    busy.value = true;
    error.value = '';

    try {
        const { data } = await api.get('/api/v1/hris/payroll-report', {
            params: {
                start_date: start.value,
                end_date: end.value,
                employee_id: employee.value || undefined,
            },
        });

        if (id !== requestId) {
            return;
        }

        report.value = data;
        applied.value = requestedKey;
    } catch {
        if (id === requestId) {
            error.value = 'Unable to load payroll spending. Please try again.';
        }
    } finally {
        if (id === requestId) {
            busy.value = false;
        }
    }
}

function applyPreset(preset: Preset) {
    [start.value, end.value] = preset.range();
    load();
}

// Long periods read better by month than as hundreds of daily bars
const byMonth = computed(() =>
    report.value
        ? daySpan(report.value.period.start, report.value.period.end) > 62
        : false,
);
const trend = computed(() => {
    const buckets = new Map<string, number>();

    for (const row of report.value?.daily ?? []) {
        const key = byMonth.value ? row.date.slice(0, 7) : row.date;
        buckets.set(key, (buckets.get(key) ?? 0) + row.amount);
    }

    return [...buckets].map(([key, amount]) => ({
        label: byMonth.value
            ? monthLabel.format(new Date(`${key}-01T12:00:00Z`))
            : dayLabel.format(new Date(`${key}T12:00:00Z`)),
        amount: Math.round(amount * 100) / 100,
    }));
});
const peak = computed(() =>
    trend.value.reduce<{ label: string; amount: number } | null>(
        (best, point) => (!best || point.amount > best.amount ? point : best),
        null,
    ),
);
const averagePerEmployee = computed(() =>
    report.value?.employee_count
        ? (report.value.total - report.value.unassigned) /
          report.value.employee_count
        : 0,
);
const share = (amount: number) =>
    report.value && report.value.total > 0
        ? Math.max(0, (amount / report.value.total) * 100)
        : 0;

const chartOptions = computed(() => ({
    chart: {
        type: 'bar',
        toolbar: { show: false },
        animations: { enabled: false },
        fontFamily: 'Arial, Helvetica, sans-serif',
        foreColor: '#68665f',
        parentHeightOffset: 0,
    },
    colors: ['#c3441c'],
    plotOptions: {
        bar: {
            borderRadius: 3,
            columnWidth: trend.value.length > 20 ? '70%' : '45%',
        },
    },
    dataLabels: { enabled: false },
    xaxis: {
        categories: trend.value.map((point) => point.label),
        axisBorder: { color: '#ded7cb' },
        axisTicks: { color: '#ded7cb' },
        labels: { hideOverlappingLabels: true },
    },
    yaxis: { labels: { formatter: (value: number) => compactMoney(value) } },
    tooltip: { y: { formatter: (value: number) => money(value) } },
    grid: { borderColor: '#ece5da', strokeDashArray: 3 },
}));
const series = computed(() => [
    {
        name: 'Net payroll paid',
        data: trend.value.map((point) => point.amount),
    },
]);

function exportCsv() {
    if (!report.value || stale.value || busy.value) {
        return;
    }

    const current = report.value;
    // Quote every cell and neutralise spreadsheet formulas
    const cell = (value: string | number) =>
        `"${String(value)
            .replace(/^[=+@-]/, "'$&")
            .replaceAll('"', '""')}"`;
    const rows: (string | number)[][] = [
        ['Payroll spending', current.period.start, current.period.end],
        [
            'Employee',
            'Position',
            'Payments',
            'Days worked',
            'Gross',
            'Deductions',
            'Net paid',
            'Share %',
        ],
        ...current.employees.map((row) => [
            row.name,
            row.position ?? '',
            row.payments,
            row.days_worked,
            row.gross.toFixed(2),
            row.deductions.toFixed(2),
            row.amount.toFixed(2),
            share(row.amount).toFixed(1),
        ]),
        [
            'Total',
            '',
            current.payment_count,
            '',
            current.gross_total.toFixed(2),
            current.deductions_total.toFixed(2),
            current.total.toFixed(2),
            current.total > 0 ? '100.0' : '',
        ],
    ];
    const url = URL.createObjectURL(
        new Blob(
            [`﻿${rows.map((row) => row.map(cell).join(',')).join('\r\n')}`],
            { type: 'text/csv;charset=utf-8' },
        ),
    );
    const link = document.createElement('a');
    link.href = url;
    link.download = `payroll-${current.period.start}-${current.period.end}.csv`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

onMounted(load);
</script>

<template>
    <section class="payroll-report" aria-label="Payroll spending report">
        <div class="panel">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">PAYROLL INSIGHTS</p>
                    <h2>Where your payroll goes</h2>
                    <p class="panel-copy">
                        Net pay released in the period, by the date it was paid.
                        Unpaid payroll isn't counted.
                    </p>
                </div>
                <button
                    class="ghost-button"
                    :disabled="!report || stale || busy"
                    @click="exportCsv"
                >
                    <Download :size="15" aria-hidden="true" />Export CSV
                </button>
            </div>
            <form class="filters" @submit.prevent="load">
                <label class="field"
                    >From<input
                        v-model="start"
                        type="date"
                        required
                        :max="end || undefined"
                /></label>
                <label class="field"
                    >To<input
                        v-model="end"
                        type="date"
                        required
                        :min="start || undefined"
                /></label>
                <label class="field"
                    >Employee<select v-model="employee">
                        <option value="">All employees</option>
                        <option
                            v-for="e in employees"
                            :key="e.id"
                            :value="String(e.id)"
                        >
                            {{ e.name }}
                        </option>
                    </select></label
                >
                <button class="solid-button" :disabled="busy">
                    <RefreshCw
                        :size="15"
                        :class="{ spinning: busy }"
                        aria-hidden="true"
                    />{{ busy ? 'Loading…' : 'Apply' }}
                </button>
            </form>
            <div class="chips" role="group" aria-label="Quick periods">
                <button
                    v-for="preset in presets"
                    :key="preset.key"
                    type="button"
                    :class="{ active: activePreset === preset.key }"
                    :aria-pressed="activePreset === preset.key"
                    @click="applyPreset(preset)"
                >
                    {{ preset.label }}
                </button>
            </div>
        </div>

        <p v-if="error" role="alert" class="notice notice-error">{{ error }}</p>
        <p v-else-if="report && stale && !busy" role="status" class="notice">
            Filters changed. Select Apply to update the report.
        </p>

        <template v-if="report">
            <p class="period-label">
                {{ fmtDate(report.period.start) }} –
                {{ fmtDate(report.period.end) }}
            </p>
            <section class="metric-grid" aria-label="Payroll totals">
                <article class="metric metric-featured">
                    <p>Net payroll paid</p>
                    <strong>{{ money(report.total) }}</strong
                    ><span
                        >{{ report.payment_count }} payment{{
                            report.payment_count === 1 ? '' : 's'
                        }}
                        released</span
                    >
                </article>
                <article class="metric">
                    <p>Gross payroll</p>
                    <strong>{{ money(report.gross_total) }}</strong
                    ><span>Before deductions</span>
                </article>
                <article class="metric">
                    <p>Deductions withheld</p>
                    <strong>{{ money(report.deductions_total) }}</strong
                    ><span>SSS, PhilHealth, Pag-IBIG and others</span>
                </article>
                <article class="metric">
                    <p>Average per employee</p>
                    <strong>{{ money(averagePerEmployee) }}</strong
                    ><span
                        >{{ report.employee_count }} employee{{
                            report.employee_count === 1 ? '' : 's'
                        }}
                        paid</span
                    >
                </article>
            </section>
            <p v-if="report.unassigned > 0" class="notice">
                {{ money(report.unassigned) }} was paid through payroll entries
                with no employee record. It's included in the totals so they
                match Financial, and shown as "Unassigned payroll" below.
            </p>

            <div v-if="!report.payment_count" class="panel empty-state">
                <Users :size="26" aria-hidden="true" />
                <h3>No payroll paid in this period.</h3>
                <p>
                    Try another period or employee. Payroll only counts once
                    it's marked paid.
                </p>
            </div>
            <template v-else>
                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">
                                {{ byMonth ? 'BY MONTH' : 'BY DAY' }}
                            </p>
                            <h2>Spending over time</h2>
                        </div>
                        <p v-if="peak" class="panel-note">
                            Highest:
                            <strong>{{ money(peak.amount) }}</strong> on
                            {{ peak.label }}
                        </p>
                    </div>
                    <div aria-hidden="true">
                        <apexchart
                            type="bar"
                            height="260"
                            :options="chartOptions"
                            :series="series"
                        />
                    </div>
                    <p class="sr-only">
                        Net payroll paid
                        {{ byMonth ? 'per month' : 'per day' }}:
                        {{
                            trend
                                .map(
                                    (point) =>
                                        `${point.label} ${money(point.amount)}`,
                                )
                                .join(', ')
                        }}.
                    </p>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">DISTRIBUTION PER EMPLOYEE</p>
                            <h2>Who received what</h2>
                        </div>
                        <span class="count-badge"
                            >{{ report.employees.length }}
                            {{
                                report.employees.length === 1 ? 'row' : 'rows'
                            }}</span
                        >
                    </div>

                    <div class="card-list">
                        <article
                            v-for="row in report.employees"
                            :key="row.employee_id ?? 'unassigned'"
                            class="share-card"
                        >
                            <div class="share-card-top">
                                <div>
                                    <strong>{{ row.name }}</strong>
                                    <p>
                                        {{
                                            row.position ||
                                            'No position recorded'
                                        }}
                                    </p>
                                </div>
                                <strong class="amount">{{
                                    money(row.amount)
                                }}</strong>
                            </div>
                            <div class="share-bar" aria-hidden="true">
                                <span
                                    :style="{ width: `${share(row.amount)}%` }"
                                />
                            </div>
                            <p class="share-meta">
                                {{ share(row.amount).toFixed(1) }}% of net
                                payroll · {{ row.payments }} payment{{
                                    row.payments === 1 ? '' : 's'
                                }}
                                <template v-if="row.employee_id !== null">
                                    · Gross {{ money(row.gross) }} · Deductions
                                    {{ money(row.deductions) }}</template
                                >
                            </p>
                        </article>
                        <div class="share-total">
                            <span>Total</span
                            ><strong>{{ money(report.total) }}</strong>
                        </div>
                    </div>

                    <div
                        class="table-scroll desktop-only"
                        tabindex="0"
                        role="region"
                        aria-label="Payroll per employee"
                    >
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Employee</th>
                                    <th scope="col" class="amount">Payments</th>
                                    <th scope="col" class="amount">Days</th>
                                    <th scope="col" class="amount">Gross</th>
                                    <th scope="col" class="amount">
                                        Deductions
                                    </th>
                                    <th scope="col" class="amount">Net paid</th>
                                    <th scope="col" class="share-col">
                                        Share of net payroll
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in report.employees"
                                    :key="row.employee_id ?? 'unassigned'"
                                >
                                    <td>
                                        <strong>{{ row.name }}</strong
                                        ><small>{{
                                            row.position ||
                                            'No position recorded'
                                        }}</small>
                                    </td>
                                    <td class="amount">{{ row.payments }}</td>
                                    <td class="amount">
                                        {{
                                            row.employee_id === null
                                                ? '—'
                                                : row.days_worked
                                        }}
                                    </td>
                                    <td class="amount">
                                        {{
                                            row.employee_id === null
                                                ? '—'
                                                : money(row.gross)
                                        }}
                                    </td>
                                    <td class="amount deduction">
                                        {{
                                            row.employee_id === null ||
                                            !row.deductions
                                                ? '—'
                                                : `−${money(row.deductions)}`
                                        }}
                                    </td>
                                    <td class="amount net">
                                        {{ money(row.amount) }}
                                    </td>
                                    <td class="share-col">
                                        <div class="share-cell">
                                            <div
                                                class="share-bar"
                                                aria-hidden="true"
                                            >
                                                <span
                                                    :style="{
                                                        width: `${share(row.amount)}%`,
                                                    }"
                                                />
                                            </div>
                                            <span
                                                >{{
                                                    share(row.amount).toFixed(
                                                        1,
                                                    )
                                                }}%</span
                                            >
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th scope="row">Total</th>
                                    <td class="amount">
                                        {{ report.payment_count }}
                                    </td>
                                    <td></td>
                                    <td class="amount">
                                        {{ money(report.gross_total) }}
                                    </td>
                                    <td class="amount deduction">
                                        {{
                                            report.deductions_total
                                                ? `−${money(report.deductions_total)}`
                                                : '—'
                                        }}
                                    </td>
                                    <td class="amount net">
                                        {{ money(report.total) }}
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>
            </template>
        </template>
        <div v-else-if="busy" class="panel empty-state" role="status">
            <RefreshCw :size="24" class="spinning" aria-hidden="true" />
            <h3>Loading payroll payments…</h3>
        </div>
    </section>
</template>

<style scoped>
.payroll-report {
    display: flex;
    flex-direction: column;
    gap: 18px;
    color: #24231e;
}
.panel {
    background: #fffcf6;
    border: 1px solid #ded7cb;
    border-radius: 6px;
    padding: 23px;
    min-width: 0;
}
.panel-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
    margin-bottom: 18px;
}
.panel h2 {
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -0.5px;
    line-height: 1.3;
}
.eyebrow {
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 1.6px;
    color: #ad3b19;
    margin-bottom: 7px;
}
.panel-copy {
    font-size: 12px;
    line-height: 1.7;
    color: #68665f;
    margin-top: 6px;
    max-width: 520px;
}
.panel-note {
    font-size: 11px;
    color: #68665f;
    text-align: right;
}
.panel-note strong {
    color: #24231e;
}
.filters {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr)) auto;
    align-items: end;
    gap: 12px;
}
.field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    color: #68665f;
    min-width: 0;
}
.field input,
.field select {
    width: 100%;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    background: #fff;
    padding: 10px 11px;
    font-size: 13px;
    font-weight: 400;
    letter-spacing: 0;
    text-transform: none;
    color: #24231e;
}
.solid-button,
.ghost-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 800;
    padding: 11px 15px;
    white-space: nowrap;
}
.solid-button {
    background: #c3441c;
    color: #fff;
}
.solid-button:hover:not(:disabled) {
    background: #a73513;
}
.ghost-button {
    border: 1px solid #d4cdbf;
    background: #fffcf6;
    color: #24231e;
}
.ghost-button:hover:not(:disabled) {
    background: #f2ede3;
}
.solid-button:disabled,
.ghost-button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 14px;
}
.chips button {
    border: 1px solid #d4cdbf;
    border-radius: 30px;
    padding: 7px 13px;
    font-size: 11px;
    font-weight: 700;
    color: #4d4a42;
    background: #fffcf6;
}
.chips button.active {
    background: #24231e;
    border-color: #24231e;
    color: #f6f2e9;
}
.notice {
    background: #f7eccf;
    border: 1px solid #ead9a9;
    color: #6b4d12;
    border-radius: 5px;
    padding: 12px 14px;
    font-size: 12px;
    line-height: 1.6;
}
.notice-error {
    background: #fbe9e4;
    border-color: #edc4b7;
    color: #a03015;
}
.period-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.4px;
    color: #68665f;
}
.metric-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}
.metric {
    background: #fffcf6;
    border: 1px solid #ded7cb;
    padding: 20px;
    border-radius: 5px;
    min-width: 0;
}
.metric-featured {
    background: #f2e5d8;
    border-color: #e4c4ab;
}
.metric p {
    font-size: 11px;
    font-weight: 700;
    color: #68665f;
}
.metric strong {
    display: block;
    font-size: clamp(20px, 2.1vw, 28px);
    letter-spacing: -1px;
    line-height: 1.3;
    margin: 11px 0 6px;
    overflow-wrap: anywhere;
    font-variant-numeric: tabular-nums;
}
.metric-featured strong {
    color: #ad3b19;
}
.metric > span {
    display: block;
    font-size: 10px;
    line-height: 1.6;
    color: #777268;
}
.count-badge {
    font-size: 10px;
    font-weight: 700;
    background: #f2e5d8;
    color: #ad3b19;
    padding: 4px 9px;
    border-radius: 20px;
    white-space: nowrap;
}
.table-scroll {
    overflow-x: auto;
}
table {
    border-collapse: collapse;
    width: 100%;
    white-space: nowrap;
    font-size: 12px;
}
th {
    text-align: left;
    font-size: 9px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #777268;
    padding: 0 14px 13px 0;
    font-weight: 700;
}
td {
    padding: 14px 14px 14px 0;
    border-top: 1px solid #ece5da;
    vertical-align: middle;
}
td small {
    display: block;
    color: #777268;
    font-size: 10px;
    margin-top: 4px;
}
tfoot th,
tfoot td {
    border-top: 2px solid #ded7cb;
    padding-top: 14px;
    font-size: 12px;
    font-weight: 800;
    color: #24231e;
    text-transform: none;
    letter-spacing: 0;
}
.amount {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.net {
    font-weight: 800;
}
.deduction {
    color: #a03015;
}
.share-col {
    width: 190px;
    padding-left: 10px;
    padding-right: 0;
}
.share-cell {
    display: flex;
    align-items: center;
    gap: 10px;
    font-variant-numeric: tabular-nums;
    font-size: 11px;
    color: #4d4a42;
}
.share-cell .share-bar {
    flex: 1;
}
.share-bar {
    height: 8px;
    border-radius: 8px;
    background: #efeadf;
    overflow: hidden;
}
.share-bar span {
    display: block;
    height: 100%;
    border-radius: 8px;
    background: #c3441c;
    min-width: 2px;
}
.card-list {
    display: none;
}
.share-card {
    padding: 14px 0;
    border-top: 1px solid #ece5da;
}
.share-card-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 9px;
    font-size: 13px;
}
.share-card-top p {
    font-size: 11px;
    color: #777268;
    margin-top: 3px;
}
.share-meta {
    font-size: 10px;
    line-height: 1.6;
    color: #68665f;
    margin-top: 7px;
}
.share-total {
    display: flex;
    justify-content: space-between;
    border-top: 2px solid #ded7cb;
    padding-top: 13px;
    font-size: 13px;
    font-weight: 800;
}
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 10px;
    padding: 34px 16px;
    color: #777268;
}
.empty-state > svg {
    color: #ad3b19;
}
.empty-state h3 {
    color: #24231e;
    font-size: 15px;
    font-weight: 700;
}
.empty-state p {
    font-size: 12px;
    line-height: 1.7;
    max-width: 360px;
}
.spinning {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
.payroll-report :focus-visible {
    outline: 2px solid #ad3b19;
    outline-offset: 3px;
}
.payroll-report button {
    cursor: pointer;
}
@media (max-width: 1000px) {
    .metric-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .filters {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 767px) {
    .desktop-only {
        display: none;
    }
    .card-list {
        display: block;
    }
    .panel {
        padding: 18px;
    }
    .panel-heading {
        flex-direction: column;
    }
    .panel-note {
        text-align: left;
    }
}
@media (max-width: 480px) {
    .filters {
        grid-template-columns: 1fr;
    }
    .metric-grid {
        gap: 10px;
    }
    .metric {
        padding: 15px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .spinning {
        animation: none;
    }
}
</style>
