<script setup lang="ts">
import { X } from 'lucide-vue-next';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';

/**
 * Full-screen view of one product's sales over the report period: summary numbers
 * (items sold, averages, best day), a bar chart by day / week / month with the
 * average marked, and the underlying numbers as a table.
 */
type Point = { date: string; qty: number; sales: number };
type Bucket = {
    key: string;
    label: string;
    short: string;
    from: string;
    to: string;
    days: number;
    qty: number;
    sales: number;
};
type Grouping = 'day' | 'week' | 'month';
type Metric = 'qty' | 'sales';

const props = defineProps<{
    productName: string;
    from: string;
    to: string;
    points: Point[];
    loading?: boolean;
}>();
const emit = defineEmits<{ close: [] }>();

const peso = (v: number) =>
    '₱' +
    Number(v || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
const pesoShort = (v: number) =>
    v >= 1_000_000
        ? '₱' + (v / 1_000_000).toFixed(1) + 'M'
        : v >= 1_000
          ? '₱' + (v / 1_000).toFixed(1) + 'K'
          : '₱' + Math.round(v);
const num = (v: number, digits = 1) =>
    Number(v || 0).toLocaleString('en-PH', { maximumFractionDigits: digits });
const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];
// Dates arrive as YYYY-MM-DD (Manila business days); handle them in UTC so the browser's zone never shifts a day
const parse = (d: string) => new Date(d + 'T00:00:00Z');
const iso = (d: Date) => d.toISOString().slice(0, 10);
const dayLabel = (d: string) => {
    const x = parse(d);

    return `${MONTHS[x.getUTCMonth()]} ${x.getUTCDate()}, ${x.getUTCFullYear()}`;
};

// ── Summary ──────────────────────────────────────────────────────────────────
const totals = computed(() => {
    const p = props.points;
    const qty = p.reduce((s, x) => s + Number(x.qty), 0);
    const sales = p.reduce((s, x) => s + Number(x.sales), 0);
    const sellingDays = p.filter((x) => Number(x.qty) > 0).length;
    const best = p.reduce<Point | null>(
        (b, x) => (!b || Number(x.qty) > Number(b.qty) ? x : b),
        null,
    );

    return {
        qty,
        sales,
        days: p.length,
        sellingDays,
        perDay: p.length ? qty / p.length : 0,
        perSellingDay: sellingDays ? qty / sellingDays : 0,
        salesPerDay: p.length ? sales / p.length : 0,
        avgPrice: qty ? sales / qty : 0,
        best: best && Number(best.qty) > 0 ? best : null,
    };
});

// ── Grouping ─────────────────────────────────────────────────────────────────
const grouping = ref<Grouping>('day');
const metric = ref<Metric>('qty');
const hideEmpty = ref(true);
// Long periods default to weeks so the bars stay readable on a phone
watch(
    () => props.points.length,
    (n) => {
        grouping.value = n > 120 ? 'month' : n > 45 ? 'week' : 'day';
    },
    { immediate: true },
);

const buckets = computed<Bucket[]>(() => {
    const out = new Map<string, Bucket>();

    for (const p of props.points) {
        const d = parse(p.date);
        let key: string, from: Date, to: Date;

        if (grouping.value === 'day') {
            key = p.date;
            from = to = d;
        } else if (grouping.value === 'week') {
            from = new Date(d);
            from.setUTCDate(d.getUTCDate() - ((d.getUTCDay() + 6) % 7)); // Monday
            to = new Date(from);
            to.setUTCDate(from.getUTCDate() + 6);
            key = iso(from);
        } else {
            from = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1));
            to = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0));
            key = iso(from);
        }

        let b = out.get(key);

        if (!b) {
            // Clip to the report period so a partial first/last week or month is visible in its label
            const f = iso(from) < props.from ? props.from : iso(from),
                t = iso(to) > props.to ? props.to : iso(to);
            const fd = parse(f),
                td = parse(t);
            const label =
                grouping.value === 'day' || f === t
                    ? dayLabel(f)
                    : grouping.value === 'week' ||
                        f !== iso(from) ||
                        t !== iso(to)
                      ? `${MONTHS[fd.getUTCMonth()]} ${fd.getUTCDate()} – ${MONTHS[td.getUTCMonth()]} ${td.getUTCDate()}, ${td.getUTCFullYear()}`
                      : `${MONTHS[fd.getUTCMonth()]} ${fd.getUTCFullYear()}`;
            const short =
                grouping.value === 'month'
                    ? MONTHS[from.getUTCMonth()]
                    : `${MONTHS[from.getUTCMonth()]} ${from.getUTCDate()}`;
            b = {
                key,
                label,
                short,
                from: f,
                to: t,
                days: 0,
                qty: 0,
                sales: 0,
            };
            out.set(key, b);
        }

        b.days++;
        b.qty += Number(p.qty);
        b.sales += Number(p.sales);
    }

    return [...out.values()];
});

const value = (b: Bucket) => (metric.value === 'qty' ? b.qty : b.sales);
const unitWord = computed(
    () => ({ day: 'day', week: 'week', month: 'month' })[grouping.value],
);
// Average per bucket from the first sale onwards (a product added mid-period is not
// dragged down by the months before it existed); quiet buckets after that still count
const firstSale = computed(() =>
    Math.max(
        0,
        buckets.value.findIndex((b) => b.qty > 0),
    ),
);
const average = computed(() => {
    const active = buckets.value.slice(firstSale.value);

    return active.length
        ? active.reduce((s, b) => s + value(b), 0) / active.length
        : 0;
});
const averageSince = computed(() => {
    const b = firstSale.value > 0 ? buckets.value[firstSale.value] : null;

    return b ? dayLabel(b.from) : null;
});
const fmtValue = (v: number) =>
    metric.value === 'qty' ? num(v) + ' sold' : peso(v);
const fmtAxis = (v: number) =>
    metric.value === 'qty' ? num(v, 0) : pesoShort(v);

// ── Chart geometry (pixel space, so text and rounded bar ends never stretch) ───
const wrap = ref<HTMLElement | null>(null);
const width = ref(360);
let ro: ResizeObserver | null = null;
const H = 280,
    padL = 52,
    padR = 12,
    padT = 14,
    padB = 28;
const MIN_BAR = 7; // below this the chart scrolls sideways instead of squeezing bars
const chartW = computed(() =>
    Math.max(width.value, padL + padR + buckets.value.length * MIN_BAR),
);
const innerW = computed(() => chartW.value - padL - padR);
const innerH = H - padT - padB;
const niceMax = computed(() => {
    const m = Math.max(...buckets.value.map(value), average.value, 1);
    const step = Math.pow(10, Math.floor(Math.log10(m)));
    const n = m / step;

    return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * step;
});
const ticks = computed(() =>
    [0, 0.25, 0.5, 0.75, 1].map((f) => f * niceMax.value),
);
const y = (v: number) => padT + innerH - (v / niceMax.value) * innerH;
const slot = computed(() => innerW.value / Math.max(buckets.value.length, 1));
const gap = computed(() => (slot.value >= 6 ? 2 : 1));
const bars = computed(() =>
    buckets.value.map((b, i) => {
        const v = value(b),
            x = padL + i * slot.value + gap.value / 2,
            w = Math.max(1, slot.value - gap.value);
        const top = y(v),
            h = padT + innerH - top,
            r = Math.min(4, w / 2, h);
        // Rounded data end, square on the baseline
        const d =
            h <= 0
                ? ''
                : `M${x},${padT + innerH} V${top + r} Q${x},${top} ${x + r},${top} H${x + w - r} Q${x + w},${top} ${x + w},${top + r} V${padT + innerH} Z`;

        return { b, i, d, x, w, cx: x + w / 2 };
    }),
);
// Spread ~6 date labels across the axis
const xLabels = computed(() => {
    const n = buckets.value.length,
        every = Math.max(
            1,
            Math.ceil(n / Math.max(2, Math.floor(chartW.value / 70))),
        );

    return bars.value.filter((bar) => bar.i % every === 0);
});

// ── Hover / tap ──────────────────────────────────────────────────────────────
const active = ref<number | null>(null);
const pick = (e: PointerEvent) => {
    const svg = e.currentTarget as SVGSVGElement,
        r = svg.getBoundingClientRect();
    const i = Math.floor((e.clientX - r.left - padL) / slot.value);
    active.value = i >= 0 && i < buckets.value.length ? i : null;
};
const activeBar = computed(() =>
    active.value === null ? null : bars.value[active.value],
);
// A chart wider than the screen opens at the most recent end
const scrollToLatest = () =>
    nextTick(() => {
        if (wrap.value) {
            wrap.value.scrollLeft = wrap.value.scrollWidth;
        }
    });
watch([grouping, () => props.points.length], scrollToLatest);
watch([grouping, metric], () => {
    active.value = null;
});

// ── Table ────────────────────────────────────────────────────────────────────
const rows = computed(() =>
    [...buckets.value].reverse().filter((b) => !hideEmpty.value || b.qty > 0),
);

// ── Full-screen behaviour ────────────────────────────────────────────────────
const onKey = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        emit('close');
    }
};
let prevOverflow = '';
// Focus moves into the view, the page behind is made inert, and focus returns on close
const closeBtn = ref<HTMLButtonElement | null>(null);
let returnFocus: HTMLElement | null = null;
const appRoot = () => document.getElementById('app');
// The chart container only exists once data has loaded, so observe it whenever it appears
watch(wrap, (el, old) => {
    if (old) {
        ro?.disconnect();
    }

    if (!el) {
        return;
    }

    width.value = el.clientWidth;
    ro = new ResizeObserver(([entry]) => {
        width.value = Math.floor(entry.contentRect.width);
    });
    ro.observe(el);
    scrollToLatest();
});
onMounted(() => {
    prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', onKey);
    returnFocus = document.activeElement as HTMLElement | null;
    appRoot()?.setAttribute('inert', '');
    nextTick(() => closeBtn.value?.focus());
});
onBeforeUnmount(() => {
    document.body.style.overflow = prevOverflow;
    window.removeEventListener('keydown', onKey);
    ro?.disconnect();
    appRoot()?.removeAttribute('inert');

    if (returnFocus?.isConnected) {
        returnFocus.focus();
    }
});
</script>

<template>
    <Teleport to="body">
        <div
            class="psd-theme fixed inset-0 z-50 overflow-y-auto bg-background"
            role="dialog"
            aria-modal="true"
            :aria-label="`${productName} sales details`"
        >
            <div
                class="sticky top-0 z-10 border-b bg-background/95 backdrop-blur"
            >
                <div
                    class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3"
                >
                    <div class="min-w-0">
                        <p
                            class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Product sales
                        </p>
                        <h2 class="truncate text-base font-bold">
                            {{ productName }}
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            {{ dayLabel(from) }} – {{ dayLabel(to) }}
                        </p>
                    </div>
                    <button
                        ref="closeBtn"
                        class="shrink-0 rounded-full p-3 text-muted-foreground transition hover:bg-muted"
                        aria-label="Close full screen"
                        @click="emit('close')"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>
            </div>

            <div class="mx-auto max-w-5xl space-y-4 px-4 py-4">
                <div
                    v-if="loading"
                    role="status"
                    class="py-16 text-center text-sm text-muted-foreground"
                >
                    Loading…
                </div>
                <div
                    v-else-if="!points.length"
                    class="py-16 text-center text-sm text-muted-foreground"
                >
                    No sales in this period.
                </div>
                <template v-else>
                    <!-- Summary -->
                    <div
                        class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6"
                    >
                        <div class="rounded-xl border bg-card p-3">
                            <p class="text-[11px] text-muted-foreground">
                                Items sold
                            </p>
                            <p class="text-lg font-bold tabular-nums">
                                {{ num(totals.qty, 0) }}
                            </p>
                        </div>
                        <div class="rounded-xl border bg-card p-3">
                            <p class="text-[11px] text-muted-foreground">
                                Sales
                            </p>
                            <p
                                class="text-lg font-bold text-green-700 tabular-nums"
                            >
                                {{ peso(totals.sales) }}
                            </p>
                        </div>
                        <div class="rounded-xl border bg-card p-3">
                            <p class="text-[11px] text-muted-foreground">
                                Average per day
                            </p>
                            <p class="text-lg font-bold tabular-nums">
                                {{ num(totals.perDay) }}
                            </p>
                            <p
                                class="text-[11px] text-muted-foreground tabular-nums"
                            >
                                {{ peso(totals.salesPerDay) }} · all
                                {{ totals.days }} days
                            </p>
                        </div>
                        <div class="rounded-xl border bg-card p-3">
                            <p class="text-[11px] text-muted-foreground">
                                Average per selling day
                            </p>
                            <p class="text-lg font-bold tabular-nums">
                                {{ num(totals.perSellingDay) }}
                            </p>
                            <p
                                class="text-[11px] text-muted-foreground tabular-nums"
                            >
                                {{ totals.sellingDays }} days with sales
                            </p>
                        </div>
                        <div class="rounded-xl border bg-card p-3">
                            <p class="text-[11px] text-muted-foreground">
                                Best day
                            </p>
                            <p class="text-lg font-bold tabular-nums">
                                {{
                                    totals.best
                                        ? num(totals.best.qty, 0) + ' sold'
                                        : '—'
                                }}
                            </p>
                            <p class="text-[11px] text-muted-foreground">
                                {{
                                    totals.best
                                        ? dayLabel(totals.best.date)
                                        : ''
                                }}
                            </p>
                        </div>
                        <div class="rounded-xl border bg-card p-3">
                            <p class="text-[11px] text-muted-foreground">
                                Average price
                            </p>
                            <p class="text-lg font-bold tabular-nums">
                                {{ peso(totals.avgPrice) }}
                            </p>
                            <p class="text-[11px] text-muted-foreground">
                                per item sold
                            </p>
                        </div>
                    </div>

                    <!-- Controls: one row above the chart -->
                    <div class="flex flex-wrap items-center gap-2">
                        <div
                            class="inline-flex rounded-lg border bg-card p-0.5"
                            role="group"
                            aria-label="Show"
                        >
                            <button
                                v-for="m in ['qty', 'sales'] as Metric[]"
                                :key="m"
                                :aria-pressed="metric === m"
                                :class="[
                                    'min-h-11 rounded-md px-3 py-2 text-xs font-semibold transition',
                                    metric === m
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-muted',
                                ]"
                                @click="metric = m"
                            >
                                {{ m === 'qty' ? 'Items sold' : 'Sales' }}
                            </button>
                        </div>
                        <div
                            class="inline-flex rounded-lg border bg-card p-0.5"
                            role="group"
                            aria-label="Group by"
                        >
                            <button
                                v-for="g in [
                                    'day',
                                    'week',
                                    'month',
                                ] as Grouping[]"
                                :key="g"
                                :aria-pressed="grouping === g"
                                :class="[
                                    'min-h-11 rounded-md px-3 py-2 text-xs font-semibold capitalize transition',
                                    grouping === g
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-muted',
                                ]"
                                @click="grouping = g"
                            >
                                {{
                                    g === 'day'
                                        ? 'Daily'
                                        : g === 'week'
                                          ? 'Weekly'
                                          : 'Monthly'
                                }}
                            </button>
                        </div>
                    </div>

                    <!-- Chart -->
                    <div class="rounded-xl border bg-card p-3">
                        <div
                            class="mb-2 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"
                        >
                            <h3 class="text-sm font-semibold">
                                {{ metric === 'qty' ? 'Items sold' : 'Sales' }}
                                per {{ unitWord }}
                            </h3>
                            <p class="text-xs text-muted-foreground">
                                <span
                                    class="mr-1 inline-block w-4 border-t-2 border-dashed border-foreground/60 align-middle"
                                ></span>
                                Average
                                <strong class="text-foreground tabular-nums">{{
                                    fmtValue(average)
                                }}</strong>
                                per {{ unitWord
                                }}<template v-if="averageSince">
                                    since {{ averageSince }}</template
                                >
                            </p>
                        </div>
                        <div class="relative">
                            <div ref="wrap" class="relative overflow-x-auto">
                                <svg
                                    :width="chartW"
                                    :height="H"
                                    class="block touch-pan-x text-primary select-none"
                                    role="img"
                                    :aria-label="`${metric === 'qty' ? 'Items sold' : 'Sales'} per ${unitWord}. Average ${fmtValue(average)}.`"
                                    @pointermove="pick"
                                    @pointerdown="pick"
                                    @pointerleave="active = null"
                                >
                                    <!-- Recessive grid and y labels -->
                                    <g v-for="t in ticks" :key="t">
                                        <line
                                            :x1="padL"
                                            :x2="chartW - padR"
                                            :y1="y(t)"
                                            :y2="y(t)"
                                            stroke="currentColor"
                                            class="text-border"
                                            :stroke-opacity="t === 0 ? 1 : 0.6"
                                            stroke-width="1"
                                        />
                                        <text
                                            :x="padL - 6"
                                            :y="y(t) + 3"
                                            text-anchor="end"
                                            class="fill-muted-foreground"
                                            font-size="12"
                                        >
                                            {{ fmtAxis(t) }}
                                        </text>
                                    </g>
                                    <!-- Bars -->
                                    <path
                                        v-for="bar in bars"
                                        :key="bar.b.key"
                                        :d="bar.d"
                                        fill="currentColor"
                                        :fill-opacity="
                                            active === null || active === bar.i
                                                ? 1
                                                : 0.45
                                        "
                                    />
                                    <!-- Average -->
                                    <line
                                        :x1="bars[firstSale]?.x ?? padL"
                                        :x2="chartW - padR"
                                        :y1="y(average)"
                                        :y2="y(average)"
                                        stroke="currentColor"
                                        class="text-foreground"
                                        stroke-opacity="0.7"
                                        stroke-width="1.5"
                                        stroke-dasharray="5,4"
                                    />
                                    <!-- X labels -->
                                    <text
                                        v-for="bar in xLabels"
                                        :key="'x' + bar.b.key"
                                        :x="bar.cx"
                                        :y="H - 8"
                                        text-anchor="middle"
                                        class="fill-muted-foreground"
                                        font-size="12"
                                    >
                                        {{ bar.b.short }}
                                    </text>
                                </svg>
                                <div
                                    v-if="activeBar"
                                    class="pointer-events-none absolute top-1 z-10 rounded-md border bg-popover px-2.5 py-1.5 text-xs whitespace-nowrap shadow-md"
                                    :style="{
                                        left:
                                            Math.min(
                                                Math.max(activeBar.cx, 80),
                                                chartW - 80,
                                            ) + 'px',
                                        transform: 'translateX(-50%)',
                                    }"
                                >
                                    <div
                                        class="font-medium text-muted-foreground"
                                    >
                                        {{ activeBar.b.label }}
                                    </div>
                                    <div class="font-bold tabular-nums">
                                        {{ num(activeBar.b.qty, 0) }} sold
                                    </div>
                                    <div
                                        class="font-semibold text-green-700 tabular-nums"
                                    >
                                        {{ peso(activeBar.b.sales) }}
                                    </div>
                                    <div
                                        class="text-muted-foreground tabular-nums"
                                    >
                                        {{
                                            value(activeBar.b) >= average
                                                ? '+'
                                                : '−'
                                        }}{{
                                            metric === 'qty'
                                                ? num(
                                                      Math.abs(
                                                          value(activeBar.b) -
                                                              average,
                                                      ),
                                                  )
                                                : peso(
                                                      Math.abs(
                                                          value(activeBar.b) -
                                                              average,
                                                      ),
                                                  )
                                        }}
                                        vs average
                                    </div>
                                </div>
                            </div>
                            <!-- Y-axis labels stay put while a wide chart scrolls -->
                            <svg
                                v-if="chartW > width"
                                :width="padL"
                                :height="H"
                                class="pointer-events-none absolute top-0 left-0"
                                aria-hidden="true"
                            >
                                <rect
                                    :width="padL"
                                    :height="H"
                                    class="fill-card"
                                />
                                <text
                                    v-for="t in ticks"
                                    :key="'yl' + t"
                                    :x="padL - 6"
                                    :y="y(t) + 3"
                                    text-anchor="end"
                                    class="fill-muted-foreground"
                                    font-size="12"
                                >
                                    {{ fmtAxis(t) }}
                                </text>
                            </svg>
                        </div>
                        <p
                            v-if="chartW > width"
                            class="mt-1 text-[11px] text-muted-foreground"
                        >
                            Swipe the chart sideways to see the whole period.
                        </p>
                    </div>

                    <!-- Table view -->
                    <div class="overflow-hidden rounded-xl border bg-card">
                        <div
                            class="flex items-center justify-between gap-2 border-b px-3 py-2"
                        >
                            <h3 class="text-sm font-semibold">
                                By {{ unitWord }}
                            </h3>
                            <label
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <input
                                    v-model="hideEmpty"
                                    type="checkbox"
                                    class="rounded"
                                />
                                Hide {{ unitWord }}s with no sales
                            </label>
                        </div>
                        <div class="max-h-[60vh] overflow-y-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="sticky top-0 bg-muted text-[11px] tracking-wide text-muted-foreground uppercase"
                                >
                                    <tr>
                                        <th class="px-3 py-2 text-left">
                                            {{
                                                grouping === 'day'
                                                    ? 'Date'
                                                    : grouping === 'week'
                                                      ? 'Week'
                                                      : 'Month'
                                            }}
                                        </th>
                                        <th class="px-3 py-2 text-right">
                                            Sold
                                        </th>
                                        <th class="px-3 py-2 text-right">
                                            Sales
                                        </th>
                                        <th class="px-3 py-2 text-right">
                                            vs avg
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="b in rows"
                                        :key="b.key"
                                        class="border-t"
                                    >
                                        <td class="px-3 py-2">{{ b.label }}</td>
                                        <td
                                            class="px-3 py-2 text-right font-semibold tabular-nums"
                                        >
                                            {{ num(b.qty, 0) }}
                                        </td>
                                        <td
                                            class="px-3 py-2 text-right tabular-nums"
                                        >
                                            {{ peso(b.sales) }}
                                        </td>
                                        <td
                                            :class="[
                                                'px-3 py-2 text-right tabular-nums',
                                                value(b) >= average
                                                    ? 'text-green-700'
                                                    : 'text-muted-foreground',
                                            ]"
                                        >
                                            {{ value(b) >= average ? '+' : '−'
                                            }}{{
                                                metric === 'qty'
                                                    ? num(
                                                          Math.abs(
                                                              value(b) -
                                                                  average,
                                                          ),
                                                      )
                                                    : pesoShort(
                                                          Math.abs(
                                                              value(b) -
                                                                  average,
                                                          ),
                                                      )
                                            }}
                                        </td>
                                    </tr>
                                    <tr v-if="!rows.length">
                                        <td
                                            colspan="4"
                                            class="px-3 py-6 text-center text-muted-foreground"
                                        >
                                            No sales in this period.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
/* Teleported to <body>, outside the Reports page's .rpt-theme wrapper, so it carries the same palette */
.psd-theme {
    --background: #fffcf6;
    --foreground: #24231e;
    --card: #fffcf6;
    --card-foreground: #24231e;
    --popover: #fffcf6;
    --popover-foreground: #24231e;
    --primary: #c3441c;
    --primary-foreground: #fff;
    --muted: #efeadf;
    --muted-foreground: #68665f;
    --border: #ded7cb;
    --input: #d4cdbf;
    --ring: #c3441c;
    color: #24231e;
    color-scheme: light;
    font-family: Arial, Helvetica, sans-serif;
}
.psd-theme :focus-visible {
    outline: 2px solid #c3441c;
    outline-offset: 2px;
}
</style>
