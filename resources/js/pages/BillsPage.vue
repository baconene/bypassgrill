<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    CalendarDays,
    Check,
    ChevronDown,
    Pencil,
    Plus,
    ReceiptText,
    RefreshCw,
    Trash2,
    Wallet,
    X,
} from 'lucide-vue-next';
import { ref, computed, onMounted } from 'vue';
import { toast } from 'vue-sonner';
import api from '@/utils/api';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Bills', href: '/bills' },
        ],
    },
});

const props = defineProps<{ accountingStartDay: number }>();

// ── Types ─────────────────────────────────────────────────────────────────────
type BillStatus =
    | 'overdue'
    | 'due_today'
    | 'upcoming'
    | 'scheduled'
    | 'inactive'
    | 'paid';
interface BillInstallment {
    id: number;
    installment_number: number;
    amount: number;
    due_date: string;
    paid_at: string | null;
    status: BillStatus;
}
interface Bill {
    id: number;
    name: string;
    description: string | null;
    amount: number;
    frequency: string;
    due_date: string;
    category: string | null;
    is_active: boolean;
    is_installment: boolean;
    installment_count: number | null;
    installments_paid: number | null;
    last_paid_at: string | null;
    status: BillStatus;
    installments: BillInstallment[];
}
interface BillForecastEntry {
    bill_id: number;
    installment_id: number | null;
    name: string;
    label: string | null;
    category: string | null;
    amount: number;
    due_date: string;
    frequency: string;
    is_installment: boolean;
    status: BillStatus;
}
interface BillForecast {
    entries: BillForecastEntry[];
    by_month: Record<string, BillForecastEntry[]>;
    total_forecast: number;
    months: number;
}
interface BillsSummary {
    total_due: number;
    overdue: number;
    upcoming: number;
    count: number;
}
type Filter = 'all' | 'attention' | 'plans' | 'inactive';

// ── State ──────────────────────────────────────────────────────────────────────
const bills = ref<Bill[]>([]);
const billForecast = ref<BillForecast | null>(null);
const periodSummary = ref<BillsSummary | null>(null);
const forecastMonths = ref(3);
const loading = ref(true);
const refreshing = ref(false);
const filter = ref<Filter>('all');
const showBillForm = ref(false);
const editingBill = ref<Bill | null>(null);
const emptyForm = () => ({
    name: '',
    description: '',
    amount: '',
    frequency: 'monthly',
    due_date: '',
    category: '',
    is_installment: false,
    installment_count: '3',
});
const billForm = ref(emptyForm());
const billSaving = ref(false);
const billPaying = ref<number | null>(null);
const billDeleting = ref<number | null>(null);
const expandedBillId = ref<number | null>(null);
const payingInstallmentId = ref<number | null>(null);

// ── Dates ─────────────────────────────────────────────────────────────────────
const ymd = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const parseYmd = (s: string) => new Date(s.slice(0, 10) + 'T00:00:00');
const fmtDate = (s: string | null) =>
    s
        ? parseYmd(s).toLocaleDateString('en-PH', {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
          })
        : '—';
const fmtShortDate = (s: string) =>
    parseYmd(s).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
const fmtDatetime = (s: string | null) => {
    if (!s) {
        return '—';
    }

    const d = new Date(s);

    return (
        d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) +
        ' · ' +
        d.toLocaleTimeString('en-PH', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
        })
    );
};
const todayLabel = new Date().toLocaleDateString('en-PH', {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
});

const accountingPeriod = computed<[Date, Date]>(() => {
    const day = props.accountingStartDay || 21;
    const now = new Date();
    const start = new Date(
        now.getFullYear(),
        now.getMonth() + (now.getDate() >= day ? 0 : -1),
        day,
    );

    return [
        start,
        new Date(start.getFullYear(), start.getMonth() + 1, day - 1),
    ];
});
const periodLabel = computed(() => {
    const [s, e] = accountingPeriod.value;

    return `${fmtShortDate(ymd(s))} – ${fmtShortDate(ymd(e))}`;
});

// ── Formatting ────────────────────────────────────────────────────────────────
const fmt = (v: number | string | null | undefined) =>
    '₱' +
    (parseFloat(String(v ?? 0)) || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
const fmtShort = (v: number) => {
    if (v >= 1_000_000) {
        return `₱${(v / 1_000_000).toFixed(1)}M`;
    }

    if (v >= 1_000) {
        return `₱${(v / 1_000).toFixed(1)}K`;
    }

    return `₱${v.toFixed(0)}`;
};
const frequencyLabel = (f: string) =>
    ({
        one_time: 'One time',
        daily: 'Daily',
        weekly: 'Weekly',
        bi_weekly: 'Every 2 weeks',
        monthly: 'Monthly',
        quarterly: 'Quarterly',
        semi_annual: 'Every 6 months',
        annual: 'Yearly',
    })[f] ?? f;
const perLabel = (b: Bill) =>
    b.is_installment
        ? 'total'
        : ({
              one_time: 'once',
              daily: '/ day',
              weekly: '/ wk',
              bi_weekly: '/ 2 wks',
              monthly: '/ mo',
              quarterly: '/ qtr',
              semi_annual: '/ 6 mo',
              annual: '/ yr',
          }[b.frequency] ?? '');
const statusLabel = (s: string) =>
    ({
        overdue: 'Overdue',
        due_today: 'Due today',
        upcoming: 'Due soon',
        scheduled: 'Scheduled',
        inactive: 'Inactive',
        paid: 'Paid',
    })[s] ?? s;
const monthLabel = (key: string) => {
    const [y, m] = key.split('-').map(Number);

    return new Date(y, m - 1, 1).toLocaleDateString('en-PH', {
        month: 'long',
        year: 'numeric',
    });
};
const sumAmounts = (rows: { amount: number }[]) =>
    rows.reduce((s, r) => s + r.amount, 0);

// ── Bill helpers ──────────────────────────────────────────────────────────────
const sortedInstallments = (bill: Bill) =>
    [...bill.installments].sort(
        (a, b) => a.installment_number - b.installment_number,
    );
const nextInstallment = (bill: Bill) =>
    sortedInstallments(bill).find((i) => !i.paid_at) ?? null;
const nextDue = (bill: Bill) =>
    bill.is_installment
        ? (nextInstallment(bill)?.due_date ?? null)
        : bill.due_date;
const nextAmount = (bill: Bill) =>
    bill.is_installment ? (nextInstallment(bill)?.amount ?? 0) : bill.amount;
const planProgress = (bill: Bill) =>
    bill.installment_count
        ? Math.round(
              ((bill.installments_paid ?? 0) / bill.installment_count) * 100,
          )
        : 0;
const paidTotal = (bill: Bill) =>
    sumAmounts(bill.installments.filter((i) => i.paid_at));
const leftTotal = (bill: Bill) =>
    sumAmounts(bill.installments.filter((i) => !i.paid_at));
const runningRemaining = (bill: Bill, upToNumber: number) => {
    let rem = bill.amount;

    for (const inst of sortedInstallments(bill)) {
        rem -= inst.amount;

        if (inst.installment_number === upToNumber) {
            break;
        }
    }

    return Math.max(0, rem);
};

// ── Computed ───────────────────────────────────────────────────────────────────
const monthlySummary = computed(() => {
    const multiplier: Record<string, number> = {
        one_time: 0,
        daily: 30,
        weekly: 4.33,
        bi_weekly: 2.17,
        monthly: 1,
        quarterly: 1 / 3,
        semi_annual: 1 / 6,
        annual: 1 / 12,
    };

    return bills.value
        .filter((b) => b.is_active && !b.is_installment)
        .reduce((sum, b) => sum + b.amount * (multiplier[b.frequency] ?? 0), 0);
});
const annualSummary = computed(
    () =>
        monthlySummary.value * 12 +
        bills.value
            .filter((b) => b.is_active && b.is_installment)
            .reduce((sum, b) => sum + leftTotal(b), 0),
);

const overdueBills = computed(() =>
    bills.value.filter((b) => b.status === 'overdue'),
);
const dueSoonBills = computed(() =>
    bills.value.filter(
        (b) => b.status === 'due_today' || b.status === 'upcoming',
    ),
);
const overdueAmount = computed(() =>
    overdueBills.value.reduce((s, b) => s + nextAmount(b), 0),
);
const dueSoonAmount = computed(() =>
    dueSoonBills.value.reduce((s, b) => s + nextAmount(b), 0),
);

const filters = computed<{ key: Filter; label: string; count: number }[]>(
    () => [
        { key: 'all', label: 'All', count: bills.value.length },
        {
            key: 'attention',
            label: 'Needs attention',
            count: overdueBills.value.length + dueSoonBills.value.length,
        },
        {
            key: 'plans',
            label: 'Payment plans',
            count: bills.value.filter((b) => b.is_installment).length,
        },
        {
            key: 'inactive',
            label: 'Inactive',
            count: bills.value.filter((b) => !b.is_active).length,
        },
    ],
);
const visibleBills = computed(() => {
    switch (filter.value) {
        case 'attention':
            return bills.value.filter((b) =>
                ['overdue', 'due_today', 'upcoming'].includes(b.status),
            );
        case 'plans':
            return bills.value.filter((b) => b.is_installment);
        case 'inactive':
            return bills.value.filter((b) => !b.is_active);
        default:
            return bills.value;
    }
});

const workBar = computed(() => {
    const overdue = overdueBills.value.length;
    const soon = dueSoonBills.value.length;

    if (overdue) {
        return {
            tone: 'alert',
            title: `${overdue} bill${overdue !== 1 ? 's' : ''} overdue.`,
            body: `${fmt(overdueAmount.value)} is past due. Settle these first to keep the books on schedule.`,
        };
    }

    if (soon) {
        return {
            tone: 'soon',
            title: `${soon} bill${soon !== 1 ? 's' : ''} due this week.`,
            body: `${fmt(dueSoonAmount.value)} falls due in the next 7 days.`,
        };
    }

    return {
        tone: 'clear',
        title: "You're all caught up.",
        body: 'Nothing is overdue and nothing falls due in the next 7 days.',
    };
});

const expandedBill = computed(
    () => bills.value.find((b) => b.id === expandedBillId.value) ?? null,
);

const burndownData = computed(() => {
    const bill = expandedBill.value;

    if (!bill?.is_installment || !bill.installments.length) {
        return null;
    }

    const sorted = sortedInstallments(bill);
    const total = bill.amount;
    const n = sorted.length;
    const plotW = 434;
    const plotH = 118;
    const toX = (v: number) => 54 + (v / n) * plotW;
    const toY = (v: number) => 14 + (1 - v / total) * plotH;

    type Pt = {
        n: number;
        rem: number;
        paid: boolean;
        svgX: number;
        svgY: number;
    };
    const pts: Pt[] = [
        { n: 0, rem: total, paid: true, svgX: toX(0), svgY: toY(total) },
    ];
    let rem = total;

    for (const inst of sorted) {
        rem = Math.max(0, rem - inst.amount);
        pts.push({
            n: inst.installment_number,
            rem,
            paid: !!inst.paid_at,
            svgX: toX(inst.installment_number),
            svgY: toY(rem),
        });
    }

    let splitIdx = 0;

    for (let i = 1; i < pts.length; i++) {
        if (pts[i].paid) {
            splitIdx = i;
        }
    }

    const toStr = (arr: Pt[]) =>
        arr.map((p) => `${p.svgX},${p.svgY}`).join(' ');
    const paidPts = pts.slice(0, splitIdx + 1);
    const unpaidPts = pts.slice(splitIdx);
    const step = n <= 6 ? 1 : n <= 12 ? 2 : n <= 24 ? 4 : Math.ceil(n / 6);

    return {
        pts,
        paidPts,
        unpaidPts,
        paidPolyStr: toStr(paidPts),
        unpaidPolyStr: toStr(unpaidPts),
        areaPoints:
            paidPts.length >= 2
                ? `54,132 ${toStr(paidPts)} ${paidPts[paidPts.length - 1].svgX},132`
                : '',
        xTicks: pts
            .filter((p) => p.n === 0 || p.n % step === 0 || p.n === n)
            .map((p) => ({
                n: p.n,
                x: p.svgX,
                label: p.n === 0 ? 'Start' : `#${p.n}`,
            })),
        yTicks: [0, 0.25, 0.5, 0.75, 1].map((f) => ({
            v: total * f,
            y: toY(total * f),
            label: fmtShort(total * f),
        })),
    };
});

// ── Actions ────────────────────────────────────────────────────────────────────
const loadBills = async () => {
    const res = await api.get('/api/v1/bills');
    bills.value = res.data.data ?? [];
};
const loadForecast = async () => {
    const res = await api.get('/api/v1/bills/forecast', {
        params: { months: forecastMonths.value },
    });
    billForecast.value = res.data;
};
const loadPeriodSummary = async () => {
    const [s, e] = accountingPeriod.value;
    const res = await api.get('/api/v1/bills/summary', {
        params: { start_date: ymd(s), end_date: ymd(e) },
    });
    periodSummary.value = res.data;
};
const reloadAll = () =>
    Promise.all([loadBills(), loadForecast(), loadPeriodSummary()]);

const refresh = async () => {
    refreshing.value = true;

    try {
        await reloadAll();
    } catch {
        toast.error('Failed to refresh bills.');
    } finally {
        refreshing.value = false;
    }
};

const setForecastMonths = async (m: number) => {
    forecastMonths.value = m;

    try {
        await loadForecast();
    } catch {
        toast.error('Failed to load the forecast.');
    }
};

const toggleBill = (bill: Bill) => {
    expandedBillId.value = expandedBillId.value === bill.id ? null : bill.id;
};

const openBillForm = (bill?: Bill) => {
    editingBill.value = bill ?? null;
    billForm.value = bill
        ? {
              name: bill.name,
              description: bill.description ?? '',
              amount: String(bill.amount),
              frequency: bill.frequency,
              due_date: bill.due_date,
              category: bill.category ?? '',
              is_installment: bill.is_installment,
              installment_count: String(bill.installment_count ?? 3),
          }
        : emptyForm();
    showBillForm.value = true;
    requestAnimationFrame(() => document.getElementById('bill-name')?.focus());
};
const closeBillForm = () => {
    showBillForm.value = false;
    editingBill.value = null;
};

const formValid = computed(
    () =>
        !!(
            billForm.value.name.trim() &&
            billForm.value.amount &&
            billForm.value.due_date
        ),
);

const saveBill = async () => {
    if (!formValid.value) {
        return;
    }

    billSaving.value = true;

    try {
        const payload: Record<string, any> = {
            name: billForm.value.name,
            description: billForm.value.description || null,
            amount: parseFloat(billForm.value.amount),
            frequency: billForm.value.frequency,
            due_date: billForm.value.due_date,
            category: billForm.value.category || null,
        };

        if (!editingBill.value) {
            payload.is_installment = billForm.value.is_installment;

            if (billForm.value.is_installment) {
                payload.installment_count = parseInt(
                    billForm.value.installment_count,
                );
            }
        }

        if (editingBill.value) {
            await api.put(`/api/v1/bills/${editingBill.value.id}`, payload);
            toast.success('Bill updated.');
        } else {
            await api.post('/api/v1/bills', payload);
            toast.success('Bill added.');
        }

        closeBillForm();
        await reloadAll();
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to save bill.');
    } finally {
        billSaving.value = false;
    }
};

const payBill = async (bill: Bill) => {
    if (
        !confirm(
            `Mark "${bill.name}" as paid?\n\nAmount: ${fmt(bill.amount)}\nThis records an expense and advances the due date.`,
        )
    ) {
        return;
    }

    billPaying.value = bill.id;

    try {
        await api.post(`/api/v1/bills/${bill.id}/pay`);
        toast.success('Bill marked as paid.');
        await reloadAll();
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to mark as paid.');
    } finally {
        billPaying.value = null;
    }
};

const payInstallment = async (bill: Bill, inst: BillInstallment) => {
    if (
        !confirm(
            `Pay installment #${inst.installment_number} of "${bill.name}"?\n\nAmount: ${fmt(inst.amount)}\nDue: ${fmtDate(inst.due_date)}`,
        )
    ) {
        return;
    }

    payingInstallmentId.value = inst.id;

    try {
        const res = await api.post(
            `/api/v1/bills/${bill.id}/installments/${inst.id}/pay`,
        );
        const idx = bills.value.findIndex((b) => b.id === bill.id);

        if (idx !== -1) {
            bills.value[idx] = res.data.data;
        }

        await Promise.all([loadForecast(), loadPeriodSummary()]);
        toast.success(`Installment #${inst.installment_number} paid.`);
    } catch (err: any) {
        toast.error(
            err.response?.data?.message ?? 'Failed to pay installment.',
        );
    } finally {
        payingInstallmentId.value = null;
    }
};

const deleteBill = async (bill: Bill) => {
    if (!confirm(`Delete "${bill.name}"? This cannot be undone.`)) {
        return;
    }

    billDeleting.value = bill.id;

    try {
        await api.delete(`/api/v1/bills/${bill.id}`);
        toast.success('Bill deleted.');

        if (expandedBillId.value === bill.id) {
            expandedBillId.value = null;
        }

        await reloadAll();
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to delete bill.');
    } finally {
        billDeleting.value = null;
    }
};

onMounted(async () => {
    try {
        await reloadAll();
    } catch {
        toast.error('Failed to load bills.');
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <Head title="Bills & Payables" />

    <div class="bills-page">
        <!-- ── Heading ─────────────────────────────────────────────────── -->
        <header class="bills-heading">
            <div>
                <p class="eyebrow">
                    <ReceiptText :size="14" aria-hidden="true" /> BYPASS GRILL /
                    PAYABLES
                </p>
                <h1>Bills &amp; <em>payables.</em></h1>
                <p class="intro">
                    Every recurring bill and payment plan, what's due, and
                    what's coming next.
                </p>
            </div>
            <div class="heading-meta">
                <span>{{ todayLabel }} · Manila</span>
                <button
                    class="refresh-button"
                    :disabled="refreshing"
                    @click="refresh"
                >
                    <RefreshCw
                        :size="14"
                        :class="{ spinning: refreshing }"
                        aria-hidden="true"
                    />
                    {{ refreshing ? 'Refreshing…' : 'Refresh' }}
                </button>
            </div>
        </header>

        <!-- ── Work bar ────────────────────────────────────────────────── -->
        <section
            class="work-bar"
            :class="`tone-${workBar.tone}`"
            aria-label="Bills status"
        >
            <div class="work-bar-copy">
                <strong
                    ><i class="tone-dot" aria-hidden="true" />{{
                        loading ? 'Loading bills…' : workBar.title
                    }}</strong
                >
                <span v-if="!loading">{{ workBar.body }}</span>
            </div>
            <div class="work-actions">
                <button
                    type="button"
                    class="primary-action"
                    @click="openBillForm()"
                >
                    <Plus :size="17" aria-hidden="true" />Add bill
                </button>
                <Link href="/financial" class="secondary-action">
                    <Wallet
                        :size="17"
                        aria-hidden="true"
                    />Financials<ArrowUpRight :size="15" aria-hidden="true" />
                </Link>
            </div>
        </section>

        <!-- ── Metrics ─────────────────────────────────────────────────── -->
        <section class="metric-grid" aria-label="Bill totals">
            <article class="metric metric-featured">
                <p>Due this accounting period</p>
                <strong>{{ fmt(periodSummary?.total_due ?? 0) }}</strong>
                <span
                    >{{ periodLabel }} · {{ periodSummary?.count ?? 0 }} bill{{
                        (periodSummary?.count ?? 0) !== 1 ? 's' : ''
                    }}</span
                >
            </article>
            <article class="metric">
                <p>Monthly exposure</p>
                <strong>{{ fmt(monthlySummary) }}</strong>
                <span>{{ fmt(annualSummary) }} a year, plans included</span>
            </article>
            <article
                class="metric"
                :class="{ 'metric-alert': overdueBills.length }"
            >
                <p>Overdue</p>
                <strong>{{ overdueBills.length }}</strong>
                <span>{{
                    overdueBills.length
                        ? `${fmt(overdueAmount)} past due`
                        : 'Nothing past due'
                }}</span>
            </article>
            <article class="metric">
                <p>Due within 7 days</p>
                <strong>{{ dueSoonBills.length }}</strong>
                <span>{{
                    dueSoonBills.length
                        ? fmt(dueSoonAmount)
                        : 'Clear week ahead'
                }}</span>
            </article>
        </section>

        <div class="bills-columns">
            <!-- ── Payables ────────────────────────────────────────────── -->
            <section class="panel" aria-labelledby="payables-title">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">WHAT YOU OWE</p>
                        <h2 id="payables-title">
                            Payables<span class="count-badge">{{
                                bills.length
                            }}</span>
                        </h2>
                    </div>
                    <button
                        v-if="!showBillForm"
                        type="button"
                        class="ghost-button"
                        @click="openBillForm()"
                    >
                        <Plus :size="14" aria-hidden="true" />New
                    </button>
                </div>

                <!-- Add / edit form -->
                <form
                    v-if="showBillForm"
                    class="bill-form"
                    @submit.prevent="saveBill"
                >
                    <div class="bill-form-head">
                        <div>
                            <p class="eyebrow">
                                {{ editingBill ? 'EDIT BILL' : 'NEW PAYABLE' }}
                            </p>
                            <h3>
                                {{
                                    editingBill
                                        ? editingBill.name
                                        : billForm.is_installment
                                          ? 'Set up a payment plan'
                                          : 'Add a recurring bill'
                                }}
                            </h3>
                        </div>
                        <button
                            type="button"
                            class="icon-button"
                            aria-label="Close form"
                            @click="closeBillForm"
                        >
                            <X :size="16" aria-hidden="true" />
                        </button>
                    </div>

                    <div
                        v-if="!editingBill"
                        class="segmented"
                        role="group"
                        aria-label="Bill type"
                    >
                        <button
                            type="button"
                            :aria-pressed="!billForm.is_installment"
                            @click="billForm.is_installment = false"
                        >
                            Recurring bill
                        </button>
                        <button
                            type="button"
                            :aria-pressed="billForm.is_installment"
                            @click="billForm.is_installment = true"
                        >
                            Payment plan
                        </button>
                    </div>

                    <div class="field-grid">
                        <label class="field field-wide">
                            <span>Name *</span>
                            <input
                                id="bill-name"
                                v-model="billForm.name"
                                type="text"
                                placeholder="e.g. Electricity"
                                required
                            />
                        </label>
                        <label class="field">
                            <span>{{
                                billForm.is_installment
                                    ? 'Total amount (₱) *'
                                    : 'Amount (₱) *'
                            }}</span>
                            <input
                                v-model="billForm.amount"
                                type="number"
                                min="0.01"
                                step="0.01"
                                placeholder="0.00"
                                inputmode="decimal"
                                required
                            />
                        </label>
                        <label class="field">
                            <span>{{
                                billForm.is_installment
                                    ? 'Pay every *'
                                    : 'Frequency *'
                            }}</span>
                            <select v-model="billForm.frequency">
                                <option
                                    v-if="!billForm.is_installment"
                                    value="one_time"
                                >
                                    One time
                                </option>
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="bi_weekly">Every 2 weeks</option>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="semi_annual">
                                    Every 6 months
                                </option>
                                <option value="annual">Yearly</option>
                            </select>
                        </label>
                        <label
                            v-if="billForm.is_installment && !editingBill"
                            class="field"
                        >
                            <span>Installments *</span>
                            <input
                                v-model="billForm.installment_count"
                                type="number"
                                min="2"
                                max="360"
                                placeholder="e.g. 6"
                            />
                            <small
                                v-if="
                                    billForm.amount &&
                                    billForm.installment_count
                                "
                            >
                                ≈
                                {{
                                    fmt(
                                        parseFloat(billForm.amount || '0') /
                                            (parseInt(
                                                billForm.installment_count,
                                            ) || 1),
                                    )
                                }}
                                each
                            </small>
                        </label>
                        <label class="field">
                            <span>{{
                                billForm.is_installment
                                    ? 'First payment *'
                                    : 'Next due date *'
                            }}</span>
                            <input
                                v-model="billForm.due_date"
                                type="date"
                                required
                            />
                        </label>
                        <label class="field">
                            <span>Category</span>
                            <input
                                v-model="billForm.category"
                                type="text"
                                placeholder="e.g. Utilities"
                                list="bill-categories"
                            />
                            <datalist id="bill-categories">
                                <option value="Subscription" />
                                <option value="Utilities" />
                                <option value="Rent" />
                                <option value="Platform Fee" />
                                <option value="Loan" />
                                <option value="Insurance" />
                                <option value="Maintenance" />
                                <option value="Tax" />
                            </datalist>
                        </label>
                        <label class="field field-wide">
                            <span>Notes</span>
                            <input
                                v-model="billForm.description"
                                type="text"
                                placeholder="Optional details"
                            />
                        </label>
                    </div>

                    <div class="form-actions">
                        <button
                            type="button"
                            class="ghost-button"
                            @click="closeBillForm"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="solid-button"
                            :disabled="billSaving || !formValid"
                        >
                            {{
                                billSaving
                                    ? 'Saving…'
                                    : editingBill
                                      ? 'Save changes'
                                      : billForm.is_installment
                                        ? 'Create plan'
                                        : 'Add bill'
                            }}
                        </button>
                    </div>
                </form>

                <!-- Filters -->
                <div
                    v-if="bills.length"
                    class="filter-row"
                    role="group"
                    aria-label="Filter bills"
                >
                    <button
                        v-for="f in filters"
                        :key="f.key"
                        type="button"
                        :aria-pressed="filter === f.key"
                        @click="filter = f.key"
                    >
                        {{ f.label }}<span>{{ f.count }}</span>
                    </button>
                </div>

                <p v-if="loading" class="muted-state" role="status">
                    Loading bills…
                </p>

                <div v-else-if="!bills.length" class="empty-state">
                    <ReceiptText :size="28" aria-hidden="true" />
                    <h3>No bills yet.</h3>
                    <p>
                        Add rent, utilities or a payment plan to see what's due
                        and when.
                    </p>
                    <button
                        type="button"
                        class="solid-button"
                        @click="openBillForm()"
                    >
                        <Plus :size="14" aria-hidden="true" />Add your first
                        bill
                    </button>
                </div>

                <p v-else-if="!visibleBills.length" class="muted-state">
                    Nothing here. Try another filter.
                </p>

                <ul v-else class="bill-list">
                    <li
                        v-for="bill in visibleBills"
                        :key="bill.id"
                        class="bill"
                        :class="{
                            'is-open': expandedBillId === bill.id,
                            'is-inactive': !bill.is_active,
                        }"
                    >
                        <button
                            type="button"
                            class="bill-row"
                            :aria-expanded="expandedBillId === bill.id"
                            :aria-controls="`bill-detail-${bill.id}`"
                            @click="toggleBill(bill)"
                        >
                            <span
                                class="bill-mark"
                                :class="`mark-${bill.status}`"
                                aria-hidden="true"
                            />
                            <span class="bill-main">
                                <strong>{{ bill.name }}</strong>
                                <small>
                                    {{
                                        bill.category
                                            ? `${bill.category} · `
                                            : ''
                                    }}{{ frequencyLabel(bill.frequency) }}
                                    <template v-if="nextDue(bill)">
                                        · Due
                                        {{
                                            fmtShortDate(nextDue(bill)!)
                                        }}</template
                                    >
                                </small>
                                <span
                                    v-if="bill.is_installment"
                                    class="plan-progress"
                                >
                                    <span class="plan-track" aria-hidden="true"
                                        ><span
                                            :style="{
                                                width: planProgress(bill) + '%',
                                            }"
                                    /></span>
                                    {{ bill.installments_paid }}/{{
                                        bill.installment_count
                                    }}
                                    paid
                                </span>
                            </span>
                            <span class="bill-amount">
                                {{ fmt(bill.amount)
                                }}<small>{{ perLabel(bill) }}</small>
                            </span>
                            <span class="chip" :class="`chip-${bill.status}`">{{
                                statusLabel(bill.status)
                            }}</span>
                            <ChevronDown
                                :size="16"
                                class="bill-chevron"
                                aria-hidden="true"
                            />
                        </button>

                        <div
                            v-if="expandedBillId === bill.id"
                            :id="`bill-detail-${bill.id}`"
                            class="bill-detail"
                        >
                            <p v-if="bill.description" class="bill-notes">
                                {{ bill.description }}
                            </p>

                            <dl class="fact-grid">
                                <div>
                                    <dt>Next due</dt>
                                    <dd>{{ fmtDate(nextDue(bill)) }}</dd>
                                </div>
                                <div>
                                    <dt>
                                        {{
                                            bill.is_installment
                                                ? 'Next payment'
                                                : 'Amount'
                                        }}
                                    </dt>
                                    <dd>{{ fmt(nextAmount(bill)) }}</dd>
                                </div>
                                <div>
                                    <dt>Frequency</dt>
                                    <dd>
                                        {{ frequencyLabel(bill.frequency) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt>Last paid</dt>
                                    <dd>
                                        {{ fmtDatetime(bill.last_paid_at) }}
                                    </dd>
                                </div>
                            </dl>

                            <template
                                v-if="
                                    bill.is_installment &&
                                    bill.installments.length
                                "
                            >
                                <div v-if="burndownData" class="burndown">
                                    <div class="sub-heading">
                                        <p class="eyebrow">PAYMENT BURNDOWN</p>
                                        <div class="legend">
                                            <span
                                                ><i class="legend-paid" />Paid
                                                {{ fmt(paidTotal(bill)) }}</span
                                            >
                                            <span
                                                ><i class="legend-left" />Left
                                                {{ fmt(leftTotal(bill)) }}</span
                                            >
                                        </div>
                                    </div>
                                    <svg
                                        viewBox="0 0 500 160"
                                        role="img"
                                        :aria-label="`Remaining balance after each installment of ${bill.name}`"
                                    >
                                        <defs>
                                            <linearGradient
                                                id="bdPaidGrad"
                                                x1="0"
                                                y1="0"
                                                x2="0"
                                                y2="1"
                                            >
                                                <stop
                                                    offset="0%"
                                                    stop-color="#376229"
                                                    stop-opacity="0.22"
                                                />
                                                <stop
                                                    offset="100%"
                                                    stop-color="#376229"
                                                    stop-opacity="0.02"
                                                />
                                            </linearGradient>
                                        </defs>
                                        <g
                                            v-for="tick in burndownData.yTicks"
                                            :key="tick.v"
                                        >
                                            <line
                                                x1="54"
                                                :y1="tick.y"
                                                x2="488"
                                                :y2="tick.y"
                                                stroke="#24231e"
                                                stroke-opacity="0.07"
                                            />
                                            <text
                                                x="50"
                                                :y="tick.y + 3.5"
                                                text-anchor="end"
                                                fill="#777268"
                                                font-size="9"
                                            >
                                                {{ tick.label }}
                                            </text>
                                        </g>
                                        <text
                                            v-for="tick in burndownData.xTicks"
                                            :key="tick.n"
                                            :x="tick.x"
                                            y="155"
                                            text-anchor="middle"
                                            fill="#777268"
                                            font-size="9"
                                        >
                                            {{ tick.label }}
                                        </text>
                                        <line
                                            x1="54"
                                            y1="132"
                                            x2="488"
                                            y2="132"
                                            stroke="#24231e"
                                            stroke-opacity="0.18"
                                        />
                                        <polygon
                                            v-if="burndownData.areaPoints"
                                            :points="burndownData.areaPoints"
                                            fill="url(#bdPaidGrad)"
                                        />
                                        <polyline
                                            v-if="
                                                burndownData.unpaidPts.length >=
                                                2
                                            "
                                            :points="burndownData.unpaidPolyStr"
                                            fill="none"
                                            stroke="#b7ad9c"
                                            stroke-width="2"
                                            stroke-dasharray="5,3"
                                            stroke-linecap="round"
                                        />
                                        <polyline
                                            v-if="
                                                burndownData.paidPts.length >= 2
                                            "
                                            :points="burndownData.paidPolyStr"
                                            fill="none"
                                            stroke="#376229"
                                            stroke-width="2.5"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        />
                                        <circle
                                            v-for="pt in burndownData.pts"
                                            :key="pt.n"
                                            :cx="pt.svgX"
                                            :cy="pt.svgY"
                                            r="3"
                                            :fill="
                                                pt.paid ? '#376229' : '#fffcf6'
                                            "
                                            :stroke="
                                                pt.paid ? '#376229' : '#b7ad9c'
                                            "
                                            stroke-width="1.5"
                                        />
                                    </svg>
                                </div>

                                <div class="sub-heading">
                                    <p class="eyebrow">INSTALLMENT SCHEDULE</p>
                                </div>
                                <div
                                    class="table-scroll"
                                    tabindex="0"
                                    role="region"
                                    :aria-label="`Installments for ${bill.name}`"
                                >
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th scope="col">#</th>
                                                <th scope="col">Due</th>
                                                <th scope="col">Status</th>
                                                <th scope="col" class="num">
                                                    Amount
                                                </th>
                                                <th scope="col" class="num">
                                                    Balance after
                                                </th>
                                                <th scope="col" class="num">
                                                    <span class="sr-only"
                                                        >Action</span
                                                    >
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr
                                                v-for="inst in sortedInstallments(
                                                    bill,
                                                )"
                                                :key="inst.id"
                                                :class="{
                                                    'is-paid': inst.paid_at,
                                                }"
                                            >
                                                <td class="muted">
                                                    #{{
                                                        inst.installment_number
                                                    }}
                                                </td>
                                                <td>
                                                    {{ fmtDate(inst.due_date) }}
                                                    <small v-if="inst.paid_at"
                                                        >Paid
                                                        {{
                                                            fmtDatetime(
                                                                inst.paid_at,
                                                            )
                                                        }}</small
                                                    >
                                                </td>
                                                <td>
                                                    <span
                                                        class="chip"
                                                        :class="`chip-${inst.status}`"
                                                        >{{
                                                            statusLabel(
                                                                inst.status,
                                                            )
                                                        }}</span
                                                    >
                                                </td>
                                                <td class="num strong">
                                                    {{ fmt(inst.amount) }}
                                                </td>
                                                <td
                                                    class="num"
                                                    :class="
                                                        runningRemaining(
                                                            bill,
                                                            inst.installment_number,
                                                        ) === 0
                                                            ? 'is-good'
                                                            : 'muted'
                                                    "
                                                >
                                                    {{
                                                        fmt(
                                                            runningRemaining(
                                                                bill,
                                                                inst.installment_number,
                                                            ),
                                                        )
                                                    }}
                                                </td>
                                                <td class="num">
                                                    <Check
                                                        v-if="inst.paid_at"
                                                        :size="15"
                                                        class="is-good"
                                                        aria-label="Paid"
                                                    />
                                                    <button
                                                        v-else-if="
                                                            bill.is_active
                                                        "
                                                        type="button"
                                                        class="pay-button small"
                                                        :disabled="
                                                            payingInstallmentId ===
                                                            inst.id
                                                        "
                                                        @click="
                                                            payInstallment(
                                                                bill,
                                                                inst,
                                                            )
                                                        "
                                                    >
                                                        {{
                                                            payingInstallmentId ===
                                                            inst.id
                                                                ? 'Paying…'
                                                                : 'Pay'
                                                        }}
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </template>

                            <div class="detail-actions">
                                <button
                                    v-if="
                                        !bill.is_installment && bill.is_active
                                    "
                                    type="button"
                                    class="pay-button"
                                    :disabled="billPaying === bill.id"
                                    @click="payBill(bill)"
                                >
                                    <Check :size="14" aria-hidden="true" />{{
                                        billPaying === bill.id
                                            ? 'Recording…'
                                            : `Mark paid · ${fmt(bill.amount)}`
                                    }}
                                </button>
                                <button
                                    type="button"
                                    class="ghost-button"
                                    @click="openBillForm(bill)"
                                >
                                    <Pencil :size="13" aria-hidden="true" />Edit
                                </button>
                                <button
                                    type="button"
                                    class="ghost-button danger"
                                    :disabled="billDeleting === bill.id"
                                    @click="deleteBill(bill)"
                                >
                                    <Trash2 :size="13" aria-hidden="true" />{{
                                        billDeleting === bill.id
                                            ? 'Deleting…'
                                            : 'Delete'
                                    }}
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- ── Forecast ────────────────────────────────────────────── -->
            <section class="panel forecast" aria-labelledby="forecast-title">
                <div class="panel-heading stack">
                    <div>
                        <p class="eyebrow">WHAT'S COMING</p>
                        <h2 id="forecast-title">Payment forecast</h2>
                    </div>
                    <div
                        class="segmented compact"
                        role="group"
                        aria-label="Forecast range"
                    >
                        <button
                            v-for="m in [1, 3, 6, 12]"
                            :key="m"
                            type="button"
                            :aria-pressed="forecastMonths === m"
                            @click="setForecastMonths(m)"
                        >
                            {{ m }}M
                        </button>
                    </div>
                </div>

                <template v-if="billForecast">
                    <p v-if="!billForecast.entries.length" class="muted-state">
                        Nothing scheduled in the next
                        {{ forecastMonths }} month{{
                            forecastMonths !== 1 ? 's' : ''
                        }}.
                    </p>

                    <div
                        v-for="(entries, month) in billForecast.by_month"
                        :key="month"
                        class="forecast-month"
                    >
                        <div class="forecast-month-head">
                            <strong>{{ monthLabel(month as string) }}</strong>
                            <span
                                >{{ fmt(sumAmounts(entries)) }} ·
                                {{ entries.length }}</span
                            >
                        </div>
                        <ul>
                            <li
                                v-for="entry in entries"
                                :key="`${entry.bill_id}-${entry.installment_id}-${entry.due_date}`"
                            >
                                <span
                                    class="date-block"
                                    :class="`date-${entry.status}`"
                                >
                                    <b>{{
                                        parseYmd(entry.due_date).getDate()
                                    }}</b>
                                    <small>{{
                                        parseYmd(
                                            entry.due_date,
                                        ).toLocaleDateString('en-PH', {
                                            weekday: 'short',
                                        })
                                    }}</small>
                                </span>
                                <span class="forecast-main">
                                    <strong
                                        >{{ entry.name
                                        }}<template v-if="entry.label">
                                            <em>{{ entry.label }}</em></template
                                        ></strong
                                    >
                                    <small
                                        >{{
                                            entry.is_installment
                                                ? 'Installment'
                                                : 'Recurring'
                                        }}{{
                                            entry.category
                                                ? ` · ${entry.category}`
                                                : ''
                                        }}<template
                                            v-if="
                                                entry.status === 'overdue' ||
                                                entry.status === 'due_today'
                                            "
                                        >
                                            ·
                                            <b
                                                :class="`text-${entry.status}`"
                                                >{{
                                                    statusLabel(entry.status)
                                                }}</b
                                            ></template
                                        ></small
                                    >
                                </span>
                                <span class="forecast-amount">{{
                                    fmt(entry.amount)
                                }}</span>
                            </li>
                        </ul>
                    </div>

                    <div
                        v-if="billForecast.entries.length"
                        class="forecast-total"
                    >
                        <span
                            >Next {{ forecastMonths }} month{{
                                forecastMonths !== 1 ? 's' : ''
                            }}</span
                        >
                        <strong>{{ fmt(billForecast.total_forecast) }}</strong>
                    </div>
                </template>
                <p v-else class="muted-state" role="status">
                    {{ loading ? 'Loading forecast…' : 'No forecast loaded.' }}
                </p>

                <p class="forecast-note">
                    <CalendarDays :size="12" aria-hidden="true" />Accounting
                    period: {{ periodLabel }}.
                    <Link href="/settings/accounting">Change</Link>
                </p>
            </section>
        </div>
    </div>
</template>

<style scoped>
.bills-page {
    background: #f6f2e9;
    color: #24231e;
    min-height: 100%;
    padding: 34px clamp(18px, 3vw, 44px);
    font-family: Arial, Helvetica, sans-serif;
    color-scheme: light;
}
button,
a {
    font: inherit;
    color: inherit;
    cursor: pointer;
}
button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}
:focus-visible {
    outline: 2px solid #c3441c;
    outline-offset: 2px;
}
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* Heading */
.bills-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    margin-bottom: 26px;
}
.eyebrow {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1.6px;
    color: #ad3b19;
    text-transform: uppercase;
}
.bills-heading h1 {
    font-size: clamp(32px, 3.5vw, 48px);
    font-weight: 850;
    letter-spacing: -1.8px;
    line-height: 1.1;
    margin: 12px 0;
}
.bills-heading h1 em {
    font-family: Georgia, serif;
    font-weight: 400;
    color: #ad3b19;
}
.intro {
    font-size: 13px;
    line-height: 1.6;
    color: #68665f;
}
.heading-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 10px;
    font-size: 11px;
    color: #68665f;
    white-space: nowrap;
}
.refresh-button {
    display: flex;
    align-items: center;
    gap: 7px;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    padding: 9px 12px;
    background: #fffcf6;
    font-size: 11px;
    font-weight: 700;
}
.refresh-button:hover {
    background: #fff;
}
.spinning {
    animation: spin 0.9s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

/* Work bar */
.work-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    background: #24231e;
    color: #f6f2e9;
    border-radius: 6px;
    padding: 23px 25px;
    margin-bottom: 22px;
}
.work-bar-copy strong {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 16px;
}
.work-bar-copy span {
    display: block;
    margin-top: 5px;
    font-size: 11px;
    line-height: 1.7;
    color: #c3bfb3;
}
.tone-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #7bc87c;
    flex-shrink: 0;
}
.tone-alert .tone-dot {
    background: #f07070;
    box-shadow: 0 0 0 4px #f0707026;
}
.tone-soon .tone-dot {
    background: #f5c842;
    box-shadow: 0 0 0 4px #f5c84226;
}
.work-actions {
    display: flex;
    gap: 10px;
    flex-shrink: 0;
    flex-wrap: wrap;
}
.primary-action,
.secondary-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 800;
    padding: 13px 15px;
    border: 0;
}
.primary-action {
    background: #c3441c;
    color: #fff;
}
.primary-action:hover {
    background: #a73513;
}
.secondary-action {
    border: 1px solid #ffffff40;
    color: #f6f2e9;
}
.secondary-action:hover {
    background: #ffffff12;
}

/* Metrics */
.metric-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 25px;
}
.metric {
    background: #fffcf6;
    border: 1px solid #ded7cb;
    padding: 21px;
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
    font-size: clamp(22px, 2.4vw, 32px);
    letter-spacing: -1px;
    line-height: 1.3;
    margin: 12px 0 7px;
    overflow-wrap: anywhere;
    font-variant-numeric: tabular-nums;
}
.metric-featured strong {
    color: #ad3b19;
}
.metric-alert {
    background: #fbe9e4;
    border-color: #edc4b7;
}
.metric-alert strong {
    color: #a03015;
}
.metric > span {
    display: block;
    font-size: 10px;
    line-height: 1.6;
    color: #777268;
}

/* Layout */
.bills-columns {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 340px;
    gap: 22px;
    align-items: start;
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
    align-items: center;
    gap: 15px;
    margin-bottom: 18px;
}
.panel-heading .eyebrow {
    margin-bottom: 7px;
    font-size: 8px;
}
.panel h2 {
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -0.5px;
    line-height: 1.3;
}
.count-badge {
    display: inline-block;
    margin-left: 8px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0;
    background: #f2e5d8;
    color: #ad3b19;
    padding: 4px 8px;
    border-radius: 20px;
    vertical-align: middle;
}

/* Buttons */
.ghost-button,
.solid-button,
.pay-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 800;
    padding: 9px 13px;
    white-space: nowrap;
}
.ghost-button {
    border: 1px solid #d4cdbf;
    background: #fffcf6;
}
.ghost-button:hover:not(:disabled) {
    background: #f6f2e9;
}
.ghost-button.danger {
    color: #a03015;
    border-color: #edc4b7;
}
.ghost-button.danger:hover:not(:disabled) {
    background: #fbe9e4;
}
.solid-button {
    border: 0;
    background: #24231e;
    color: #f6f2e9;
}
.solid-button:hover:not(:disabled) {
    background: #3a3830;
}
.pay-button {
    border: 0;
    background: #376229;
    color: #fff;
}
.pay-button:hover:not(:disabled) {
    background: #2b4e20;
}
.pay-button.small {
    padding: 6px 11px;
    font-size: 10px;
}
.icon-button {
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    border-radius: 4px;
    border: 1px solid #d4cdbf;
    background: #fffcf6;
}

/* Segmented controls */
.segmented {
    display: flex;
    gap: 3px;
    padding: 3px;
    background: #ece5da;
    border-radius: 5px;
}
.segmented button {
    flex: 1;
    border: 0;
    background: transparent;
    border-radius: 3px;
    padding: 9px 12px;
    font-size: 11px;
    font-weight: 800;
    color: #68665f;
}
.segmented button[aria-pressed='true'] {
    background: #fffcf6;
    color: #24231e;
    box-shadow: 0 1px 2px #24231e1f;
}
.segmented.compact button {
    padding: 6px 10px;
    font-size: 10px;
}

/* Form */
.bill-form {
    border: 1px solid #e4c4ab;
    background: #fbf6ee;
    border-radius: 5px;
    padding: 18px;
    margin-bottom: 18px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.bill-form-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}
.bill-form-head h3 {
    margin-top: 6px;
    font-size: 15px;
    font-weight: 800;
    letter-spacing: -0.3px;
}
.field-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}
.field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
.field-wide {
    grid-column: span 2;
}
.field > span {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.4px;
    color: #68665f;
}
.field input,
.field select {
    width: 100%;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    background: #fffcf6;
    padding: 10px 11px;
    font-size: 13px;
    color: #24231e;
}
.field input:focus,
.field select:focus {
    outline: 2px solid #c3441c55;
    border-color: #c3441c;
}
.field small {
    font-size: 10px;
    color: #777268;
}
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

/* Filters */
.filter-row {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.filter-row button {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: 1px solid #ded7cb;
    background: transparent;
    border-radius: 20px;
    padding: 6px 11px;
    font-size: 11px;
    font-weight: 700;
    color: #68665f;
}
.filter-row button span {
    font-size: 10px;
    background: #ece5da;
    border-radius: 10px;
    padding: 1px 6px;
    font-variant-numeric: tabular-nums;
}
.filter-row button[aria-pressed='true'] {
    background: #24231e;
    border-color: #24231e;
    color: #f6f2e9;
}
.filter-row button[aria-pressed='true'] span {
    background: #ffffff26;
}

/* Bill list */
.bill-list {
    list-style: none;
    margin: 0;
    padding: 0;
    border-top: 1px solid #ece5da;
}
.bill {
    border-bottom: 1px solid #ece5da;
}
.bill.is-inactive .bill-row {
    opacity: 0.55;
}
.bill-row {
    display: grid;
    grid-template-columns: 4px minmax(0, 1fr) auto auto 18px;
    align-items: center;
    gap: 14px;
    width: 100%;
    padding: 15px 4px 15px 0;
    border: 0;
    background: transparent;
    text-align: left;
}
.bill-row:hover {
    background: #f9f4ec;
}
.bill-mark {
    align-self: stretch;
    border-radius: 2px;
    background: #d4cdbf;
}
.mark-overdue {
    background: #c3441c;
}
.mark-due_today {
    background: #ad3b19;
}
.mark-upcoming {
    background: #d6a531;
}
.mark-scheduled {
    background: #b7ad9c;
}
.bill-main {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}
.bill-main strong {
    font-size: 14px;
    font-weight: 800;
    letter-spacing: -0.2px;
    overflow-wrap: anywhere;
}
.bill-main small {
    font-size: 11px;
    color: #777268;
}
.plan-progress {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 10px;
    font-weight: 700;
    color: #ad3b19;
}
.plan-track {
    width: 90px;
    height: 4px;
    border-radius: 2px;
    background: #ece5da;
    overflow: hidden;
}
.plan-track span {
    display: block;
    height: 100%;
    background: #376229;
}
.bill-amount {
    font-size: 15px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    text-align: right;
    white-space: nowrap;
}
.bill-amount small {
    margin-left: 4px;
    font-size: 10px;
    font-weight: 700;
    color: #777268;
}
.bill-chevron {
    color: #777268;
    transition: transform 0.2s;
}
.bill.is-open .bill-chevron {
    transform: rotate(180deg);
}

.chip {
    display: inline-block;
    border-radius: 3px;
    padding: 4px 8px;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    white-space: nowrap;
    background: #ece5da;
    color: #68665f;
}
.chip-overdue {
    background: #fbe9e4;
    color: #a03015;
}
.chip-due_today {
    background: #f2e5d8;
    color: #ad3b19;
}
.chip-upcoming {
    background: #f7ecd2;
    color: #7b5815;
}
.chip-scheduled {
    background: #ece5da;
    color: #4f4c45;
}
.chip-paid {
    background: #e4edde;
    color: #376229;
}
.chip-inactive {
    background: #ece5da;
    color: #777268;
}

/* Bill detail */
.bill-detail {
    padding: 4px 0 20px 18px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.bill-notes {
    font-size: 12px;
    line-height: 1.6;
    color: #68665f;
}
.fact-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1px;
    background: #ded7cb;
    border: 1px solid #ded7cb;
    border-radius: 5px;
    overflow: hidden;
}
.fact-grid div {
    background: #fbf6ee;
    padding: 11px 13px;
    min-width: 0;
}
.fact-grid dt {
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #777268;
}
.fact-grid dd {
    margin-top: 5px;
    font-size: 13px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}
.sub-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.sub-heading .eyebrow {
    font-size: 8px;
}
.legend {
    display: flex;
    gap: 14px;
    font-size: 10px;
    color: #68665f;
    font-weight: 700;
}
.legend span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.legend i {
    display: inline-block;
    width: 14px;
    height: 3px;
    border-radius: 2px;
}
.legend-paid {
    background: #376229;
}
.legend-left {
    background: repeating-linear-gradient(
        90deg,
        #b7ad9c 0 4px,
        transparent 4px 6px
    );
}
.burndown {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.burndown svg {
    width: 100%;
    height: 170px;
    background: #fbf6ee;
    border: 1px solid #ece5da;
    border-radius: 5px;
    padding: 6px;
}
.table-scroll {
    overflow-x: auto;
    border: 1px solid #ece5da;
    border-radius: 5px;
}
.data-table {
    width: 100%;
    min-width: 520px;
    border-collapse: collapse;
    font-size: 12px;
}
.data-table th {
    text-align: left;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #777268;
    background: #f6f2e9;
    padding: 9px 12px;
}
.data-table td {
    padding: 10px 12px;
    border-top: 1px solid #ece5da;
    font-variant-numeric: tabular-nums;
    vertical-align: middle;
}
.data-table td small {
    display: block;
    font-size: 10px;
    color: #777268;
    margin-top: 2px;
}
.data-table tr.is-paid td {
    background: #f3f6ee;
}
.data-table .num {
    text-align: right;
}
.muted {
    color: #777268;
}
.strong {
    font-weight: 800;
}
.is-good {
    color: #376229;
    font-weight: 700;
}
.detail-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

/* States */
.muted-state {
    padding: 22px 0;
    font-size: 12px;
    color: #777268;
    text-align: center;
}
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 34px 12px;
    text-align: center;
    color: #68665f;
}
.empty-state h3 {
    font-size: 15px;
    font-weight: 800;
    color: #24231e;
}
.empty-state p {
    font-size: 12px;
    max-width: 320px;
    line-height: 1.6;
    margin-bottom: 6px;
}

/* Forecast */
.panel-heading.stack {
    flex-wrap: wrap;
}
.forecast-month {
    margin-bottom: 14px;
}
.forecast-month-head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid #24231e;
}
.forecast-month-head strong {
    font-size: 12px;
    font-weight: 800;
}
.forecast-month-head span {
    font-size: 11px;
    font-weight: 700;
    color: #68665f;
    font-variant-numeric: tabular-nums;
}
.forecast-month ul {
    list-style: none;
    margin: 0;
    padding: 0;
}
.forecast-month li {
    display: grid;
    grid-template-columns: 38px minmax(0, 1fr) auto;
    align-items: center;
    gap: 11px;
    padding: 10px 0;
    border-bottom: 1px solid #ece5da;
}
.date-block {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 5px 0;
    border-radius: 4px;
    background: #f6f2e9;
    line-height: 1.1;
}
.date-block b {
    font-size: 15px;
    font-weight: 850;
    font-variant-numeric: tabular-nums;
}
.date-block small {
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    color: #777268;
}
.date-overdue {
    background: #fbe9e4;
    color: #a03015;
}
.date-due_today {
    background: #f2e5d8;
    color: #ad3b19;
}
.forecast-main {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
}
.forecast-main strong {
    font-size: 12px;
    font-weight: 800;
    overflow-wrap: anywhere;
}
.forecast-main strong em {
    margin-left: 5px;
    font-style: normal;
    font-weight: 700;
    color: #777268;
}
.forecast-main small {
    font-size: 10px;
    color: #777268;
}
.text-overdue {
    color: #a03015;
}
.text-due_today {
    color: #ad3b19;
}
.forecast-amount {
    font-size: 12px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
.forecast-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-top: 6px;
    padding: 14px 16px;
    border-radius: 5px;
    background: #24231e;
    color: #f6f2e9;
}
.forecast-total span {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: #c3bfb3;
}
.forecast-total strong {
    font-size: 20px;
    font-weight: 850;
    letter-spacing: -0.6px;
    font-variant-numeric: tabular-nums;
}
.forecast-note {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 14px;
    font-size: 10px;
    color: #777268;
}
.forecast-note a {
    color: #ad3b19;
    font-weight: 800;
    text-decoration: underline;
    text-underline-offset: 2px;
}

/* Responsive */
@media (max-width: 1100px) {
    .bills-columns {
        grid-template-columns: minmax(0, 1fr);
    }
}
@media (max-width: 960px) {
    .metric-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .field-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .fact-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 640px) {
    .bills-page {
        padding: 22px 16px;
    }
    .bills-heading {
        flex-direction: column;
        gap: 14px;
    }
    .heading-meta {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
        width: 100%;
    }
    .work-bar {
        flex-direction: column;
        align-items: stretch;
        padding: 20px;
    }
    .work-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }
    .metric-grid {
        gap: 10px;
    }
    .metric {
        padding: 16px;
    }
    .panel {
        padding: 16px;
    }
    .field-grid {
        grid-template-columns: minmax(0, 1fr);
    }
    .field-wide {
        grid-column: auto;
    }
    .form-actions > * {
        flex: 1;
    }
    .bill-row {
        grid-template-columns: 4px minmax(0, 1fr) 18px;
        grid-template-areas:
            'mark main chev'
            'mark meta chev';
        row-gap: 8px;
        column-gap: 12px;
    }
    .bill-mark {
        grid-area: mark;
    }
    .bill-main {
        grid-area: main;
    }
    .bill-chevron {
        grid-area: chev;
    }
    .bill-amount {
        grid-area: meta;
        justify-self: start;
    }
    .chip {
        justify-self: start;
    }
    .bill-row > .chip {
        grid-area: meta;
        justify-self: end;
    }
    .bill-detail {
        padding-left: 0;
    }
    .detail-actions > * {
        flex: 1;
    }
}
@media (max-width: 420px) {
    .metric-grid {
        grid-template-columns: minmax(0, 1fr);
    }
    .fact-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
