<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowUpRight,
    BarChart3,
    ChevronLeft,
    ChevronRight,
    CalendarDays,
    FileText,
    LayoutGrid,
    Pencil,
    Plus,
    RefreshCw,
    Receipt,
    Search,
    Trash2,
    Wallet,
    X,
} from 'lucide-vue-next';
import { FocusScope } from 'reka-ui';
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { toast } from 'vue-sonner';
import api from '@/utils/api';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Financial', href: '/financial' },
        ],
    },
});

// ── Types ───────────────────────────────────────────────────────────────────────
interface FtSummary {
    period: { start: string; end: string };
    payments: { total: number; count: number };
    expenses: { total: number; count: number };
    income_adjustments: { total: number; count: number };
    payroll: { total: number; count: number };
    asset_deductions: { total: number; count: number };
    payout_shares: { total: number; count: number };
    net: number;
    opening_balance: number;
    balance_as_of_end: number;
    balance_by_tender: { tender: string; balance: number; count: number }[];
    by_tender: { tender: string; total: number; count: number }[];
    net_by_tender: {
        tender: string;
        total_in: number;
        total_out: number;
        net: number;
        count: number;
    }[];
    include_asset_deductions: boolean;
}
interface PaymentTender {
    id: number;
    name: string;
    is_active: boolean;
}
interface FtTransaction {
    id: number;
    type: string;
    amount: number;
    description: string;
    notes: string | null;
    transacted_at: string;
    financial_balance: number | null;
    payment_tender_id: number | null;
    order_id: number | null;
    user?: { name: string };
    tender?: { id: number; name: string };
    order?: { customer_name: string | null; id: number } | null;
}
interface BillsSummary {
    total_due: number;
    overdue: number;
    upcoming: number;
    count: number;
    period: { start: string; end: string };
}
type Tab = 'overview' | 'ledger' | 'performance';
type Preset = 'today' | 'yesterday' | '7d' | 'month' | 'lastMonth' | 'custom';

// ── Auth ──────────────────────────────────────────────────────────────────────
const page = usePage();
const isAdmin = computed(() =>
    ((page.props.auth as any)?.roles ?? []).includes('admin'),
);

// ── Dates ─────────────────────────────────────────────────────────────────────
// Local (Manila) dates. toISOString() is UTC, which is a day behind before 8 am.
const ymd = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const addDays = (d: Date, n: number) => {
    const c = new Date(d);
    c.setDate(c.getDate() + n);

    return c;
};
const parseYmd = (s: string) => new Date(s + 'T00:00:00');
const today = ymd(new Date());
const nowDatetimeLocal = () => {
    const d = new Date();

    return `${ymd(d)}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};

// ── State ──────────────────────────────────────────────────────────────────────
const ftStartDate = ref(today);
const ftEndDate = ref(today);
const activePreset = ref<Preset>('today');
const ftTypeFilter = ref('');
const ftTenderFilter = ref<number | ''>('');
const loading = ref(false);
const refreshing = ref(false);
const ftSummary = ref<FtSummary | null>(null);
const billsSummary = ref<BillsSummary | null>(null);
const ftTransactions = ref<FtTransaction[]>([]);
const ftMeta = ref<any>(null);
const ftPage = ref(1);
const entryForm = ref({
    type: 'expense' as 'expense' | 'income_adjustment',
    description: '',
    amount: '',
    notes: '',
    transacted_at: nowDatetimeLocal(),
    payment_tender_id: null as number | null,
});
const entrySaving = ref(false);
const ftDeleting = ref<number | null>(null);
const ftSearch = ref('');
const ftSortKey = ref<'transacted_at' | 'type' | 'amount' | 'description'>(
    'transacted_at',
);
const ftSortDir = ref<'asc' | 'desc'>('desc');
const tenders = ref<PaymentTender[]>([]);
const includeAssetDeductions = ref(true);
const editingTx = ref<FtTransaction | null>(null);
const editForm = ref({
    type: '',
    description: '',
    amount: '',
    notes: '',
    transacted_at: '',
    payment_tender_id: null as number | null,
});
const editSaving = ref(false);
const sheet = ref<null | 'new' | 'edit'>(null);

const readTab = (): Tab => {
    try {
        const saved = localStorage.getItem('financial-tab');

        return saved === 'ledger' || saved === 'performance'
            ? saved
            : 'overview';
    } catch {
        return 'overview';
    }
};
const activeTab = ref<Tab>(readTab());
const dailyData = ref<
    { date: string; income: number; expense: number; balance: number }[]
>([]);
const prevSummary = ref<FtSummary | null>(null);

interface PeriodRow {
    start: string;
    end: string;
    opening: number;
    money_in: number;
    money_out: number;
    net: number;
    closing: number;
    count: number;
    is_current: boolean;
}
const periodHistory = ref<{
    granularity: 'day' | 'week' | 'month' | 'period';
    period_days: number;
    rows: PeriodRow[];
} | null>(null);
const historyTitle = computed(() => {
    const h = periodHistory.value;

    if (!h) {
        return 'Period by period';
    }

    return {
        day: 'Day by day',
        week: 'Week by week',
        month: 'Month by month',
        period: `${h.period_days}-day periods`,
    }[h.granularity];
});
const periodRowLabel = (row: PeriodRow) => {
    const g = periodHistory.value?.granularity;
    const start = parseYmd(row.start);

    if (g === 'month') {
        const month = start.toLocaleDateString('en-PH', {
            month: 'long',
            year: 'numeric',
        });
        const monthEnd = new Date(start.getFullYear(), start.getMonth() + 1, 0);

        return row.end === ymd(monthEnd)
            ? month
            : `${month} (to ${parseYmd(row.end).getDate()})`;
    }

    if (g === 'day') {
        return start.toLocaleDateString('en-PH', {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
        });
    }

    return `${fmtDay(row.start)} – ${fmtDay(row.end)}`;
};
const historyMaxNet = computed(() =>
    Math.max(
        1,
        ...(periodHistory.value?.rows ?? []).map((r) => Math.abs(r.net)),
    ),
);
const perfLoading = ref(false);
const perfStale = ref(true);
const hoveredDayIdx = ref<number | null>(null);
const showComparisonHelp = ref(false);

// ── Helpers ───────────────────────────────────────────────────────────────────
// Negative amounts read "−₱849.67", not "₱-849.67".
const fmt = (v: number | string | null | undefined) => {
    const n = parseFloat(String(v ?? 0)) || 0;

    return (
        (n < 0 ? '−' : '') +
        '₱' +
        Math.abs(n).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })
    );
};

const fmtDatetime = (s: string) => {
    if (!s) {
        return '—';
    }

    // MySQL returns "YYYY-MM-DD HH:MM:SS" (space separator); Safari rejects that format.
    // Replace the space with T so all browsers get a valid ISO-8601 string.
    const d = new Date(s.replace(' ', 'T'));

    if (isNaN(d.getTime())) {
        return s;
    }

    return (
        d.toLocaleDateString('en-PH', {
            month: 'short',
            day: 'numeric',
            year: '2-digit',
        }) +
        ' ' +
        d.toLocaleTimeString('en-PH', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: true,
        })
    );
};
const fmtDay = (s: string) =>
    parseYmd(s).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });

const typeLabel = (t: string) =>
    ({
        order: 'Order',
        payment: 'Payment',
        expense: 'Expense',
        income_adjustment: 'Income Adj.',
        payroll: 'Payroll',
        asset_deduction: 'Asset Deduction',
        payout_share: 'Payout Share',
    })[t] ?? t;

const typeOptions = [
    { value: '', label: 'All' },
    { value: 'payment', label: 'Payments' },
    { value: 'expense', label: 'Expenses' },
    { value: 'income_adjustment', label: 'Income adj.' },
    { value: 'payroll', label: 'Payroll' },
    { value: 'asset_deduction', label: 'Asset deductions' },
    { value: 'payout_share', label: 'Payout shares' },
];

const isCredit = (t: string) => t === 'payment' || t === 'income_adjustment';

// ── Period ────────────────────────────────────────────────────────────────────
const presets: { key: Preset; label: string }[] = [
    { key: 'today', label: 'Today' },
    { key: 'yesterday', label: 'Yesterday' },
    { key: '7d', label: 'Last 7 days' },
    { key: 'month', label: 'This month' },
    { key: 'lastMonth', label: 'Last month' },
    { key: 'custom', label: 'Custom' },
];
const setPreset = (key: Preset) => {
    activePreset.value = key;

    if (key === 'custom') {
        return;
    }

    const now = new Date();
    const ranges: Record<Exclude<Preset, 'custom'>, [Date, Date]> = {
        today: [now, now],
        yesterday: [addDays(now, -1), addDays(now, -1)],
        '7d': [addDays(now, -6), now],
        month: [new Date(now.getFullYear(), now.getMonth(), 1), now],
        lastMonth: [
            new Date(now.getFullYear(), now.getMonth() - 1, 1),
            new Date(now.getFullYear(), now.getMonth(), 0),
        ],
    };
    const [start, end] = ranges[key];
    ftStartDate.value = ymd(start);
    ftEndDate.value = ymd(end);
    reload();
};
const onCustomDate = () => {
    if (
        ftStartDate.value &&
        ftEndDate.value &&
        ftStartDate.value > ftEndDate.value
    ) {
        [ftStartDate.value, ftEndDate.value] = [
            ftEndDate.value,
            ftStartDate.value,
        ];
    }

    reload();
};
const periodDays = computed(
    () =>
        Math.round(
            (parseYmd(ftEndDate.value).getTime() -
                parseYmd(ftStartDate.value).getTime()) /
                86400000,
        ) + 1,
);
const periodLabel = computed(() =>
    ftStartDate.value === ftEndDate.value
        ? parseYmd(ftStartDate.value).toLocaleDateString('en-PH', {
              weekday: 'short',
              month: 'short',
              day: 'numeric',
              year: 'numeric',
          })
        : `${fmtDay(ftStartDate.value)} – ${parseYmd(ftEndDate.value).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })}`,
);

// ── Computed ─────────────────────────────────────────────────────────────────────
const periodIncome = computed(() => {
    if (!ftSummary.value) {
        return 0;
    }

    return (
        ftSummary.value.payments.total +
        (ftSummary.value.income_adjustments?.total ?? 0)
    );
});

const periodExpenses = computed(() => {
    if (!ftSummary.value) {
        return 0;
    }

    const s = ftSummary.value;

    return (
        s.expenses.total +
        (s.payroll?.total ?? 0) +
        (s.asset_deductions?.total ?? 0) +
        (s.payout_shares?.total ?? 0)
    );
});

const comparisonRows = computed(() => {
    if (!ftSummary.value) {
        return [];
    }

    const s = ftSummary.value;
    const p = prevSummary.value;
    const prevIncome = p
        ? p.payments.total + (p.income_adjustments?.total ?? 0)
        : 0;
    const prevExpenses = p
        ? p.expenses.total +
          (p.payroll?.total ?? 0) +
          (p.asset_deductions?.total ?? 0) +
          (p.payout_shares?.total ?? 0)
        : 0;
    const mkRow = (
        label: string,
        cur: number,
        prev: number,
        higherIsBetter = true,
    ) => {
        const change = cur - prev;
        const changePct =
            prev !== 0 ? Math.round((change / Math.abs(prev)) * 100) : null;
        const good = higherIsBetter ? change >= 0 : change <= 0;

        return {
            label,
            cur,
            prev,
            change,
            changePct,
            tone: change === 0 ? 'flat' : good ? 'good' : 'bad',
            sign: change >= 0 ? '+' : '−',
        };
    };

    return [
        mkRow('Total income', periodIncome.value, prevIncome, true),
        mkRow('Total outflow', periodExpenses.value, prevExpenses, false),
        mkRow('Net cash', s.net ?? 0, p?.net ?? 0, true),
        mkRow('Payments', s.payments.total, p?.payments?.total ?? 0, true),
        mkRow(
            'Expenses only',
            s.expenses.total,
            p?.expenses?.total ?? 0,
            false,
        ),
        mkRow('Payroll', s.payroll?.total ?? 0, p?.payroll?.total ?? 0, false),
    ];
});

// Every movement type in the period, sized against the largest, for the overview bars.
const typeRows = computed(() => {
    if (!ftSummary.value) {
        return [];
    }

    const s = ftSummary.value;
    const rows = [
        { type: 'payment', total: s.payments.total, count: s.payments.count },
        {
            type: 'income_adjustment',
            total: s.income_adjustments?.total ?? 0,
            count: s.income_adjustments?.count ?? 0,
        },
        { type: 'expense', total: s.expenses.total, count: s.expenses.count },
        {
            type: 'payroll',
            total: s.payroll?.total ?? 0,
            count: s.payroll?.count ?? 0,
        },
        {
            type: 'asset_deduction',
            total: s.asset_deductions?.total ?? 0,
            count: s.asset_deductions?.count ?? 0,
        },
        {
            type: 'payout_share',
            total: s.payout_shares?.total ?? 0,
            count: s.payout_shares?.count ?? 0,
        },
    ].filter((r) => r.total > 0 || r.count > 0);
    const max = Math.max(...rows.map((r) => r.total), 1);
    const inTotal = periodIncome.value || 1;
    const outTotal = periodExpenses.value || 1;

    return rows.map((r) => ({
        ...r,
        credit: isCredit(r.type),
        width: Math.max(2, Math.round((r.total / max) * 100)),
        share: Math.round(
            (r.total / (isCredit(r.type) ? inTotal : outTotal)) * 100,
        ),
    }));
});

// Money in/out per tender this period, joined with each tender's running balance.
const tenderRows = computed(() => {
    const s = ftSummary.value;

    if (!s) {
        return [];
    }

    const balances = new Map(
        s.balance_by_tender.map((b) => [b.tender, b.balance]),
    );
    const names = new Set([
        ...s.net_by_tender.map((r) => r.tender),
        ...s.balance_by_tender.map((b) => b.tender),
    ]);

    return [...names]
        .map((name) => {
            const flow = s.net_by_tender.find((r) => r.tender === name);

            return {
                tender: name,
                id: tenders.value.find((t) => t.name === name)?.id ?? null,
                total_in: flow?.total_in ?? 0,
                total_out: flow?.total_out ?? 0,
                net: flow?.net ?? 0,
                count: flow?.count ?? 0,
                balance: balances.get(name) ?? 0,
            };
        })
        .sort((a, b) => b.balance - a.balance);
});

const lineChart = computed(() => {
    const data = dailyData.value;

    if (!data.length) {
        return null;
    }

    const n = data.length;
    const VW = 600,
        VH = 200;
    const padL = 58,
        padR = 58,
        padT = 14,
        padB = 38;
    const W = VW - padL - padR;
    const H = VH - padT - padB;

    // Left axis: daily income / expense scale
    const maxIE = Math.max(...data.flatMap((d) => [d.income, d.expense]), 100);
    const xPos = (i: number) => padL + (n > 1 ? (i / (n - 1)) * W : W / 2);
    const yPos = (v: number) => padT + H * (1 - v / maxIE);
    const polyline = (key: 'income' | 'expense') =>
        data.map((d, i) => `${xPos(i)},${yPos(d[key])}`).join(' ');
    const area = (key: 'income' | 'expense') => {
        const pts = data.map((d, i) => `L${xPos(i)},${yPos(d[key])}`).join(' ');

        return `M${xPos(0)},${padT + H} ${pts} L${xPos(n - 1)},${padT + H}Z`;
    };
    const yTicks = [0, 0.25, 0.5, 0.75, 1].map((p) => ({
        y: yPos(maxIE * p),
        val: maxIE * p,
    }));

    // Right axis: cumulative balance scale
    const bals = data.map((d) => d.balance);
    const minBal = Math.min(...bals);
    const maxBal = Math.max(...bals);
    const bRange = Math.max(maxBal - minBal, 1);
    const bMin = minBal - bRange * 0.08;
    const bMax = maxBal + bRange * 0.08;
    const yBal = (v: number) => padT + H * (1 - (v - bMin) / (bMax - bMin));
    const balPolyline = data
        .map((d, i) => `${xPos(i)},${yBal(d.balance)}`)
        .join(' ');
    const balTicks = [0, 0.25, 0.5, 0.75, 1].map((p) => ({
        y: padT + H * (1 - p),
        val: bMin + (bMax - bMin) * p,
    }));
    const shortFmt = (v: number) => {
        const abs = Math.abs(v);
        const s = v < 0 ? '-' : '';

        if (abs >= 1_000_000) {
            return s + (abs / 1_000_000).toFixed(1) + 'M';
        }

        if (abs >= 1_000) {
            return s + (abs / 1_000).toFixed(0) + 'k';
        }

        return v.toFixed(0);
    };

    const stepW = n > 1 ? W / (n - 1) : W;
    const xLabels = data
        .map((d, i) => ({ i, x: xPos(i), label: d.date.slice(5) }))
        .filter((_, i) => i === 0 || i === n - 1 || i % 5 === 0);
    const strips = data.map((_, i) => ({
        x: xPos(i) - stepW / 2,
        width: stepW,
        index: i,
    }));

    return {
        polyline,
        area,
        balPolyline,
        yTicks,
        balTicks,
        shortFmt,
        xLabels,
        strips,
        xPos,
        yPos,
        yBal,
        padL,
        padR,
        padT,
        padB,
        H,
        VW,
        VH,
    };
});

const sortedTx = computed(() => {
    let list = ftTransactions.value;
    const q = ftSearch.value.trim().toLowerCase();

    if (q) {
        list = list.filter(
            (tx) =>
                tx.description.toLowerCase().includes(q) ||
                tx.type.toLowerCase().includes(q) ||
                (tx.tender?.name ?? '').toLowerCase().includes(q) ||
                (tx.user?.name ?? '').toLowerCase().includes(q) ||
                (tx.order?.customer_name ?? '').toLowerCase().includes(q),
        );
    }

    const dir = ftSortDir.value === 'asc' ? 1 : -1;

    return [...list].sort((a, b) => {
        switch (ftSortKey.value) {
            case 'transacted_at':
                return (
                    dir *
                    (new Date(a.transacted_at.replace(' ', 'T')).getTime() -
                        new Date(b.transacted_at.replace(' ', 'T')).getTime())
                );
            case 'amount':
                return dir * (a.amount - b.amount);
            case 'type':
                return dir * a.type.localeCompare(b.type);
            case 'description':
                return dir * a.description.localeCompare(b.description);
        }

        return 0;
    });
});

const activeFilters = computed(() => {
    const list: { key: 'type' | 'tender' | 'search'; label: string }[] = [];

    if (ftTypeFilter.value) {
        list.push({
            key: 'type',
            label:
                typeOptions.find((o) => o.value === ftTypeFilter.value)
                    ?.label ?? ftTypeFilter.value,
        });
    }

    if (ftTenderFilter.value !== '') {
        list.push({
            key: 'tender',
            label:
                tenders.value.find((t) => t.id === ftTenderFilter.value)
                    ?.name ?? 'Tender',
        });
    }

    if (ftSearch.value.trim()) {
        list.push({ key: 'search', label: `“${ftSearch.value.trim()}”` });
    }

    return list;
});

const toggleSort = (key: typeof ftSortKey.value) => {
    if (ftSortKey.value === key) {
        ftSortDir.value = ftSortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        ftSortKey.value = key;
        ftSortDir.value = 'desc';
    }
};
const sortMark = (key: typeof ftSortKey.value) =>
    ftSortKey.value !== key ? '↕' : ftSortDir.value === 'asc' ? '↑' : '↓';
const ariaSort = (key: typeof ftSortKey.value) =>
    ftSortKey.value !== key
        ? 'none'
        : ftSortDir.value === 'asc'
          ? 'ascending'
          : 'descending';

// ── Data loading ─────────────────────────────────────────────────────────────────
const loadTenders = async () => {
    try {
        const res = await api.get('/api/v1/payment-tenders');
        tenders.value = Array.isArray(res.data)
            ? res.data
            : (res.data?.data ?? []);
    } catch {
        /* non-fatal */
    }
};

const loadFinancial = async (page = 1) => {
    ftPage.value = page;

    try {
        const [summaryRes, listRes, billsRes] = await Promise.all([
            api.get('/api/v1/financial-transactions/summary', {
                params: {
                    start_date: ftStartDate.value || undefined,
                    end_date: ftEndDate.value || undefined,
                    include_asset_deductions: includeAssetDeductions.value,
                },
            }),
            api.get('/api/v1/financial-transactions', {
                params: {
                    page,
                    start_date: ftStartDate.value || undefined,
                    end_date: ftEndDate.value || undefined,
                    type: ftTypeFilter.value || undefined,
                    payment_tender_id: ftTenderFilter.value || undefined,
                    include_asset_deductions: includeAssetDeductions.value,
                },
            }),
            api
                .get('/api/v1/bills/summary', {
                    params: {
                        start_date: ftStartDate.value || undefined,
                        end_date: ftEndDate.value || undefined,
                    },
                })
                .catch(() => ({ data: null })),
        ]);
        ftSummary.value = summaryRes.data;
        ftTransactions.value = listRes.data.data ?? [];
        ftMeta.value = listRes.data.meta ?? listRes.data;
        billsSummary.value = billsRes.data;
    } catch (err: any) {
        toast.error(
            err.response?.data?.message ?? 'Failed to load transactions.',
        );
    }
};

const loadPerformance = async () => {
    perfLoading.value = true;

    try {
        const start = parseYmd(ftStartDate.value);
        const prevEnd = addDays(start, -1);
        const prevStart = addDays(prevEnd, -(periodDays.value - 1));
        const [dailyRes, prevRes, periodsRes] = await Promise.all([
            api.get('/api/v1/financial-transactions/daily', {
                params: { days: 30 },
            }),
            api.get('/api/v1/financial-transactions/summary', {
                params: {
                    start_date: ymd(prevStart),
                    end_date: ymd(prevEnd),
                    include_asset_deductions: includeAssetDeductions.value,
                },
            }),
            api.get('/api/v1/financial-transactions/periods', {
                params: {
                    start_date: ftStartDate.value,
                    end_date: ftEndDate.value,
                    count: 6,
                    include_asset_deductions: includeAssetDeductions.value,
                },
            }),
        ]);
        dailyData.value = dailyRes.data;
        prevSummary.value = prevRes.data;
        periodHistory.value = periodsRes.data;
        perfStale.value = false;
    } catch {
        toast.error('Failed to load performance data.');
    } finally {
        perfLoading.value = false;
    }
};

// Reload everything that depends on the period or filters.
const reload = async (page = 1) => {
    perfStale.value = true;
    await loadFinancial(page);

    if (activeTab.value === 'performance') {
        await loadPerformance();
    }
};

const refreshAll = async () => {
    refreshing.value = true;

    try {
        await Promise.all([reload(ftPage.value), loadTenders()]);
    } finally {
        refreshing.value = false;
    }
};

const switchTab = (tab: Tab) => {
    activeTab.value = tab;

    try {
        localStorage.setItem('financial-tab', tab);
    } catch {
        /* private mode */
    }

    if (tab === 'performance' && perfStale.value) {
        loadPerformance();
    }
};

// Jump from an overview figure to the matching ledger rows.
const showInLedger = (filters: { type?: string; tender?: number | null }) => {
    ftTypeFilter.value = filters.type ?? '';
    ftTenderFilter.value = filters.tender ?? '';
    ftSearch.value = '';
    switchTab('ledger');
    loadFinancial();
};
const setTypeFilter = (type: string) => {
    ftTypeFilter.value = type;
    loadFinancial();
};
const clearFilter = (key: 'type' | 'tender' | 'search') => {
    if (key === 'search') {
        ftSearch.value = '';

        return;
    }

    if (key === 'type') {
        ftTypeFilter.value = '';
    } else {
        ftTenderFilter.value = '';
    }

    loadFinancial();
};
const clearAllFilters = () => {
    ftTypeFilter.value = '';
    ftTenderFilter.value = '';
    ftSearch.value = '';
    loadFinancial();
};

const onChartTouch = (e: TouchEvent) => {
    if (!lineChart.value || !dailyData.value.length) {
        return;
    }

    const target = e.currentTarget as SVGSVGElement;
    const rect = target.getBoundingClientRect();
    const touch = e.touches[0];

    if (!touch) {
        return;
    }

    const relX = touch.clientX - rect.left;
    const chart = lineChart.value;
    const svgX = (relX / rect.width) * chart.VW;
    const n = dailyData.value.length;
    hoveredDayIdx.value = Math.round(
        Math.max(
            0,
            Math.min(
                n - 1,
                ((svgX - chart.padL) / (chart.VW - chart.padL - chart.padR)) *
                    (n - 1),
            ),
        ),
    );
};

// ── Actions ────────────────────────────────────────────────────────────────────
const entryValid = computed(
    () =>
        !!entryForm.value.description.trim() &&
        Number(entryForm.value.amount) > 0 &&
        !!entryForm.value.payment_tender_id &&
        !!entryForm.value.transacted_at,
);
const openNewEntry = (type: 'expense' | 'income_adjustment' = 'expense') => {
    editingTx.value = null;
    entryForm.value = {
        type,
        description: '',
        amount: '',
        notes: '',
        transacted_at: nowDatetimeLocal(),
        payment_tender_id: entryForm.value.payment_tender_id,
    };
    sheet.value = 'new';
};
const closeSheet = () => {
    sheet.value = null;
    editingTx.value = null;
};

const saveEntry = async () => {
    if (!entryValid.value) {
        return;
    }

    entrySaving.value = true;
    const recordedDate = entryForm.value.transacted_at.substring(0, 10);
    const payload = {
        type: entryForm.value.type,
        amount: parseFloat(entryForm.value.amount),
        description: entryForm.value.description,
        notes: entryForm.value.notes || null,
        transacted_at: entryForm.value.transacted_at || null,
        payment_tender_id: entryForm.value.payment_tender_id || null,
    };

    try {
        await api.post('/api/v1/financial-transactions', payload);
        const label =
            entryForm.value.type === 'income_adjustment'
                ? 'Income adjustment'
                : 'Expense';
        toast.success(`${label} recorded.`);
        closeSheet();

        // Widen the period to include the entry's date so it's always visible after save.
        if (
            recordedDate < ftStartDate.value ||
            recordedDate > ftEndDate.value
        ) {
            if (recordedDate < ftStartDate.value) {
                ftStartDate.value = recordedDate;
            }

            if (recordedDate > ftEndDate.value) {
                ftEndDate.value = recordedDate;
            }

            activePreset.value = 'custom';
        }

        await reload();
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to save entry.');
    } finally {
        entrySaving.value = false;
    }
};

const startEdit = (tx: FtTransaction) => {
    editingTx.value = tx;
    // Format datetime-local value: strip seconds/ms from ISO string
    const dt = tx.transacted_at
        ? tx.transacted_at.replace(' ', 'T').substring(0, 16)
        : '';
    editForm.value = {
        type: tx.type,
        description: tx.description,
        amount: String(tx.amount),
        notes: tx.notes ?? '',
        transacted_at: dt,
        payment_tender_id: tx.payment_tender_id ?? null,
    };
    sheet.value = 'edit';
};

const saveEdit = async () => {
    if (!editingTx.value) {
        return;
    }

    editSaving.value = true;

    try {
        await api.patch(
            `/api/v1/financial-transactions/${editingTx.value.id}`,
            {
                type: editForm.value.type || undefined,
                description: editForm.value.description || undefined,
                amount: editForm.value.amount
                    ? parseFloat(editForm.value.amount)
                    : undefined,
                notes: editForm.value.notes || null,
                transacted_at: editForm.value.transacted_at || undefined,
                payment_tender_id: editForm.value.payment_tender_id || null,
            },
        );
        toast.success('Transaction updated.');
        closeSheet();
        await reload(ftPage.value);
    } catch (err: any) {
        toast.error(
            err.response?.data?.message ?? 'Failed to update transaction.',
        );
    } finally {
        editSaving.value = false;
    }
};

const deleteTransaction = async (tx: FtTransaction) => {
    const orderNote =
        tx.order_id && ['order', 'payment'].includes(tx.type)
            ? `\n\nThis will also delete Order #${tx.order_id} and all of its payments and transactions.`
            : '';

    if (
        !confirm(
            `Delete transaction?\n${tx.description}\nAmount: ${fmt(tx.amount)}${orderNote}`,
        )
    ) {
        return;
    }

    ftDeleting.value = tx.id;

    try {
        await api.delete(`/api/v1/financial-transactions/${tx.id}`);
        toast.success('Transaction deleted.');

        if (editingTx.value?.id === tx.id) {
            closeSheet();
        }

        await reload(ftPage.value);
    } catch (err: any) {
        toast.error(
            err.response?.data?.message ?? 'Failed to delete transaction.',
        );
    } finally {
        ftDeleting.value = null;
    }
};
const canDelete = (tx: FtTransaction) =>
    isAdmin.value || ['expense', 'income_adjustment'].includes(tx.type);

watch(includeAssetDeductions, () => reload());

// Lock page scroll while the entry sheet is open.
let originalOverflow: string | null = null;
watch(sheet, (open) => {
    if (open && originalOverflow === null) {
        originalOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    } else if (!open && originalOverflow !== null) {
        document.body.style.overflow = originalOverflow;
        originalOverflow = null;
    }
});

onMounted(async () => {
    loading.value = true;

    try {
        await Promise.all([loadFinancial(), loadTenders()]);

        if (activeTab.value === 'performance') {
            await loadPerformance();
        }
    } finally {
        loading.value = false;
    }
});
onBeforeUnmount(() => {
    if (originalOverflow !== null) {
        document.body.style.overflow = originalOverflow;
    }
});
</script>

<template>
    <Head title="Financial" />

    <div class="fin-theme fin-page">
        <!-- ── Heading ──────────────────────────────────────────────────────── -->
        <header class="fin-heading">
            <div>
                <p class="fin-eyebrow">
                    <span aria-hidden="true" />BYPASS GRILL / FINANCES
                </p>
                <h1>FOLLOW EVERY <span>PESO.</span></h1>
                <p class="fin-intro">
                    Money in, money out, and where it sits, for any period you
                    pick.
                </p>
            </div>
            <div class="fin-heading-actions">
                <button
                    class="fin-ghost-btn"
                    :disabled="refreshing"
                    @click="refreshAll"
                >
                    <RefreshCw
                        :size="14"
                        :class="{ spinning: refreshing }"
                        aria-hidden="true"
                    />{{ refreshing ? 'Refreshing…' : 'Refresh' }}
                </button>
                <button class="fin-primary-btn" @click="openNewEntry()">
                    <Plus :size="16" aria-hidden="true" />Record entry
                </button>
            </div>
        </header>

        <!-- ── Period bar ───────────────────────────────────────────────────── -->
        <section class="fin-period" aria-label="Reporting period">
            <div class="fin-period-top">
                <div class="fin-period-label">
                    <CalendarDays :size="16" aria-hidden="true" />
                    <strong>{{ periodLabel }}</strong>
                    <small
                        >{{ periodDays }} day{{
                            periodDays !== 1 ? 's' : ''
                        }}</small
                    >
                </div>
                <label class="fin-switch">
                    <input v-model="includeAssetDeductions" type="checkbox" />
                    <span class="fin-switch-track" aria-hidden="true"
                        ><span
                    /></span>
                    Include asset deductions
                </label>
            </div>
            <div class="fin-presets" role="group" aria-label="Quick periods">
                <button
                    v-for="p in presets"
                    :key="p.key"
                    :aria-pressed="activePreset === p.key"
                    @click="setPreset(p.key)"
                >
                    {{ p.label }}
                </button>
            </div>
            <div v-if="activePreset === 'custom'" class="fin-custom-range">
                <label
                    >From<input
                        v-model="ftStartDate"
                        type="date"
                        :max="ftEndDate"
                        @change="onCustomDate"
                /></label>
                <label
                    >To<input
                        v-model="ftEndDate"
                        type="date"
                        :min="ftStartDate"
                        @change="onCustomDate"
                /></label>
            </div>
        </section>

        <!-- ── KPI strip ────────────────────────────────────────────────────── -->
        <section class="fin-kpis" aria-label="Period totals">
            <!-- Balance forward: opening + money in − money out = closing -->
            <template v-if="ftSummary">
                <article class="fin-kpi">
                    <p>
                        <Wallet :size="13" aria-hidden="true" />Opening balance
                    </p>
                    <strong>{{ fmt(ftSummary.opening_balance ?? 0) }}</strong>
                    <span
                        >Brought forward from before
                        {{ fmtDay(ftSummary.period.start) }}</span
                    >
                </article>
                <article class="fin-kpi" data-op="+">
                    <p>
                        <ArrowDownLeft :size="13" aria-hidden="true" />Money in
                    </p>
                    <strong class="is-in">{{ fmt(periodIncome) }}</strong>
                    <span>Payments + income adjustments</span>
                </article>
                <article class="fin-kpi" data-op="−">
                    <p>
                        <ArrowUpRight :size="13" aria-hidden="true" />Money out
                    </p>
                    <strong class="is-out">{{ fmt(periodExpenses) }}</strong>
                    <span
                        >Expenses, payroll{{
                            includeAssetDeductions ? ', asset deductions' : ''
                        }}, payouts</span
                    >
                </article>
                <article class="fin-kpi fin-kpi-dark" data-op="=">
                    <p>
                        <Wallet :size="13" aria-hidden="true" />Closing balance
                    </p>
                    <strong>{{ fmt(ftSummary.balance_as_of_end ?? 0) }}</strong>
                    <span
                        >As of {{ fmtDay(ftSummary.period.end) }} ·
                        <b :class="ftSummary.net >= 0 ? 'net-up' : 'net-down'"
                            >{{ ftSummary.net >= 0 ? '▲' : '▼' }}
                            {{ fmt(ftSummary.net) }}
                            {{ ftSummary.net >= 0 ? 'surplus' : 'deficit' }}</b
                        ></span
                    >
                </article>
            </template>
            <p v-else class="fin-loading" role="status">
                {{ loading ? 'Loading figures…' : 'No figures loaded.' }}
            </p>
        </section>

        <!-- ── Tabs ─────────────────────────────────────────────────────────── -->
        <nav class="fin-tabs" role="tablist" aria-label="Financial views">
            <button
                role="tab"
                :aria-selected="activeTab === 'overview'"
                @click="switchTab('overview')"
            >
                <LayoutGrid :size="15" aria-hidden="true" />Overview
            </button>
            <button
                role="tab"
                :aria-selected="activeTab === 'ledger'"
                @click="switchTab('ledger')"
            >
                <FileText :size="15" aria-hidden="true" />Ledger<span
                    v-if="ftMeta?.total != null"
                    class="fin-tab-count"
                    >{{ ftMeta.total }}</span
                >
            </button>
            <button
                role="tab"
                :aria-selected="activeTab === 'performance'"
                @click="switchTab('performance')"
            >
                <BarChart3 :size="15" aria-hidden="true" />Performance
            </button>
        </nav>

        <!-- ══ OVERVIEW ══════════════════════════════════════════════════════ -->
        <div v-show="activeTab === 'overview'" role="tabpanel" class="fin-grid">
            <section class="fin-panel fin-span-2">
                <div class="fin-panel-head">
                    <div>
                        <p class="fin-kicker">
                            WHERE IT CAME FROM, WHERE IT WENT
                        </p>
                        <h2>Money by type</h2>
                    </div>
                    <small>Select a row to open it in the ledger</small>
                </div>
                <ul v-if="typeRows.length" class="fin-type-list">
                    <li v-for="row in typeRows" :key="row.type">
                        <button @click="showInLedger({ type: row.type })">
                            <span class="fin-type-name">
                                <i
                                    :class="row.credit ? 'dot-in' : 'dot-out'"
                                    aria-hidden="true"
                                />{{ typeLabel(row.type) }}
                                <small
                                    >{{ row.count }} txn{{
                                        row.count !== 1 ? 's' : ''
                                    }}
                                    · {{ row.share }}% of
                                    {{ row.credit ? 'in' : 'out' }}</small
                                >
                            </span>
                            <span
                                class="fin-type-amount"
                                :class="row.credit ? 'is-in' : 'is-out'"
                                >{{ row.credit ? '+' : '−'
                                }}{{ fmt(row.total) }}</span
                            >
                            <span class="fin-bar" aria-hidden="true"
                                ><span
                                    :class="row.credit ? 'bar-in' : 'bar-out'"
                                    :style="{ width: row.width + '%' }"
                            /></span>
                        </button>
                    </li>
                </ul>
                <div v-else class="fin-empty">
                    <Receipt :size="26" aria-hidden="true" />
                    <h3>Nothing recorded in this period.</h3>
                    <p>
                        Pick another period above, or record an expense or
                        income adjustment.
                    </p>
                </div>
            </section>

            <section class="fin-panel">
                <div class="fin-panel-head">
                    <div>
                        <p class="fin-kicker">CASH FLOW</p>
                        <h2>In vs out</h2>
                    </div>
                </div>
                <div v-if="ftSummary" class="fin-flow">
                    <div class="fin-flow-bars" aria-hidden="true">
                        <span
                            class="bar-in"
                            :style="{
                                flexGrow:
                                    periodIncome || (periodExpenses ? 0 : 1),
                            }"
                        />
                        <span
                            class="bar-out"
                            :style="{ flexGrow: periodExpenses }"
                        />
                    </div>
                    <dl class="fin-list">
                        <div>
                            <dt><i class="dot-in" />Money in</dt>
                            <dd class="is-in">{{ fmt(periodIncome) }}</dd>
                        </div>
                        <div>
                            <dt><i class="dot-out" />Money out</dt>
                            <dd class="is-out">−{{ fmt(periodExpenses) }}</dd>
                        </div>
                        <div class="fin-list-total">
                            <dt>Net cash</dt>
                            <dd
                                :class="ftSummary.net >= 0 ? 'is-in' : 'is-out'"
                            >
                                {{ ftSummary.net < 0 ? '−' : ''
                                }}{{ fmt(Math.abs(ftSummary.net)) }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </section>

            <section class="fin-panel fin-bills">
                <div class="fin-panel-head">
                    <div>
                        <p class="fin-kicker">PAYABLES</p>
                        <h2>Bills due</h2>
                    </div>
                </div>
                <p class="fin-bills-total">
                    {{ fmt(billsSummary?.total_due ?? 0) }}
                </p>
                <dl class="fin-list">
                    <div>
                        <dt>Bills in this period</dt>
                        <dd>{{ billsSummary?.count ?? 0 }}</dd>
                    </div>
                    <div v-if="billsSummary?.overdue">
                        <dt>Overdue</dt>
                        <dd class="is-out">{{ fmt(billsSummary.overdue) }}</dd>
                    </div>
                    <div v-if="billsSummary?.upcoming">
                        <dt>Upcoming</dt>
                        <dd>{{ fmt(billsSummary.upcoming) }}</dd>
                    </div>
                </dl>
                <Link href="/bills" class="fin-text-link"
                    >Manage bills <ArrowUpRight :size="14" aria-hidden="true"
                /></Link>
            </section>

            <section class="fin-panel fin-span-2">
                <div class="fin-panel-head">
                    <div>
                        <p class="fin-kicker">WHERE THE MONEY SITS</p>
                        <h2>Tenders &amp; accounts</h2>
                    </div>
                    <small
                        >Balance is all-time up to
                        {{
                            ftSummary ? fmtDay(ftSummary.period.end) : ''
                        }}</small
                    >
                </div>
                <div
                    v-if="tenderRows.length"
                    class="fin-table-scroll"
                    tabindex="0"
                    role="region"
                    aria-label="Tender balances"
                >
                    <table class="fin-table">
                        <thead>
                            <tr>
                                <th scope="col">Tender</th>
                                <th scope="col" class="num">In</th>
                                <th scope="col" class="num">Out</th>
                                <th scope="col" class="num">Net this period</th>
                                <th scope="col" class="num">Balance</th>
                                <th scope="col">
                                    <span class="sr-only">Open in ledger</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in tenderRows" :key="row.tender">
                                <td>
                                    <strong>{{ row.tender }}</strong
                                    ><small
                                        >{{ row.count }} txn{{
                                            row.count !== 1 ? 's' : ''
                                        }}
                                        this period</small
                                    >
                                </td>
                                <td class="num is-in">
                                    +{{ fmt(row.total_in) }}
                                </td>
                                <td class="num is-out">
                                    −{{ fmt(row.total_out) }}
                                </td>
                                <td
                                    class="num"
                                    :class="row.net >= 0 ? 'is-in' : 'is-out'"
                                >
                                    {{ row.net < 0 ? '−' : '+'
                                    }}{{ fmt(Math.abs(row.net)) }}
                                </td>
                                <td
                                    class="num fin-strong"
                                    :class="{ 'is-out': row.balance < 0 }"
                                >
                                    {{ row.balance < 0 ? '−' : ''
                                    }}{{ fmt(Math.abs(row.balance)) }}
                                </td>
                                <td class="num">
                                    <button
                                        v-if="row.id"
                                        class="fin-row-link"
                                        @click="
                                            showInLedger({ tender: row.id })
                                        "
                                    >
                                        Ledger
                                        <ChevronRight
                                            :size="13"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="num is-in">
                                    +{{
                                        fmt(
                                            tenderRows.reduce(
                                                (s, r) => s + r.total_in,
                                                0,
                                            ),
                                        )
                                    }}
                                </td>
                                <td class="num is-out">
                                    −{{
                                        fmt(
                                            tenderRows.reduce(
                                                (s, r) => s + r.total_out,
                                                0,
                                            ),
                                        )
                                    }}
                                </td>
                                <td class="num">
                                    {{
                                        fmt(
                                            tenderRows.reduce(
                                                (s, r) => s + r.net,
                                                0,
                                            ),
                                        )
                                    }}
                                </td>
                                <td class="num">
                                    {{ fmt(ftSummary?.balance_as_of_end ?? 0) }}
                                </td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p v-else class="fin-muted">
                    No tender activity yet. Tag expenses and income adjustments
                    to a tender when recording them.
                </p>
            </section>

            <section v-if="ftSummary?.by_tender.length" class="fin-panel">
                <div class="fin-panel-head">
                    <div>
                        <p class="fin-kicker">SALES COLLECTED</p>
                        <h2>Payments by tender</h2>
                    </div>
                </div>
                <dl class="fin-list">
                    <div v-for="row in ftSummary.by_tender" :key="row.tender">
                        <dt>
                            {{ row.tender }} <small>{{ row.count }}</small>
                        </dt>
                        <dd class="is-in">{{ fmt(row.total) }}</dd>
                    </div>
                </dl>
            </section>
        </div>

        <!-- ══ LEDGER ════════════════════════════════════════════════════════ -->
        <div v-show="activeTab === 'ledger'" role="tabpanel" class="fin-stack">
            <section class="fin-panel fin-toolbar">
                <div class="fin-toolbar-row">
                    <div class="fin-search">
                        <Search :size="15" aria-hidden="true" />
                        <input
                            v-model="ftSearch"
                            type="search"
                            placeholder="Search description, customer, tender, or staff"
                            aria-label="Search transactions"
                        />
                    </div>
                    <select
                        v-model="ftTenderFilter"
                        class="fin-select"
                        aria-label="Filter by tender"
                        @change="loadFinancial()"
                    >
                        <option value="">All tenders</option>
                        <option v-for="t in tenders" :key="t.id" :value="t.id">
                            {{ t.name }}
                        </option>
                    </select>
                </div>
                <div class="fin-chips" role="group" aria-label="Filter by type">
                    <button
                        v-for="o in typeOptions"
                        :key="o.value"
                        :aria-pressed="ftTypeFilter === o.value"
                        @click="setTypeFilter(o.value)"
                    >
                        {{ o.label }}
                    </button>
                </div>
                <div v-if="activeFilters.length" class="fin-active-filters">
                    <span>Showing:</span>
                    <button
                        v-for="f in activeFilters"
                        :key="f.key"
                        :aria-label="`Remove filter ${f.label}`"
                        @click="clearFilter(f.key)"
                    >
                        {{ f.label }} <X :size="12" aria-hidden="true" />
                    </button>
                    <button class="fin-clear-all" @click="clearAllFilters">
                        Clear all
                    </button>
                </div>
            </section>

            <section class="fin-panel fin-ledger">
                <div class="fin-panel-head">
                    <div>
                        <p class="fin-kicker">LEDGER</p>
                        <h2>Transactions</h2>
                    </div>
                    <small>{{
                        ftSearch
                            ? `${sortedTx.length} match${sortedTx.length !== 1 ? 'es' : ''} on this page`
                            : ftMeta?.total != null
                              ? `${ftMeta.total} in this period`
                              : ''
                    }}</small>
                </div>

                <!-- Desktop table -->
                <div
                    v-if="sortedTx.length"
                    class="fin-table-scroll fin-desktop"
                    tabindex="0"
                    role="region"
                    aria-label="Transactions table"
                >
                    <table class="fin-table">
                        <thead>
                            <tr>
                                <th
                                    scope="col"
                                    :aria-sort="ariaSort('transacted_at')"
                                >
                                    <button
                                        class="fin-sort"
                                        @click="toggleSort('transacted_at')"
                                    >
                                        Date
                                        <span>{{
                                            sortMark('transacted_at')
                                        }}</span>
                                    </button>
                                </th>
                                <th scope="col" :aria-sort="ariaSort('type')">
                                    <button
                                        class="fin-sort"
                                        @click="toggleSort('type')"
                                    >
                                        Type <span>{{ sortMark('type') }}</span>
                                    </button>
                                </th>
                                <th
                                    scope="col"
                                    :aria-sort="ariaSort('description')"
                                >
                                    <button
                                        class="fin-sort"
                                        @click="toggleSort('description')"
                                    >
                                        Description
                                        <span>{{
                                            sortMark('description')
                                        }}</span>
                                    </button>
                                </th>
                                <th scope="col">Tender</th>
                                <th scope="col">By</th>
                                <th
                                    scope="col"
                                    class="num"
                                    :aria-sort="ariaSort('amount')"
                                >
                                    <button
                                        class="fin-sort"
                                        @click="toggleSort('amount')"
                                    >
                                        Amount
                                        <span>{{ sortMark('amount') }}</span>
                                    </button>
                                </th>
                                <th scope="col" class="num">Balance</th>
                                <th scope="col">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="tx in sortedTx"
                                :key="tx.id"
                                :class="{
                                    'is-editing': editingTx?.id === tx.id,
                                }"
                            >
                                <td class="fin-nowrap">
                                    {{ fmtDatetime(tx.transacted_at) }}
                                </td>
                                <td>
                                    <span
                                        class="fin-type-pill"
                                        :class="`type-${tx.type}`"
                                        >{{ typeLabel(tx.type) }}</span
                                    >
                                </td>
                                <td class="fin-desc">
                                    <strong>{{ tx.description }}</strong>
                                    <small
                                        v-if="
                                            tx.order?.customer_name || tx.notes
                                        "
                                        >{{
                                            [tx.order?.customer_name, tx.notes]
                                                .filter(Boolean)
                                                .join(' · ')
                                        }}</small
                                    >
                                </td>
                                <td>{{ tx.tender?.name ?? '—' }}</td>
                                <td class="fin-muted-cell">
                                    {{ tx.user?.name ?? '—' }}
                                </td>
                                <td
                                    class="num fin-strong"
                                    :class="
                                        isCredit(tx.type) ? 'is-in' : 'is-out'
                                    "
                                >
                                    {{ isCredit(tx.type) ? '+' : '−'
                                    }}{{ fmt(tx.amount) }}
                                </td>
                                <td class="num">
                                    {{ fmt(tx.financial_balance ?? 0) }}
                                </td>
                                <td class="fin-actions">
                                    <button
                                        v-if="tx.type !== 'order'"
                                        :aria-label="`Edit ${tx.description}`"
                                        @click="startEdit(tx)"
                                    >
                                        <Pencil :size="15" />
                                    </button>
                                    <button
                                        v-if="canDelete(tx)"
                                        class="is-danger"
                                        :disabled="ftDeleting === tx.id"
                                        :aria-label="`Delete ${tx.description}`"
                                        @click="deleteTransaction(tx)"
                                    >
                                        <Trash2 :size="15" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile cards -->
                <ul v-if="sortedTx.length" class="fin-mobile fin-tx-cards">
                    <li
                        v-for="tx in sortedTx"
                        :key="tx.id"
                        :class="{ 'is-editing': editingTx?.id === tx.id }"
                    >
                        <div class="fin-tx-top">
                            <span
                                class="fin-type-pill"
                                :class="`type-${tx.type}`"
                                >{{ typeLabel(tx.type) }}</span
                            >
                            <strong
                                :class="isCredit(tx.type) ? 'is-in' : 'is-out'"
                                >{{ isCredit(tx.type) ? '+' : '−'
                                }}{{ fmt(tx.amount) }}</strong
                            >
                        </div>
                        <p class="fin-tx-desc">{{ tx.description }}</p>
                        <p class="fin-tx-meta">
                            {{
                                [
                                    fmtDatetime(tx.transacted_at),
                                    tx.tender?.name,
                                    tx.order?.customer_name,
                                    tx.user?.name,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </p>
                        <div class="fin-tx-bottom">
                            <span
                                >Balance
                                {{ fmt(tx.financial_balance ?? 0) }}</span
                            >
                            <div class="fin-actions">
                                <button
                                    v-if="tx.type !== 'order'"
                                    :aria-label="`Edit ${tx.description}`"
                                    @click="startEdit(tx)"
                                >
                                    <Pencil :size="15" />
                                </button>
                                <button
                                    v-if="canDelete(tx)"
                                    class="is-danger"
                                    :disabled="ftDeleting === tx.id"
                                    :aria-label="`Delete ${tx.description}`"
                                    @click="deleteTransaction(tx)"
                                >
                                    <Trash2 :size="15" />
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>

                <div v-if="!sortedTx.length" class="fin-empty">
                    <Search :size="26" aria-hidden="true" />
                    <h3>
                        {{
                            ftSearch || activeFilters.length
                                ? 'No transactions match these filters.'
                                : 'No transactions in this period.'
                        }}
                    </h3>
                    <p v-if="activeFilters.length">
                        <button class="fin-text-link" @click="clearAllFilters">
                            Clear filters
                        </button>
                    </p>
                    <p v-else>
                        Choose another period above or record an entry.
                    </p>
                </div>

                <div v-if="ftMeta && ftMeta.last_page > 1" class="fin-pager">
                    <button
                        :disabled="ftPage === 1"
                        @click="loadFinancial(ftPage - 1)"
                    >
                        <ChevronLeft :size="15" aria-hidden="true" />Previous
                    </button>
                    <span>Page {{ ftPage }} of {{ ftMeta.last_page }}</span>
                    <button
                        :disabled="ftPage === ftMeta.last_page"
                        @click="loadFinancial(ftPage + 1)"
                    >
                        Next<ChevronRight :size="15" aria-hidden="true" />
                    </button>
                </div>
            </section>
        </div>

        <!-- ══ PERFORMANCE ═══════════════════════════════════════════════════ -->
        <div
            v-show="activeTab === 'performance'"
            role="tabpanel"
            class="fin-stack"
        >
            <p v-if="perfLoading" class="fin-panel fin-loading" role="status">
                Loading performance data…
            </p>
            <template v-else-if="ftSummary">
                <section v-if="periodHistory?.rows.length" class="fin-panel">
                    <div class="fin-panel-head">
                        <div>
                            <p class="fin-kicker">BALANCE CARRIED FORWARD</p>
                            <h2>{{ historyTitle }}</h2>
                        </div>
                        <small
                            >Each closing balance becomes the next opening
                            balance</small
                        >
                    </div>
                    <div
                        class="fin-table-scroll"
                        tabindex="0"
                        role="region"
                        aria-label="Balance carried forward by period"
                    >
                        <table class="fin-table fin-history">
                            <thead>
                                <tr>
                                    <th scope="col">Period</th>
                                    <th scope="col" class="num">Opening</th>
                                    <th scope="col" class="num">Money in</th>
                                    <th scope="col" class="num">Money out</th>
                                    <th scope="col">Net</th>
                                    <th scope="col" class="num">Closing</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(row, i) in periodHistory.rows"
                                    :key="row.start"
                                    :class="{ 'is-current': row.is_current }"
                                >
                                    <td>
                                        <strong>{{
                                            periodRowLabel(row)
                                        }}</strong
                                        ><small
                                            >{{ row.count }} txn{{
                                                row.count !== 1 ? 's' : ''
                                            }}{{
                                                row.is_current
                                                    ? ' · selected period'
                                                    : ''
                                            }}</small
                                        >
                                    </td>
                                    <td class="num fin-muted-cell">
                                        {{ fmt(row.opening) }}
                                    </td>
                                    <td class="num is-in">
                                        +{{ fmt(row.money_in) }}
                                    </td>
                                    <td class="num is-out">
                                        −{{ fmt(row.money_out) }}
                                    </td>
                                    <td class="fin-net-cell">
                                        <span
                                            class="fin-net-bar"
                                            :class="
                                                row.net >= 0
                                                    ? 'bar-in'
                                                    : 'bar-out'
                                            "
                                            :style="{
                                                width:
                                                    Math.max(
                                                        3,
                                                        (Math.abs(row.net) /
                                                            historyMaxNet) *
                                                            100,
                                                    ) + '%',
                                            }"
                                            aria-hidden="true"
                                        />
                                        <span
                                            class="fin-strong"
                                            :class="
                                                row.net >= 0
                                                    ? 'is-in'
                                                    : 'is-out'
                                            "
                                            >{{ row.net >= 0 ? '+' : ''
                                            }}{{ fmt(row.net) }}</span
                                        >
                                        <small
                                            v-if="i > 0"
                                            class="fin-vs-prev"
                                            :class="
                                                row.net >=
                                                periodHistory.rows[i - 1].net
                                                    ? 'is-in'
                                                    : 'is-out'
                                            "
                                            >{{
                                                row.net >=
                                                periodHistory.rows[i - 1].net
                                                    ? '▲'
                                                    : '▼'
                                            }}
                                            {{
                                                fmt(
                                                    Math.abs(
                                                        row.net -
                                                            periodHistory.rows[
                                                                i - 1
                                                            ].net,
                                                    ),
                                                )
                                            }}
                                            vs before</small
                                        >
                                    </td>
                                    <td class="num fin-strong">
                                        {{ fmt(row.closing) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="fin-muted fin-history-note">
                        Opening + money in − money out = closing. Balances cover
                        every tender and untagged entry{{
                            includeAssetDeductions
                                ? ''
                                : ', excluding asset deductions'
                        }}.
                    </p>
                </section>

                <section class="fin-panel">
                    <div class="fin-panel-head">
                        <div>
                            <p class="fin-kicker">VERSUS THE PERIOD BEFORE</p>
                            <h2>How this period compares</h2>
                        </div>
                        <button
                            class="fin-help"
                            :aria-expanded="showComparisonHelp"
                            aria-label="How the comparison works"
                            @click="showComparisonHelp = !showComparisonHelp"
                        >
                            ?
                        </button>
                    </div>
                    <div v-if="showComparisonHelp" class="fin-help-box">
                        <p>
                            <strong>This period</strong> is the range picked
                            above ({{ periodLabel }}).
                        </p>
                        <p>
                            <strong>Previous</strong> is the same number of days
                            right before it. For a single day, that's the day
                            before.
                        </p>
                        <p>
                            <strong>Green</strong> means better (more income or
                            less cost). <strong>Red</strong> means worse.
                        </p>
                    </div>
                    <div class="fin-compare-grid">
                        <article
                            v-for="row in comparisonRows"
                            :key="row.label"
                            class="fin-compare"
                            :class="`tone-${row.tone}`"
                        >
                            <p>{{ row.label }}</p>
                            <strong>{{ fmt(row.cur) }}</strong>
                            <span class="fin-delta">
                                {{
                                    row.tone === 'flat'
                                        ? 'No change'
                                        : `${row.sign}${fmt(Math.abs(row.change))}`
                                }}<template
                                    v-if="
                                        row.changePct !== null &&
                                        row.tone !== 'flat'
                                    "
                                >
                                    ({{ row.changePct > 0 ? '+' : ''
                                    }}{{ row.changePct }}%)</template
                                >
                            </span>
                            <small>Previous {{ fmt(row.prev) }}</small>
                        </article>
                    </div>
                </section>

                <section class="fin-panel">
                    <div class="fin-panel-head">
                        <div>
                            <p class="fin-kicker">LAST 30 DAYS</p>
                            <h2>Income, expenses &amp; balance</h2>
                        </div>
                        <div class="fin-legend">
                            <span><i class="dot-in" />Income</span>
                            <span><i class="dot-out" />Expenses</span>
                            <span><i class="dot-bal" />Balance</span>
                        </div>
                    </div>
                    <div v-if="lineChart" class="fin-chart">
                        <svg
                            :viewBox="`0 0 ${lineChart.VW} ${lineChart.VH}`"
                            role="img"
                            aria-label="Daily income, expenses and running balance for the last 30 days"
                            @mouseleave="hoveredDayIdx = null"
                            @touchstart="
                                onChartTouch($event as unknown as TouchEvent)
                            "
                            @touchmove.prevent="
                                onChartTouch($event as unknown as TouchEvent)
                            "
                        >
                            <line
                                v-for="tick in lineChart.yTicks"
                                :key="tick.y"
                                :x1="lineChart.padL"
                                :y1="tick.y"
                                :x2="lineChart.VW - lineChart.padR"
                                :y2="tick.y"
                                stroke="#24231e"
                                stroke-opacity="0.08"
                            />
                            <text
                                v-for="tick in lineChart.yTicks"
                                :key="`yl${tick.y}`"
                                :x="lineChart.padL - 6"
                                :y="tick.y + 3"
                                text-anchor="end"
                                fill="#777268"
                                font-size="9"
                            >
                                ₱{{ lineChart.shortFmt(tick.val) }}
                            </text>
                            <text
                                v-for="tick in lineChart.balTicks"
                                :key="`yr${tick.y}`"
                                :x="lineChart.VW - lineChart.padR + 6"
                                :y="tick.y + 3"
                                text-anchor="start"
                                fill="#24231e"
                                fill-opacity="0.6"
                                font-size="9"
                            >
                                {{ lineChart.shortFmt(tick.val) }}
                            </text>
                            <text
                                v-for="lbl in lineChart.xLabels"
                                :key="`xl${lbl.i}`"
                                :x="lbl.x"
                                :y="lineChart.VH - 8"
                                text-anchor="middle"
                                fill="#777268"
                                font-size="9"
                            >
                                {{ lbl.label }}
                            </text>
                            <path
                                :d="lineChart.area('income')"
                                fill="#5f8f4e"
                                fill-opacity="0.12"
                            />
                            <path
                                :d="lineChart.area('expense')"
                                fill="#ef5b2a"
                                fill-opacity="0.1"
                            />
                            <polyline
                                :points="lineChart.polyline('income')"
                                fill="none"
                                stroke="#5f8f4e"
                                stroke-width="2.2"
                                stroke-linejoin="round"
                                stroke-linecap="round"
                            />
                            <polyline
                                :points="lineChart.polyline('expense')"
                                fill="none"
                                stroke="#ef5b2a"
                                stroke-width="2.2"
                                stroke-linejoin="round"
                                stroke-linecap="round"
                            />
                            <polyline
                                :points="lineChart.balPolyline"
                                fill="none"
                                stroke="#24231e"
                                stroke-width="2"
                                stroke-linejoin="round"
                                stroke-linecap="round"
                                stroke-dasharray="5,4"
                            />
                            <rect
                                v-for="strip in lineChart.strips"
                                :key="strip.index"
                                :x="strip.x"
                                :y="lineChart.padT"
                                :width="strip.width"
                                :height="lineChart.H"
                                fill="transparent"
                                @mouseenter="hoveredDayIdx = strip.index"
                            />
                            <template
                                v-if="
                                    hoveredDayIdx !== null &&
                                    dailyData[hoveredDayIdx]
                                "
                            >
                                <line
                                    :x1="lineChart.xPos(hoveredDayIdx)"
                                    :y1="lineChart.padT"
                                    :x2="lineChart.xPos(hoveredDayIdx)"
                                    :y2="lineChart.padT + lineChart.H"
                                    stroke="#24231e"
                                    stroke-opacity="0.25"
                                    stroke-dasharray="3,3"
                                />
                                <circle
                                    :cx="lineChart.xPos(hoveredDayIdx)"
                                    :cy="
                                        lineChart.yPos(
                                            dailyData[hoveredDayIdx].income,
                                        )
                                    "
                                    r="4"
                                    fill="#5f8f4e"
                                />
                                <circle
                                    :cx="lineChart.xPos(hoveredDayIdx)"
                                    :cy="
                                        lineChart.yPos(
                                            dailyData[hoveredDayIdx].expense,
                                        )
                                    "
                                    r="4"
                                    fill="#ef5b2a"
                                />
                                <circle
                                    :cx="lineChart.xPos(hoveredDayIdx)"
                                    :cy="
                                        lineChart.yBal(
                                            dailyData[hoveredDayIdx].balance,
                                        )
                                    "
                                    r="4"
                                    fill="#24231e"
                                />
                            </template>
                        </svg>
                        <div class="fin-chart-readout" aria-live="polite">
                            <template
                                v-if="
                                    hoveredDayIdx !== null &&
                                    dailyData[hoveredDayIdx]
                                "
                            >
                                <strong>{{
                                    fmtDay(dailyData[hoveredDayIdx].date)
                                }}</strong>
                                <span class="is-in"
                                    >+{{
                                        fmt(dailyData[hoveredDayIdx].income)
                                    }}</span
                                >
                                <span class="is-out"
                                    >−{{
                                        fmt(dailyData[hoveredDayIdx].expense)
                                    }}</span
                                >
                                <span
                                    :class="
                                        dailyData[hoveredDayIdx].income -
                                            dailyData[hoveredDayIdx].expense >=
                                        0
                                            ? 'is-in'
                                            : 'is-out'
                                    "
                                    >Net
                                    {{
                                        fmt(
                                            dailyData[hoveredDayIdx].income -
                                                dailyData[hoveredDayIdx]
                                                    .expense,
                                        )
                                    }}</span
                                >
                                <span class="fin-strong"
                                    >Balance
                                    {{
                                        fmt(dailyData[hoveredDayIdx].balance)
                                    }}</span
                                >
                            </template>
                            <span v-else
                                >Hover or drag across the chart to read a
                                day</span
                            >
                        </div>
                    </div>
                    <p v-else class="fin-muted">No daily data available.</p>
                </section>
            </template>
        </div>

        <footer class="fin-footer">
            <span>BYPASS GRILL · GOOD FOOD. GOOD MOOD.</span
            ><span
                >Figures load for the period above. Select Refresh to
                update.</span
            >
        </footer>
    </div>

    <!-- Mobile: always-reachable record button -->
    <button class="fin-theme fin-fab" @click="openNewEntry()">
        <Plus :size="18" aria-hidden="true" />Record entry
    </button>

    <!-- ── Entry sheet (new + edit) ─────────────────────────────────────────── -->
    <Teleport to="body">
        <Transition name="fin-fade">
            <div
                v-if="sheet"
                class="fin-theme fin-sheet-backdrop"
                @click.self="closeSheet"
            >
                <FocusScope
                    as="div"
                    loop
                    trapped
                    class="fin-sheet"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="
                        sheet === 'new' ? 'Record entry' : 'Edit transaction'
                    "
                    @keydown.esc.stop.prevent="closeSheet"
                >
                    <header class="fin-sheet-head">
                        <div>
                            <p class="fin-kicker">
                                {{
                                    sheet === 'new'
                                        ? 'NEW ENTRY'
                                        : `EDIT #${editingTx?.id}`
                                }}
                            </p>
                            <h2>
                                {{
                                    sheet === 'new'
                                        ? 'Record entry'
                                        : `Edit ${typeLabel(editingTx?.type ?? '')}`
                                }}
                            </h2>
                        </div>
                        <button
                            class="fin-icon-btn"
                            aria-label="Close"
                            @click="closeSheet"
                        >
                            <X :size="18" />
                        </button>
                    </header>

                    <!-- New entry -->
                    <form
                        v-if="sheet === 'new'"
                        class="fin-form"
                        @submit.prevent="saveEntry"
                    >
                        <div
                            class="fin-segment"
                            role="radiogroup"
                            aria-label="Entry type"
                        >
                            <button
                                type="button"
                                role="radio"
                                :aria-checked="entryForm.type === 'expense'"
                                @click="entryForm.type = 'expense'"
                            >
                                <ArrowUpRight
                                    :size="15"
                                    aria-hidden="true"
                                />Expense<small>Money going out</small>
                            </button>
                            <button
                                type="button"
                                role="radio"
                                :aria-checked="
                                    entryForm.type === 'income_adjustment'
                                "
                                @click="entryForm.type = 'income_adjustment'"
                            >
                                <ArrowDownLeft
                                    :size="15"
                                    aria-hidden="true"
                                />Income adjustment<small
                                    >Money coming in</small
                                >
                            </button>
                        </div>
                        <label
                            >Amount
                            <span class="fin-money"
                                ><span aria-hidden="true">₱</span
                                ><input
                                    v-model="entryForm.amount"
                                    type="number"
                                    inputmode="decimal"
                                    min="0.01"
                                    step="0.01"
                                    placeholder="0.00"
                                    required
                            /></span>
                        </label>
                        <label
                            >Description<input
                                v-model="entryForm.description"
                                type="text"
                                placeholder="e.g. Charcoal, LPG refill, customer refund"
                                required
                        /></label>
                        <div class="fin-form-row">
                            <label
                                >Tender / account
                                <select
                                    v-model="entryForm.payment_tender_id"
                                    required
                                    :class="{
                                        'is-empty':
                                            !entryForm.payment_tender_id,
                                    }"
                                >
                                    <option :value="null" disabled>
                                        Select tender
                                    </option>
                                    <option
                                        v-for="t in tenders"
                                        :key="t.id"
                                        :value="t.id"
                                    >
                                        {{ t.name }}
                                    </option>
                                </select>
                            </label>
                            <label
                                >Date &amp; time<input
                                    v-model="entryForm.transacted_at"
                                    type="datetime-local"
                                    min="2000-01-01T00:00"
                                    max="2099-12-31T23:59"
                                    required
                            /></label>
                        </div>
                        <label
                            >Notes <span class="fin-optional">(optional)</span
                            ><input
                                v-model="entryForm.notes"
                                type="text"
                                placeholder="Receipt number or reference"
                        /></label>
                        <footer class="fin-sheet-foot">
                            <button
                                type="button"
                                class="fin-ghost-btn"
                                @click="closeSheet"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="fin-primary-btn"
                                :disabled="entrySaving || !entryValid"
                            >
                                {{
                                    entrySaving
                                        ? 'Saving…'
                                        : `Record ${entryForm.type === 'expense' ? 'expense' : 'income'}${Number(entryForm.amount) > 0 ? ' · ' + fmt(entryForm.amount) : ''}`
                                }}
                            </button>
                        </footer>
                    </form>

                    <!-- Edit -->
                    <form v-else class="fin-form" @submit.prevent="saveEdit">
                        <label v-if="editingTx?.type !== 'payment'"
                            >Type
                            <select v-model="editForm.type">
                                <option value="expense">Expense</option>
                                <option value="income_adjustment">
                                    Income Adjustment
                                </option>
                                <option value="asset_deduction">
                                    Asset Deduction
                                </option>
                                <option value="payroll">Payroll</option>
                                <option value="payout_share">
                                    Payout Share
                                </option>
                            </select>
                        </label>
                        <label
                            >Amount
                            <span class="fin-money"
                                ><span aria-hidden="true">₱</span
                                ><input
                                    v-model="editForm.amount"
                                    type="number"
                                    inputmode="decimal"
                                    min="0.01"
                                    step="0.01"
                                    required
                            /></span>
                        </label>
                        <label
                            >Description<input
                                v-model="editForm.description"
                                type="text"
                                required
                        /></label>
                        <div class="fin-form-row">
                            <label
                                >Tender / account
                                <select v-model="editForm.payment_tender_id">
                                    <option :value="null">Not tagged</option>
                                    <option
                                        v-if="
                                            editingTx?.tender &&
                                            !tenders.some(
                                                (t) =>
                                                    t.id ===
                                                    editingTx!.tender!.id,
                                            )
                                        "
                                        :value="editingTx.tender.id"
                                    >
                                        {{ editingTx.tender.name }}
                                    </option>
                                    <option
                                        v-for="t in tenders"
                                        :key="t.id"
                                        :value="t.id"
                                    >
                                        {{ t.name }}
                                    </option>
                                </select>
                            </label>
                            <label
                                >Date &amp; time<input
                                    v-model="editForm.transacted_at"
                                    type="datetime-local"
                                    min="2000-01-01T00:00"
                                    max="2099-12-31T23:59"
                            /></label>
                        </div>
                        <label
                            >Notes <span class="fin-optional">(optional)</span
                            ><input
                                v-model="editForm.notes"
                                type="text"
                                placeholder="Receipt number or reference"
                        /></label>
                        <footer class="fin-sheet-foot">
                            <button
                                v-if="editingTx && canDelete(editingTx)"
                                type="button"
                                class="fin-danger-btn"
                                :disabled="ftDeleting === editingTx.id"
                                @click="deleteTransaction(editingTx)"
                            >
                                <Trash2 :size="15" aria-hidden="true" />Delete
                            </button>
                            <button
                                type="button"
                                class="fin-ghost-btn"
                                @click="closeSheet"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="fin-primary-btn"
                                :disabled="
                                    editSaving ||
                                    !editForm.description.trim() ||
                                    !(Number(editForm.amount) > 0)
                                "
                            >
                                {{ editSaving ? 'Saving…' : 'Save changes' }}
                            </button>
                        </footer>
                    </form>
                </FocusScope>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
/* Welcome-page palette: ink, cream and grill orange. */
.fin-theme {
    --ink: #24231e;
    --cream: #f6f2e9;
    --paper: #fffcf6;
    --orange: #ef5b2a;
    --orange-deep: #c3441c;
    --line: #ded7cb;
    --line-soft: #ece5da;
    --muted: #68665f;
    --in: #3f7a33;
    --out: #c0391b;
    color: var(--ink);
    color-scheme: light;
    font-family: Arial, Helvetica, sans-serif;
}
.fin-theme :focus-visible {
    outline: 2px solid var(--orange-deep);
    outline-offset: 2px;
}
.fin-theme button:not(:disabled) {
    cursor: pointer;
}
.fin-page {
    display: flex;
    flex-direction: column;
    gap: 20px;
    min-height: 100%;
    padding: 32px clamp(16px, 3vw, 44px) 96px;
    background: var(--cream);
}
.is-in {
    color: var(--in);
}
.is-out {
    color: var(--out);
}
.fin-strong {
    font-weight: 800;
}
.fin-muted {
    font-size: 12px;
    line-height: 1.7;
    color: var(--muted);
}

/* Heading */
.fin-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: 18px 24px;
}
.fin-eyebrow {
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 2px;
}
.fin-eyebrow > span {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--orange);
}
.fin-heading h1 {
    margin: 14px 0 10px;
    font-family: Impact, 'Arial Narrow', sans-serif;
    font-size: clamp(44px, 6vw, 78px);
    font-weight: 900;
    line-height: 0.92;
    letter-spacing: -1px;
}
.fin-heading h1 span {
    color: var(--orange);
}
.fin-intro {
    max-width: 420px;
    font-size: 14px;
    line-height: 1.7;
    color: var(--muted);
}
.fin-heading-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

/* Buttons */
.fin-primary-btn,
.fin-ghost-btn,
.fin-danger-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 4px;
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 800;
    white-space: nowrap;
    transition:
        background 0.15s,
        border-color 0.15s;
}
.fin-primary-btn {
    background: var(--orange);
    color: #fff;
}
.fin-primary-btn:hover:not(:disabled) {
    background: var(--orange-deep);
}
.fin-ghost-btn {
    border: 1px solid #d4cdbf;
    background: var(--paper);
    color: var(--ink);
}
.fin-ghost-btn:hover:not(:disabled) {
    background: #efeadf;
}
.fin-danger-btn {
    margin-right: auto;
    border: 1px solid #edc4b7;
    background: #fbe9e4;
    color: var(--out);
}
.fin-danger-btn:hover:not(:disabled) {
    background: #f7d9d0;
}
.fin-primary-btn:disabled,
.fin-ghost-btn:disabled,
.fin-danger-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.fin-icon-btn {
    display: grid;
    place-items: center;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    color: var(--muted);
}
.fin-icon-btn:hover {
    background: #efeadf;
    color: var(--ink);
}
.fin-text-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 800;
    color: var(--orange-deep);
}
.fin-text-link:hover {
    text-decoration: underline;
    text-underline-offset: 4px;
}

/* Period bar */
.fin-period {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 16px 18px;
    border-radius: 6px;
    background: var(--ink);
    color: var(--cream);
}
.fin-period-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px 16px;
}
.fin-period-label {
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: 15px;
}
.fin-period-label small {
    border-radius: 20px;
    padding: 2px 8px;
    background: #ffffff1a;
    color: #c3bfb3;
    font-size: 10px;
    font-weight: 700;
}
.fin-presets {
    display: flex;
    gap: 6px;
    overflow-x: auto;
    padding-bottom: 2px;
    scrollbar-width: none;
}
.fin-presets button {
    flex-shrink: 0;
    border: 1px solid #ffffff33;
    border-radius: 20px;
    padding: 7px 14px;
    font-size: 12px;
    font-weight: 700;
    color: #e4dfd3;
    transition:
        background 0.15s,
        color 0.15s;
}
.fin-presets button:hover {
    background: #ffffff14;
}
.fin-presets button[aria-pressed='true'] {
    border-color: var(--orange);
    background: var(--orange);
    color: #fff;
}
.fin-custom-range {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.fin-custom-range label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    font-weight: 700;
    color: #c3bfb3;
}
.fin-custom-range input {
    border: 1px solid #ffffff40;
    border-radius: 4px;
    padding: 7px 10px;
    background: #ffffff10;
    color: var(--cream);
    color-scheme: dark;
    font-size: 13px;
}
.fin-switch {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    font-size: 11px;
    font-weight: 700;
    color: #c3bfb3;
    cursor: pointer;
}
.fin-switch input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.fin-switch-track {
    position: relative;
    width: 34px;
    height: 20px;
    border-radius: 20px;
    background: #ffffff33;
    transition: background 0.15s;
}
.fin-switch-track span {
    position: absolute;
    top: 3px;
    left: 3px;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: #fff;
    transition: transform 0.15s;
}
.fin-switch input:checked + .fin-switch-track {
    background: var(--orange);
}
.fin-switch input:checked + .fin-switch-track span {
    transform: translateX(14px);
}
.fin-switch input:focus-visible + .fin-switch-track {
    outline: 2px solid var(--orange);
    outline-offset: 2px;
}

/* KPIs */
.fin-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}
.fin-kpi {
    min-width: 0;
    padding: 18px;
    border: 1px solid var(--line);
    border-radius: 6px;
    background: var(--paper);
}
.fin-kpi p {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    color: var(--muted);
}
.fin-kpi strong {
    display: block;
    margin: 10px 0 6px;
    font-size: clamp(20px, 2.2vw, 28px);
    letter-spacing: -1px;
    line-height: 1.2;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}
.fin-kpi > span {
    font-size: 10px;
    line-height: 1.5;
    color: #777268;
}
.fin-kpi.is-surplus {
    border-color: #b9cfae;
    background: #eef4ea;
}
.fin-kpi.is-surplus strong {
    color: var(--in);
}
.fin-kpi.is-deficit {
    border-color: #edc4b7;
    background: #fbe9e4;
}
.fin-kpi.is-deficit strong {
    color: var(--out);
}
.fin-kpi-dark {
    border-color: var(--ink);
    background: var(--ink);
    color: var(--cream);
}
.fin-kpi-dark p,
.fin-kpi-dark > span {
    color: #c3bfb3;
}
.fin-loading {
    grid-column: 1 / -1;
    font-size: 13px;
    color: var(--muted);
}

/* Tabs */
.fin-tabs {
    position: sticky;
    top: 0;
    z-index: 5;
    display: flex;
    gap: 4px;
    width: max-content;
    max-width: 100%;
    overflow-x: auto;
    padding: 4px;
    border-radius: 6px;
    background: #ebe5d8;
}
.fin-tabs button {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border-radius: 4px;
    padding: 9px 16px;
    font-size: 12px;
    font-weight: 800;
    color: var(--muted);
    white-space: nowrap;
}
.fin-tabs button:hover {
    color: var(--ink);
}
.fin-tabs button[aria-selected='true'] {
    background: var(--paper);
    color: var(--orange-deep);
    box-shadow: 0 1px 2px #24231e1a;
}
.fin-tab-count {
    border-radius: 20px;
    padding: 1px 7px;
    background: #efeadf;
    color: var(--muted);
    font-size: 10px;
}

/* Panels */
.fin-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}
.fin-span-2 {
    grid-column: span 2;
}
.fin-stack {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.fin-panel {
    min-width: 0;
    padding: 20px;
    border: 1px solid var(--line);
    border-radius: 6px;
    background: var(--paper);
}
.fin-panel-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 8px 14px;
    margin-bottom: 16px;
}
.fin-panel-head h2 {
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -0.4px;
}
.fin-panel-head > small {
    font-size: 10px;
    color: #777268;
    padding-top: 4px;
}
.fin-kicker {
    margin-bottom: 5px;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 1.6px;
    color: var(--orange-deep);
}
.fin-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 30px 12px;
    border: 1px dashed #d4cdbf;
    border-radius: 6px;
    text-align: center;
    color: #777268;
}
.fin-empty > svg {
    color: var(--orange);
}
.fin-empty h3 {
    font-size: 15px;
    font-weight: 800;
    color: var(--ink);
}
.fin-empty p {
    font-size: 12px;
}

/* Dots / bars */
.dot-in,
.dot-out,
.dot-bal {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 2px;
    flex-shrink: 0;
}
.dot-in {
    background: #5f8f4e;
}
.dot-out {
    background: var(--orange);
}
.dot-bal {
    background: var(--ink);
}
.bar-in {
    background: #5f8f4e;
}
.bar-out {
    background: var(--orange);
}

/* Money by type */
.fin-type-list {
    list-style: none;
    margin: 0;
    padding: 0;
}
.fin-type-list li + li {
    border-top: 1px solid var(--line-soft);
}
.fin-type-list button {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px 16px;
    width: 100%;
    padding: 12px 8px;
    border-radius: 4px;
    text-align: left;
    transition: background 0.15s;
}
.fin-type-list button:hover {
    background: #f7f1e6;
}
.fin-type-name {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 4px 8px;
    font-size: 13px;
    font-weight: 800;
}
.fin-type-name small {
    font-size: 10px;
    font-weight: 400;
    color: #777268;
}
.fin-type-amount {
    font-size: 14px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}
.fin-bar {
    grid-column: 1 / -1;
    height: 6px;
    border-radius: 3px;
    background: #efeadf;
    overflow: hidden;
}
.fin-bar > span {
    display: block;
    height: 100%;
    border-radius: 3px;
    transition: width 0.4s;
}

/* Lists */
.fin-list {
    font-size: 12px;
}
.fin-list > div {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 9px 0;
    border-bottom: 1px solid var(--line-soft);
}
.fin-list dt {
    display: flex;
    align-items: center;
    gap: 7px;
    color: var(--muted);
}
.fin-list dt small {
    border-radius: 20px;
    padding: 0 6px;
    background: #efeadf;
    font-size: 10px;
}
.fin-list dd {
    font-weight: 800;
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.fin-list-total {
    border-bottom: 0 !important;
}
.fin-list-total dt {
    color: var(--ink);
    font-weight: 800;
}
.fin-flow-bars {
    display: flex;
    gap: 3px;
    height: 14px;
    margin-bottom: 14px;
    border-radius: 3px;
    overflow: hidden;
    background: #efeadf;
}
.fin-flow-bars span {
    flex-basis: 0;
    transition: flex-grow 0.4s;
}
.fin-bills {
    background: #ebe8d3;
    border-color: #d8d3b4;
}
.fin-bills-total {
    margin-bottom: 10px;
    font-size: 28px;
    font-weight: 800;
    letter-spacing: -1px;
    font-variant-numeric: tabular-nums;
}
.fin-bills .fin-list {
    margin-bottom: 14px;
}

/* Tables */
.fin-table-scroll {
    overflow-x: auto;
}
.fin-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    white-space: nowrap;
}
.fin-table th {
    padding: 0 12px 11px 0;
    text-align: left;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #777268;
}
.fin-table td {
    padding: 12px 12px 12px 0;
    border-top: 1px solid var(--line-soft);
    vertical-align: middle;
}
.fin-table td small {
    display: block;
    margin-top: 3px;
    font-size: 10px;
    color: #777268;
}
.fin-table tfoot td {
    border-top: 1px solid var(--line);
    font-weight: 800;
}
.fin-table .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.fin-table tbody tr {
    transition: background 0.15s;
}
.fin-table tbody tr:hover {
    background: #f7f1e6;
}
.fin-table tr.is-editing {
    background: #f4dfcf;
}
.fin-nowrap {
    white-space: nowrap;
}
.fin-desc {
    white-space: normal;
    min-width: 200px;
}
.fin-desc strong {
    font-weight: 700;
}
.fin-muted-cell {
    color: #777268;
}
.fin-sort {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font: inherit;
    letter-spacing: inherit;
    text-transform: inherit;
    color: inherit;
}
.fin-sort span {
    opacity: 0.6;
}
.fin-sort:hover {
    color: var(--ink);
}
.fin-row-link {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    font-size: 11px;
    font-weight: 800;
    color: var(--orange-deep);
}
.fin-actions {
    display: flex;
    justify-content: flex-end;
    gap: 4px;
}
.fin-actions button {
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    border-radius: 4px;
    color: var(--muted);
}
.fin-actions button:hover {
    background: #efeadf;
    color: var(--ink);
}
.fin-actions button.is-danger:hover {
    background: #fbe9e4;
    color: var(--out);
}
.fin-actions button:disabled {
    opacity: 0.4;
}

/* Type pills */
.fin-type-pill {
    display: inline-block;
    border-radius: 20px;
    padding: 3px 9px;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
    background: #efeadf;
    color: #625b4c;
}
.type-payment {
    background: #e4edde;
    color: #376229;
}
.type-income_adjustment {
    background: #dfeee9;
    color: #2b6a57;
}
.type-expense {
    background: #fbe4dc;
    color: #a8391a;
}
.type-payroll {
    background: #ece4f3;
    color: #5d3f7a;
}
.type-asset_deduction {
    background: #f7eccf;
    color: #7b5815;
}
.type-payout_share {
    background: #f8e2d7;
    color: #9d401d;
}
.type-order {
    background: #e2e8f0;
    color: #3d4b5e;
}

/* Ledger toolbar */
.fin-toolbar {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 14px;
}
.fin-toolbar-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.fin-search {
    position: relative;
    flex: 1 1 260px;
    display: flex;
    align-items: center;
}
.fin-search svg {
    position: absolute;
    left: 12px;
    color: #93897b;
    pointer-events: none;
}
.fin-search input,
.fin-select {
    width: 100%;
    height: 42px;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    background: #fff;
    font-size: 13px;
    color: var(--ink);
}
.fin-search input {
    padding: 0 12px 0 36px;
}
.fin-select {
    flex: 0 1 200px;
    padding: 0 10px;
}
.fin-search input:focus,
.fin-select:focus {
    outline: none;
    border-color: var(--orange-deep);
    box-shadow: 0 0 0 3px #c3441c26;
}
.fin-chips {
    display: flex;
    gap: 6px;
    overflow-x: auto;
    scrollbar-width: none;
}
.fin-chips button {
    flex-shrink: 0;
    border-radius: 4px;
    padding: 7px 12px;
    background: #efeadf;
    font-size: 12px;
    font-weight: 700;
    color: #575144;
}
.fin-chips button:hover {
    background: #e4dccd;
    color: var(--ink);
}
.fin-chips button[aria-pressed='true'] {
    background: var(--ink);
    color: var(--cream);
}
.fin-active-filters {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    font-size: 11px;
    color: #777268;
}
.fin-active-filters button {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid #e4c4ab;
    border-radius: 20px;
    padding: 3px 9px;
    background: #f2e5d8;
    font-weight: 700;
    color: #a23817;
}
.fin-active-filters .fin-clear-all {
    border: 0;
    background: none;
    text-decoration: underline;
    color: var(--muted);
}

/* Mobile ledger cards */
.fin-mobile {
    display: none;
}
.fin-tx-cards {
    list-style: none;
    margin: 0;
    padding: 0;
}
.fin-tx-cards li {
    padding: 14px 4px;
    border-top: 1px solid var(--line-soft);
}
.fin-tx-cards li.is-editing {
    background: #f4dfcf;
}
.fin-tx-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}
.fin-tx-top strong {
    font-size: 15px;
    font-variant-numeric: tabular-nums;
}
.fin-tx-desc {
    margin-top: 7px;
    font-size: 13px;
    font-weight: 700;
}
.fin-tx-meta {
    margin-top: 3px;
    font-size: 11px;
    color: #777268;
}
.fin-tx-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 6px;
    font-size: 11px;
    color: #777268;
    font-variant-numeric: tabular-nums;
}

.fin-pager {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-top: 12px;
    padding-top: 14px;
    border-top: 1px solid var(--line-soft);
    font-size: 12px;
    color: var(--muted);
}
.fin-pager button {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    padding: 8px 12px;
    background: var(--paper);
    font-size: 11px;
    font-weight: 800;
    color: var(--ink);
}
.fin-pager button:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

/* Performance */
.fin-help {
    width: 28px;
    height: 28px;
    border: 1px solid #d4cdbf;
    border-radius: 50%;
    font-size: 12px;
    font-weight: 800;
    color: var(--muted);
}
.fin-help[aria-expanded='true'] {
    background: var(--ink);
    color: var(--cream);
}
.fin-help-box {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 16px;
    padding: 12px 14px;
    border-radius: 5px;
    background: #f6f2e9;
    font-size: 12px;
    line-height: 1.6;
}
.fin-compare-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}
.fin-compare {
    min-width: 0;
    padding: 14px;
    border: 1px solid var(--line);
    border-top-width: 3px;
    border-radius: 5px;
    background: #fff;
}
.fin-compare p {
    font-size: 11px;
    font-weight: 700;
    color: var(--muted);
}
.fin-compare strong {
    display: block;
    margin: 8px 0 4px;
    font-size: 20px;
    letter-spacing: -0.6px;
    font-variant-numeric: tabular-nums;
}
.fin-compare small {
    display: block;
    margin-top: 4px;
    font-size: 10px;
    color: #777268;
}
.fin-delta {
    font-size: 12px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}
.tone-good {
    border-top-color: #6f9a5d;
}
.tone-good .fin-delta {
    color: var(--in);
}
.tone-bad {
    border-top-color: var(--orange);
}
.tone-bad .fin-delta {
    color: var(--out);
}
.tone-flat {
    border-top-color: #d4cdbf;
}
.tone-flat .fin-delta {
    color: #777268;
}
.fin-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    font-size: 11px;
    color: var(--muted);
}
.fin-legend span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.fin-chart svg {
    display: block;
    width: 100%;
    height: 220px;
    touch-action: pan-y;
}
.fin-chart-readout {
    display: flex;
    justify-content: center;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px 16px;
    min-height: 44px;
    margin-top: 4px;
    padding-top: 10px;
    border-top: 1px solid var(--line-soft);
    font-size: 12px;
    color: #777268;
    font-variant-numeric: tabular-nums;
}
.fin-chart-readout strong {
    color: var(--ink);
}

/* Balance forward */
.fin-kpi b {
    font-weight: 800;
}
.fin-kpi-dark .net-up {
    color: #a9d894;
}
.fin-kpi-dark .net-down {
    color: #ffa684;
}
@media (min-width: 1101px) {
    .fin-kpi[data-op] {
        position: relative;
    }
    .fin-kpi[data-op]::before {
        content: attr(data-op);
        position: absolute;
        top: 50%;
        left: -18px;
        z-index: 1;
        display: grid;
        place-items: center;
        width: 24px;
        height: 24px;
        border: 1px solid var(--line);
        border-radius: 50%;
        background: var(--cream);
        color: var(--ink);
        font-size: 14px;
        font-weight: 800;
        line-height: 1;
        transform: translateY(-50%);
    }
}
.fin-history tr.is-current {
    background: #f4dfcf;
}
.fin-history tr.is-current td:first-child {
    box-shadow: inset 3px 0 0 var(--orange);
    padding-left: 10px;
}
.fin-net-cell {
    min-width: 170px;
}
.fin-net-bar {
    display: block;
    height: 5px;
    margin-bottom: 5px;
    border-radius: 3px;
}
.fin-vs-prev {
    font-weight: 700;
}
.fin-history-note {
    margin-top: 12px;
}

.fin-footer {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px 15px;
    margin-top: 8px;
    padding-top: 18px;
    border-top: 1px solid var(--line);
    font-size: 9px;
    color: #777268;
}
.fin-footer > span:first-child {
    font-weight: 700;
    letter-spacing: 1px;
}

/* Mobile record button */
.fin-fab {
    position: fixed;
    right: 16px;
    bottom: 16px;
    left: 16px;
    z-index: 30;
    display: none;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 6px;
    padding: 14px;
    background: var(--orange);
    box-shadow: 0 12px 28px -12px #24231ecc;
    font-size: 14px;
    font-weight: 800;
    color: #fff;
}

/* Entry sheet */
.fin-sheet-backdrop {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    justify-content: flex-end;
    background: #24231e80;
}
.fin-sheet {
    display: flex;
    flex-direction: column;
    width: min(460px, 100vw);
    height: 100%;
    overflow-y: auto;
    background: var(--paper);
    box-shadow: -20px 0 40px -20px #24231e80;
    animation: fin-slide-in 0.22s ease-out;
}
.fin-sheet-head {
    position: sticky;
    top: 0;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    padding: 20px 22px 16px;
    border-bottom: 1px solid var(--line-soft);
    background: var(--paper);
}
.fin-sheet-head h2 {
    font-size: 20px;
    font-weight: 800;
    letter-spacing: -0.4px;
}
.fin-form {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 14px;
    padding: 20px 22px;
}
.fin-form label {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
}
.fin-form input,
.fin-form select {
    width: 100%;
    height: 42px;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    padding: 0 12px;
    background: #fff;
    font-size: 14px;
    font-weight: 400;
    color: var(--ink);
}
.fin-form input:focus,
.fin-form select:focus {
    outline: none;
    border-color: var(--orange-deep);
    box-shadow: 0 0 0 3px #c3441c26;
}
.fin-form select.is-empty {
    color: #93897b;
}
.fin-optional {
    font-weight: 400;
    color: #777268;
}
.fin-form-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.fin-money {
    display: flex;
    align-items: center;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    background: #fff;
}
.fin-money:focus-within {
    border-color: var(--orange-deep);
    box-shadow: 0 0 0 3px #c3441c26;
}
.fin-money > span {
    padding-left: 12px;
    font-size: 18px;
    font-weight: 800;
    color: #93897b;
}
.fin-form .fin-money input {
    height: 52px;
    border: 0;
    padding-left: 6px;
    font-size: 22px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    box-shadow: none;
}
.fin-segment {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}
.fin-segment button {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 3px;
    border: 1px solid #d4cdbf;
    border-radius: 5px;
    padding: 12px;
    background: #fff;
    text-align: left;
    font-size: 13px;
    font-weight: 800;
    color: var(--ink);
}
.fin-segment button small {
    font-size: 10px;
    font-weight: 400;
    color: #777268;
}
.fin-segment button[aria-checked='true'] {
    border-color: var(--orange);
    background: #fdf0e9;
    box-shadow: inset 0 0 0 1px var(--orange);
}
.fin-segment button[aria-checked='true'] svg {
    color: var(--orange-deep);
}
.fin-sheet-foot {
    display: flex;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: auto;
    padding-top: 16px;
    border-top: 1px solid var(--line-soft);
}

.spinning {
    animation: fin-spin 1s linear infinite;
}
@keyframes fin-spin {
    to {
        transform: rotate(360deg);
    }
}
@keyframes fin-slide-in {
    from {
        transform: translateX(40px);
        opacity: 0;
    }
    to {
        transform: none;
        opacity: 1;
    }
}
.fin-fade-enter-active,
.fin-fade-leave-active {
    transition: opacity 0.15s;
}
.fin-fade-enter-from,
.fin-fade-leave-to {
    opacity: 0;
}

@media (max-width: 1100px) {
    .fin-kpis {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .fin-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .fin-compare-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 767px) {
    .fin-desktop {
        display: none;
    }
    .fin-mobile {
        display: block;
    }
    .fin-grid {
        grid-template-columns: 1fr;
    }
    .fin-span-2 {
        grid-column: auto;
    }
    .fin-fab {
        display: flex;
    }
    .fin-heading-actions .fin-primary-btn {
        display: none;
    }
}
@media (max-width: 540px) {
    .fin-page {
        padding: 24px 16px 96px;
        gap: 16px;
    }
    .fin-kpis {
        gap: 10px;
    }
    .fin-kpi {
        padding: 14px;
    }
    .fin-panel {
        padding: 16px;
    }
    .fin-tabs {
        width: 100%;
    }
    .fin-tabs button {
        flex: 1;
        justify-content: center;
        padding: 9px 8px;
    }
    .fin-form-row {
        grid-template-columns: 1fr;
    }
    .fin-compare-grid {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    .fin-compare {
        padding: 12px;
    }
    .fin-compare strong {
        font-size: 16px;
    }
    .fin-sheet-foot > button {
        flex: 1;
    }
    .fin-danger-btn {
        flex-basis: 100%;
    }
}
@media (prefers-reduced-motion: reduce) {
    .spinning,
    .fin-sheet {
        animation: none;
    }
    .fin-bar > span,
    .fin-flow-bars span {
        transition: none;
    }
}
</style>
