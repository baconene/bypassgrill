<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    PieChart,
    Users,
    History,
    TrendingUp,
    RefreshCw,
    Plus,
    Trash2,
    Pencil,
    Download,
    Save,
    X,
    HelpCircle,
    Gift,
    Package,
    Banknote,
    CheckCircle2,
} from 'lucide-vue-next';
import { FocusScope } from 'reka-ui';
import { ref, computed, onMounted, watch } from 'vue';
import { toast } from 'vue-sonner';
import api from '@/utils/api';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Profit Sharing', href: '/distribution' },
        ],
    },
});

defineProps<{
    categories: { id: number; name: string }[];
    products: { id: number; name: string; category_id: number }[];
    users: { id: number; name: string }[];
}>();

// ── Shared filters ────────────────────────────────────────────────────────
// Business dates must not move to the previous day when converted to UTC.
const today = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
}).format(new Date());
const monthStart = today.slice(0, 7) + '-01';
const dateKey = (d: Date) =>
    [
        d.getFullYear(),
        String(d.getMonth() + 1).padStart(2, '0'),
        String(d.getDate()).padStart(2, '0'),
    ].join('-');
const basis = ref<'sales' | 'profit'>('sales');
const startDate = ref(monthStart);
const endDate = ref(today);
const categoryId = ref<number | ''>('');
const productId = ref<number | ''>('');

const subTab = ref<
    | 'distribution'
    | 'shareholders'
    | 'incentives'
    | 'trends'
    | 'history'
    | 'help'
>('distribution');

const fmt = (v: number | null | undefined) =>
    '₱' +
    Number(v ?? 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

// ── Distribution preview ─────────────────────────────────────────────────────
const result = ref<any>(null);
const loading = ref(false);
const saving = ref(false);
const previewKey = ref('');
let previewRequest = 0;
const previewCurrent = computed(
    () => !!result.value && previewKey.value === JSON.stringify(params()),
);
const canSave = computed(
    () =>
        previewCurrent.value &&
        result.value?.can_snapshot &&
        !loading.value &&
        !saving.value,
);

const params = () => ({
    basis: basis.value,
    start_date: startDate.value,
    end_date: endDate.value,
    category_id: categoryId.value || undefined,
    product_id: productId.value || undefined,
});

const loadPreview = async () => {
    if (!startDate.value || !endDate.value || endDate.value < startDate.value) {
        toast.error(
            'Choose a valid date range. The end date must be on or after the start date.',
        );

        return;
    }

    const request = ++previewRequest;
    const key = JSON.stringify(params());
    previewKey.value = '';
    loading.value = true;

    try {
        const res = await api.get('/api/v1/distribution/preview', {
            params: params(),
        });

        if (request !== previewRequest) {
            return;
        }

        result.value = res.data;
        previewKey.value = key;
    } catch (err: any) {
        toast.error(
            err.response?.data?.message ?? 'Failed to compute distribution',
        );
    } finally {
        if (request === previewRequest) {
            loading.value = false;
        }
    }
};

const setMonth = () => {
    startDate.value = monthStart;
    endDate.value = today;
    loadPreview();
};
const setQuarter = () => {
    startDate.value = dateKey(
        new Date(
            Number(today.slice(0, 4)),
            Math.floor((Number(today.slice(5, 7)) - 1) / 3) * 3,
            1,
        ),
    );
    endDate.value = today;
    loadPreview();
};
const setYear = () => {
    startDate.value = today.slice(0, 4) + '-01-01';
    endDate.value = today;
    loadPreview();
};
const setWeek = () => {
    const d = new Date(today + 'T12:00:00');
    const day = d.getDay() || 7;
    d.setDate(d.getDate() - day + 1);
    startDate.value = dateKey(d);
    endDate.value = today;
    loadPreview();
};

const exportCsv = () => {
    const qs = new URLSearchParams(
        Object.entries(params())
            .filter(([, v]) => v !== undefined)
            .map(([k, v]) => [k, String(v)]),
    ).toString();
    window.open(`/api/v1/distribution/export?${qs}`, '_blank');
};
const saveSnapshot = async () => {
    if (!canSave.value) {
        return;
    }

    saving.value = true;

    try {
        await api.post('/api/v1/distribution/snapshots', params());
        toast.success('Snapshot saved to history');
        subTab.value = 'history';
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to save snapshot');
    } finally {
        saving.value = false;
    }
};

// ── Combined summary (dividend + incentive) ──────────────────────────────────
const combinedSummary = computed(() => {
    if (!result.value) {
        return [];
    }

    const map: Record<number, number> = {};

    for (const s of result.value.incentive?.by_shareholder ?? []) {
        map[s.shareholder_id] = s.incentive_amount ?? 0;
    }

    const members = [...(result.value.members ?? [])];

    for (const inc of result.value.incentive?.by_shareholder ?? []) {
        if (!members.some((m) => m.shareholder_id === inc.shareholder_id)) {
            members.push({
                shareholder_id: inc.shareholder_id,
                name: inc.name,
                percentage: 0,
                amount: 0,
            });
        }
    }

    return members.map((m: any) => ({
        ...m,
        incentive_amount: map[m.shareholder_id] ?? 0,
        total_amount:
            Math.round(((m.amount ?? 0) + (map[m.shareholder_id] ?? 0)) * 100) /
            100,
    }));
});

// ── Pie chart — Ownership Dividend ───────────────────────────────────────────
const pieSeries = computed(() =>
    (result.value?.chart ?? []).map((c: any) => c.value),
);
const pieOptions = computed(() => ({
    chart: {
        type: 'pie',
        fontFamily: 'Arial, sans-serif',
        foreColor: '#68665f',
        animations: { enabled: false },
    },
    labels: (result.value?.chart ?? []).map((c: any) => c.label),
    legend: { position: 'bottom' },
    colors: [
        '#c3441c',
        '#24231e',
        '#71815b',
        '#d39a42',
        '#956953',
        '#a7a17b',
        '#76746e',
    ],
    dataLabels: { formatter: (val: number) => val.toFixed(1) + '%' },
    tooltip: {
        y: {
            formatter: (val: number) =>
                '₱' + val.toLocaleString('en-PH', { minimumFractionDigits: 2 }),
        },
    },
}));

// ── Pie chart — Product Incentive Distribution ────────────────────────────────
const incentivePieData = computed(() => {
    const inc = result.value?.incentive;

    if (!inc || (!inc.by_shareholder?.length && !(inc.company_retained > 0))) {
        return null;
    }

    const slices = [
        ...(inc.by_shareholder ?? []).map((s: any) => ({
            label: s.name,
            value: s.incentive_amount,
        })),
        ...(inc.company_retained > 0
            ? [{ label: 'Company (unowned)', value: inc.company_retained }]
            : []),
    ];

    return slices;
});
const incentivePieSeries = computed(
    () => incentivePieData.value?.map((s) => s.value) ?? [],
);
const incentivePieOptions = computed(() => ({
    chart: {
        type: 'pie',
        fontFamily: 'Arial, sans-serif',
        foreColor: '#68665f',
        animations: { enabled: false },
    },
    labels: incentivePieData.value?.map((s) => s.label) ?? [],
    legend: { position: 'bottom' },
    colors: [
        '#c3441c',
        '#d39a42',
        '#24231e',
        '#71815b',
        '#956953',
        '#a7a17b',
        '#76746e',
    ],
    dataLabels: { formatter: (val: number) => val.toFixed(1) + '%' },
    tooltip: {
        y: {
            formatter: (val: number) =>
                '₱' + val.toLocaleString('en-PH', { minimumFractionDigits: 2 }),
        },
    },
}));

// ── Pie chart — Combined (Dividend + Incentive) ───────────────────────────────
const combinedPieData = computed(() => {
    if (!result.value || !combinedSummary.value.length) {
        return null;
    }

    const companyTotal =
        Math.round(
            ((result.value.company_amount ?? 0) +
                (result.value.incentive?.company_retained ?? 0)) *
                100,
        ) / 100;

    return [
        ...combinedSummary.value.map((m: any) => ({
            label: m.name,
            value: m.total_amount,
        })),
        ...(companyTotal > 0
            ? [{ label: 'Company', value: companyTotal }]
            : []),
    ];
});
const combinedPieSeries = computed(
    () => combinedPieData.value?.map((s) => s.value) ?? [],
);
const combinedPieOptions = computed(() => ({
    chart: {
        type: 'pie',
        fontFamily: 'Arial, sans-serif',
        foreColor: '#68665f',
        animations: { enabled: false },
    },
    labels: combinedPieData.value?.map((s) => s.label) ?? [],
    legend: { position: 'bottom' },
    colors: [
        '#c3441c',
        '#24231e',
        '#71815b',
        '#d39a42',
        '#956953',
        '#a7a17b',
        '#76746e',
    ],
    dataLabels: { formatter: (val: number) => val.toFixed(1) + '%' },
    tooltip: {
        y: {
            formatter: (val: number) =>
                '₱' + val.toLocaleString('en-PH', { minimumFractionDigits: 2 }),
        },
    },
}));

// ── Shareholders ──────────────────────────────────────────────────────────────
const shareholders = ref<any[]>([]);
const totalOwnership = ref(0);
const companyPct = ref(100);
const shForm = ref<any>({
    id: null,
    name: '',
    email: '',
    ownership_percentage: '',
    status: 'active',
    notes: '',
});
const shSaving = ref(false);

const loadShareholders = async () => {
    const res = await api.get('/api/v1/shareholders');
    shareholders.value = res.data.shareholders;
    totalOwnership.value = res.data.total_ownership;
    companyPct.value = res.data.company_percentage;
};
const editSh = (s: any) => {
    shForm.value = { ...s };
};
const resetShForm = () => {
    shForm.value = {
        id: null,
        name: '',
        email: '',
        ownership_percentage: '',
        status: 'active',
        notes: '',
    };
};
const saveSh = async () => {
    shSaving.value = true;

    try {
        const payload = {
            ...shForm.value,
            ownership_percentage:
                parseFloat(shForm.value.ownership_percentage) || 0,
        };

        if (shForm.value.id) {
            await api.put(`/api/v1/shareholders/${shForm.value.id}`, payload);
        } else {
            await api.post('/api/v1/shareholders', payload);
        }

        toast.success('Shareholder saved');
        resetShForm();
        await loadShareholders();
    } catch (err: any) {
        toast.error(
            Object.values(err.response?.data?.errors ?? {})
                .flat()
                .join(' ') ||
                err.response?.data?.message ||
                'Failed to save',
        );
    } finally {
        shSaving.value = false;
    }
};
const deleteSh = async (s: any) => {
    if (!confirm(`Remove shareholder ${s.name}?`)) {
        return;
    }

    await api.delete(`/api/v1/shareholders/${s.id}`);
    toast.success('Removed');
    await loadShareholders();
};

// ── Incentive rules (pool rate config) ───────────────────────────────────────
const incentiveRules = ref<any[]>([]);
const iForm = ref<any>({
    id: null,
    name: '',
    pool_type: 'gross_sales_pct',
    rate: '',
    distribution_method: 'by_sales',
    is_active: true,
    effective_date: today,
    expiration_date: '',
    notes: '',
});
const iSaving = ref(false);

const loadIncentiveRules = async () => {
    incentiveRules.value = (await api.get('/api/v1/incentive-rules')).data;
};
const editIncentive = (r: any) => {
    iForm.value = { ...r, expiration_date: r.expiration_date ?? '' };
};
const resetIForm = () => {
    iForm.value = {
        id: null,
        name: '',
        pool_type: 'gross_sales_pct',
        rate: '',
        distribution_method: 'by_sales',
        is_active: true,
        effective_date: today,
        expiration_date: '',
        notes: '',
    };
};
const saveIncentive = async () => {
    iSaving.value = true;

    try {
        const payload = {
            ...iForm.value,
            rate: parseFloat(iForm.value.rate) || 0,
            expiration_date: iForm.value.expiration_date || null,
        };

        if (iForm.value.id) {
            await api.put(`/api/v1/incentive-rules/${iForm.value.id}`, payload);
        } else {
            await api.post('/api/v1/incentive-rules', payload);
        }

        toast.success('Incentive rule saved');
        resetIForm();
        await loadIncentiveRules();
    } catch (err: any) {
        toast.error(
            Object.values(err.response?.data?.errors ?? {})
                .flat()
                .join(' ') ||
                err.response?.data?.message ||
                'Failed to save',
        );
    } finally {
        iSaving.value = false;
    }
};
const deleteIncentive = async (r: any) => {
    if (!confirm(`Delete incentive rule "${r.name}"?`)) {
        return;
    }

    await api.delete(`/api/v1/incentive-rules/${r.id}`);
    toast.success('Deleted');
    await loadIncentiveRules();
};

const poolTypeLabel = (t: string) =>
    ({
        gross_sales_pct: '% of Gross Sales',
        gross_profit_pct: '% of Gross Profit',
        net_profit_pct: '% of Net Profit',
        fixed_amount: 'Fixed ₱ Amount',
        product_sales_pct: "% of Each Product's Sales",
    })[t] ?? t;

const poolTypeUnit = (t: string) => (t === 'fixed_amount' ? '₱' : '%');

// ── Product Ownership ─────────────────────────────────────────────────────────
const productOwnerships = ref<any[]>([]);
const productFilter = ref('');
const editProductId = ref<number | null>(null);
const editProductName = ref('');
const editOwnerRows = ref<
    { shareholder_id: number | ''; ownership_percentage: number | '' }[]
>([]);
const productOwnerSaving = ref(false);

const filteredProducts = computed(() => {
    const q = productFilter.value.toLowerCase();

    return q
        ? productOwnerships.value.filter((p) =>
              p.product_name.toLowerCase().includes(q),
          )
        : productOwnerships.value;
});

const ownerTotalPct = computed(() =>
    editOwnerRows.value.reduce(
        (s, r) => s + (parseFloat(r.ownership_percentage as any) || 0),
        0,
    ),
);

const loadProductOwnerships = async () => {
    productOwnerships.value = (
        await api.get('/api/v1/product-ownerships')
    ).data;
};

const startEditProduct = (p: any) => {
    editProductId.value = p.product_id;
    editProductName.value = p.product_name;
    editOwnerRows.value = p.owners.length
        ? p.owners.map((o: any) => ({
              shareholder_id: o.shareholder_id,
              ownership_percentage: o.ownership_percentage,
          }))
        : [{ shareholder_id: '', ownership_percentage: '' }];
};

const addOwnerRow = () =>
    editOwnerRows.value.push({ shareholder_id: '', ownership_percentage: '' });
const removeOwnerRow = (i: number) => editOwnerRows.value.splice(i, 1);

const saveProductOwnership = async () => {
    if (
        editOwnerRows.value.length > 0 &&
        Math.abs(ownerTotalPct.value - 100) > 0.01
    ) {
        toast.error('Ownership percentages must total 100%');

        return;
    }

    productOwnerSaving.value = true;

    try {
        const owners = editOwnerRows.value
            .filter((r) => r.shareholder_id !== '')
            .map((r) => ({
                shareholder_id: r.shareholder_id,
                ownership_percentage:
                    parseFloat(r.ownership_percentage as any) || 0,
            }));
        await api.put(`/api/v1/product-ownerships/${editProductId.value}`, {
            owners,
        });
        toast.success('Product ownership saved');
        editProductId.value = null;
        await loadProductOwnerships();
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to save');
    } finally {
        productOwnerSaving.value = false;
    }
};

const clearProductOwnership = async (
    productId: number,
    productName: string,
) => {
    if (
        !confirm(
            `Remove all owners from "${productName}"? It will become company-owned.`,
        )
    ) {
        return;
    }

    try {
        await api.delete(`/api/v1/product-ownerships/${productId}`);
        toast.success('Owners cleared');

        if (editProductId.value === productId) {
            editProductId.value = null;
        }

        await loadProductOwnerships();
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to clear owners');
    }
};

// ── Trends ────────────────────────────────────────────────────────────────────
const trend = ref<any[]>([]);

const loadTrends = async () => {
    const yearStart = today.slice(0, 4) + '-01-01';
    const res = await api.get('/api/v1/distribution/trend', {
        params: { basis: basis.value, start_date: yearStart, end_date: today },
    });
    trend.value = res.data;
};
const trendSeries = computed(() => [
    { name: 'Dividend', data: trend.value.map((t: any) => t.members) },
    { name: 'Incentive', data: trend.value.map((t: any) => t.incentive ?? 0) },
    { name: 'Company', data: trend.value.map((t: any) => t.company) },
]);
const trendOptions = computed(() => ({
    chart: { type: 'line', toolbar: { show: false } },
    stroke: { width: 2, curve: 'smooth' },
    xaxis: { categories: trend.value.map((t: any) => t.month) },
    colors: ['#c3441c', '#d39a42', '#71815b'],
    yaxis: {
        labels: { formatter: (v: number) => '₱' + (v / 1000).toFixed(0) + 'K' },
    },
    legend: { position: 'top' },
}));

// ── Snapshots history ─────────────────────────────────────────────────────────
const snapshots = ref<any[]>([]);
const loadSnapshots = async () => {
    snapshots.value = (await api.get('/api/v1/distribution/snapshots')).data;
};

// ── Payout modal ──────────────────────────────────────────────────────────────
const tenders = ref<{ id: number; name: string }[]>([]);
const loadTenders = async () => {
    if (tenders.value.length) {
        return;
    }

    const res = await api.get('/api/v1/payment-tenders/all');
    tenders.value = res.data;
};

const payoutModal = ref<{
    open: boolean;
    snapshot: any | null;
    tenderId: number | '';
    notes: string;
    loading: boolean;
}>({
    open: false,
    snapshot: null,
    tenderId: '',
    notes: '',
    loading: false,
});

const openPayoutModal = async (snapshot: any) => {
    await loadTenders();
    payoutModal.value = {
        open: true,
        snapshot,
        tenderId: '',
        notes: '',
        loading: false,
    };
};

const submitPayout = async () => {
    if (payoutModal.value.loading || !payoutModal.value.tenderId) {
        return;
    }

    if (!payoutModal.value.tenderId) {
        toast.error('Please select a tender.');

        return;
    }

    payoutModal.value.loading = true;

    try {
        await api.post(
            `/api/v1/distribution/snapshots/${payoutModal.value.snapshot.id}/payout`,
            {
                tender_id: payoutModal.value.tenderId,
                notes: payoutModal.value.notes || null,
            },
        );
        toast.success('Payout recorded successfully.');
        payoutModal.value.open = false;
        loadSnapshots();
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to record payout.');
    } finally {
        payoutModal.value.loading = false;
    }
};

// ── Tab activation ────────────────────────────────────────────────────────────
watch(categoryId, () => {
    productId.value = '';
});
watch(subTab, (t) => {
    if (t === 'shareholders') {
        loadShareholders();
    } else if (t === 'incentives') {
        loadIncentiveRules();
        loadProductOwnerships();
        loadShareholders();
    } else if (t === 'trends') {
        loadTrends();
    } else if (t === 'history') {
        loadSnapshots();
    }
});

onMounted(loadPreview);

const tabs = [
    { key: 'distribution', label: 'Distribution', icon: PieChart },
    { key: 'shareholders', label: 'Shareholders', icon: Users },
    { key: 'incentives', label: 'Incentives', icon: Gift },
    { key: 'trends', label: 'Trends', icon: TrendingUp },
    { key: 'history', label: 'History', icon: History },
    { key: 'help', label: 'Help', icon: HelpCircle },
] as const;
</script>

<template>
    <Head title="Profit Sharing" />

    <div class="sharing-page w-full space-y-5">
        <div class="sharing-heading">
            <div>
                <p class="sharing-eyebrow">BYPASS GRILL / BUSINESS OVERVIEW</p>
                <h1>Profit <em>sharing.</em></h1>
                <p class="sharing-intro">
                    See what you earned. Review each share. Pay with confidence.
                </p>
            </div>
            <button
                @click="subTab = 'help'"
                class="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium text-muted-foreground transition hover:bg-muted"
            >
                <HelpCircle class="h-4 w-4" /> How it works
            </button>
        </div>

        <!-- Sub-tabs -->
        <nav
            class="sharing-tabs flex gap-1 overflow-x-auto"
            aria-label="Profit sharing sections"
        >
            <button
                v-for="t in tabs"
                :key="t.key"
                @click="subTab = t.key"
                :aria-current="subTab === t.key ? 'page' : undefined"
                :class="[
                    'flex items-center gap-1.5 border-b-2 px-4 py-2.5 text-sm font-semibold whitespace-nowrap transition',
                    subTab === t.key
                        ? 'border-primary text-primary'
                        : 'border-transparent text-muted-foreground hover:text-foreground',
                ]"
            >
                <component :is="t.icon" class="h-4 w-4" /> {{ t.label }}
            </button>
        </nav>

        <!-- ── DISTRIBUTION ───────────────────────────────────────────────── -->
        <template v-if="subTab === 'distribution'">
            <!-- Filters -->
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <div class="space-y-3">
                    <div class="sharing-filters flex flex-wrap items-end gap-3">
                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-muted-foreground"
                                >Basis</label
                            >
                            <div class="flex overflow-hidden rounded-lg border">
                                <button
                                    @click="
                                        basis = 'sales';
                                        loadPreview();
                                    "
                                    :class="[
                                        'px-3 py-2 text-sm font-semibold',
                                        basis === 'sales'
                                            ? 'bg-primary text-primary-foreground'
                                            : 'hover:bg-muted',
                                    ]"
                                >
                                    Sales
                                </button>
                                <button
                                    @click="
                                        basis = 'profit';
                                        loadPreview();
                                    "
                                    :class="[
                                        'px-3 py-2 text-sm font-semibold',
                                        basis === 'profit'
                                            ? 'bg-primary text-primary-foreground'
                                            : 'hover:bg-muted',
                                    ]"
                                >
                                    Profit
                                </button>
                            </div>
                        </div>
                        <div>
                            <label
                                for="sharing-from"
                                class="mb-1 block text-xs font-medium text-muted-foreground"
                                >From</label
                            ><input
                                id="sharing-from"
                                v-model="startDate"
                                type="date"
                                class="rounded-lg border bg-background px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label
                                for="sharing-to"
                                class="mb-1 block text-xs font-medium text-muted-foreground"
                                >To</label
                            ><input
                                id="sharing-to"
                                :min="startDate"
                                v-model="endDate"
                                type="date"
                                class="rounded-lg border bg-background px-3 py-2 text-sm"
                            />
                        </div>
                        <div class="w-full sm:w-auto">
                            <label
                                class="mb-1 block text-xs font-medium text-muted-foreground"
                                >Category</label
                            >
                            <select
                                v-model="categoryId"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm sm:w-auto"
                            >
                                <option value="">All</option>
                                <option
                                    v-for="c in categories"
                                    :key="c.id"
                                    :value="c.id"
                                >
                                    {{ c.name }}
                                </option>
                            </select>
                        </div>
                        <div class="w-full sm:w-auto">
                            <label
                                class="mb-1 block text-xs font-medium text-muted-foreground"
                                >Product</label
                            >
                            <select
                                v-model="productId"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm sm:w-auto"
                            >
                                <option value="">All</option>
                                <option
                                    v-for="p in products.filter(
                                        (p) =>
                                            !categoryId ||
                                            p.category_id === categoryId,
                                    )"
                                    :key="p.id"
                                    :value="p.id"
                                >
                                    {{ p.name }}
                                </option>
                            </select>
                        </div>
                        <button
                            @click="loadPreview"
                            :disabled="loading"
                            class="flex w-full items-center justify-center gap-1.5 rounded-lg bg-primary px-5 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 sm:w-auto"
                        >
                            <RefreshCw
                                v-if="loading"
                                class="h-3.5 w-3.5 animate-spin"
                            /><PieChart v-else class="h-3.5 w-3.5" /> Compute
                        </button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            @click="setWeek"
                            class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted"
                        >
                            This Week
                        </button>
                        <button
                            @click="setMonth"
                            class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted"
                        >
                            Month
                        </button>
                        <button
                            @click="setQuarter"
                            class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted"
                        >
                            Quarter
                        </button>
                        <button
                            @click="setYear"
                            class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted"
                        >
                            Year
                        </button>
                        <button
                            @click="exportCsv"
                            class="flex items-center gap-1 rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted"
                        >
                            <Download class="h-3 w-3" /> CSV
                        </button>
                        <button
                            @click="saveSnapshot"
                            :disabled="!canSave"
                            class="flex items-center gap-1 rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted"
                        >
                            <Save class="h-3 w-3" />
                            {{ saving ? 'Saving...' : 'Save payout snapshot' }}
                        </button>
                    </div>
                </div>
            </div>

            <p v-if="loading" role="status" class="sharing-notice">
                Calculating your distribution?
            </p>
            <p
                v-else-if="result && !previewCurrent"
                role="status"
                class="sharing-notice"
            >
                Filters changed. Select Compute to refresh the figures before
                saving.
            </p>
            <p v-if="categoryId || productId" class="sharing-notice">
                Filtered figures are estimates before shared expenses. Clear
                filters for a balanced payout snapshot.
            </p>
            <p v-if="result?.over_budget" role="alert" class="sharing-notice">
                Incentives exceed available profit. Adjust the incentive rules
                before saving or paying out.
            </p>
            <template v-if="result">
                <div class="sharing-summary">
                    <div>
                        <p class="sharing-eyebrow">
                            {{ result.range.start }} to {{ result.range.end }}
                        </p>
                        <h2>Your profit, accounted for.</h2>
                        <p>
                            The positive closing balance funds incentives first,
                            then ownership dividends. Company earnings stay in
                            the business.
                        </p>
                    </div>
                    <div>
                        <span>Combined member entitlement</span
                        ><strong>{{
                            fmt(
                                combinedSummary.reduce(
                                    (sum: number, m: any) =>
                                        sum + m.total_amount,
                                    0,
                                ),
                            )
                        }}</strong
                        ><small>Dividend + product incentive</small>
                    </div>
                </div>
                <!-- Financial Summary -->
                <p
                    v-if="
                        result.financial_summary.calculation ===
                        'financial_cash'
                    "
                    class="sharing-notice"
                >
                    Cash basis: matches Financial's closing balance for these
                    dates with asset deductions included. COGS is shown for
                    reference and is not deducted again. The balance brought
                    forward from previous months is included. Product/category
                    filters are estimates only.
                </p>
                <div
                    v-if="
                        result.financial_summary.calculation ===
                        'financial_cash'
                    "
                    class="grid gap-3 sm:grid-cols-3"
                >
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Balance brought forward
                        </p>
                        <p class="mt-1 text-xl font-bold">
                            {{ fmt(result.financial_summary.opening_balance) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Before {{ result.range.start }}
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Period net movement
                        </p>
                        <p class="mt-1 text-xl font-bold">
                            {{ fmt(result.financial_summary.net_profit) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Money in minus money out
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Available closing balance
                        </p>
                        <p class="mt-1 text-xl font-bold text-primary">
                            {{ fmt(result.financial_summary.closing_balance) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Brought forward + period movement
                        </p>
                    </div>
                </div>
                <div
                    v-if="result.financial_summary"
                    class="rounded-xl border bg-card p-4 shadow-sm"
                >
                    <div class="mb-3 flex items-center justify-between">
                        <p
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Financial Summary —
                            {{ result.financial_summary.period_end }}
                        </p>
                    </div>
                    <div
                        class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5"
                    >
                        <div class="space-y-0.5">
                            <p
                                class="text-[10px] tracking-wide text-muted-foreground uppercase"
                            >
                                Gross Sales
                            </p>
                            <p class="text-base font-bold">
                                {{ fmt(result.financial_summary.gross_sales) }}
                            </p>
                        </div>
                        <div class="space-y-0.5">
                            <p
                                class="text-[10px] tracking-wide text-muted-foreground uppercase"
                            >
                                Refunds (already in net sales)
                            </p>
                            <p class="text-base font-bold text-red-500">
                                −{{ fmt(result.financial_summary.refunds) }}
                            </p>
                        </div>
                        <div class="space-y-0.5">
                            <p
                                class="text-[10px] tracking-wide text-muted-foreground uppercase"
                            >
                                Net Sales
                            </p>
                            <p class="text-base font-bold text-blue-600">
                                {{ fmt(result.financial_summary.net_sales) }}
                            </p>
                        </div>
                        <div class="space-y-0.5">
                            <p
                                class="text-[10px] tracking-wide text-muted-foreground uppercase"
                            >
                                {{
                                    result.financial_summary.calculation ===
                                    'financial_cash'
                                        ? 'COGS (reference only)'
                                        : 'COGS (deducted from estimate)'
                                }}
                            </p>
                            <p class="text-base font-bold">
                                {{ fmt(result.financial_summary.cogs) }}
                            </p>
                        </div>
                        <div
                            v-if="
                                (result.financial_summary.income_adjustments ??
                                    0) !== 0
                            "
                            class="space-y-0.5"
                            title="Manual 'Other Income' entries from the Financial module — added to net profit."
                        >
                            <p
                                class="text-[10px] tracking-wide text-muted-foreground uppercase"
                            >
                                Other Income
                            </p>
                            <p class="text-base font-bold text-teal-600">
                                +{{
                                    fmt(
                                        result.financial_summary
                                            .income_adjustments,
                                    )
                                }}
                            </p>
                        </div>
                        <div
                            v-if="
                                (result.financial_summary.expenses ?? 0) !== 0
                            "
                            class="space-y-0.5"
                            title="Operating expenses (incl. paid bills) from the Financial module — deducted from net profit."
                        >
                            <p
                                class="text-[10px] tracking-wide text-muted-foreground uppercase"
                            >
                                Expenses
                            </p>
                            <p class="text-base font-bold text-red-500">
                                −{{ fmt(result.financial_summary.expenses) }}
                            </p>
                        </div>
                        <div
                            v-if="result.financial_summary.previous_payouts"
                            class="space-y-0.5"
                        >
                            <p
                                class="text-[10px] tracking-wide text-muted-foreground uppercase"
                            >
                                Recorded payouts
                            </p>
                            <p class="text-base font-bold">
                                -{{
                                    fmt(
                                        result.financial_summary
                                            .previous_payouts,
                                    )
                                }}
                            </p>
                        </div>
                        <div
                            v-if="(result.financial_summary.payroll ?? 0) !== 0"
                            class="space-y-0.5"
                            title="Payroll disbursements — deducted from net profit."
                        >
                            <p
                                class="text-[10px] tracking-wide text-muted-foreground uppercase"
                            >
                                Payroll
                            </p>
                            <p class="text-base font-bold text-purple-600">
                                −{{ fmt(result.financial_summary.payroll) }}
                            </p>
                        </div>
                        <div class="space-y-0.5">
                            <p
                                class="text-[10px] tracking-wide text-muted-foreground uppercase"
                                title="Cash basis: only paid bills and expenses are deducted — upcoming or unpaid bills are not reflected until paid. Includes manual Other Income / Expenses / Payroll."
                            >
                                {{
                                    result.financial_summary.calculation ===
                                    'financial_cash'
                                        ? 'Net cash movement'
                                        : 'Estimated gross profit'
                                }}
                            </p>
                            <p
                                class="text-base font-bold"
                                :class="
                                    result.financial_summary.net_profit >= 0
                                        ? 'text-emerald-600'
                                        : 'text-red-500'
                                "
                            >
                                {{ fmt(result.financial_summary.net_profit) }}
                            </p>
                        </div>
                        <div
                            v-if="result.financial_summary.asset_deductions"
                            class="space-y-0.5"
                        >
                            <p
                                class="text-[10px] tracking-wide text-muted-foreground uppercase"
                            >
                                Asset deductions
                            </p>
                            <p class="text-base font-bold text-red-500">
                                -{{
                                    fmt(
                                        result.financial_summary
                                            .asset_deductions,
                                    )
                                }}
                            </p>
                        </div>
                        <div class="space-y-0.5 border-l pl-3">
                            <p
                                class="text-[10px] tracking-wide text-muted-foreground uppercase"
                            >
                                {{
                                    result.basis === 'profit'
                                        ? 'Net cash / gross sales'
                                        : 'Sales Basis'
                                }}
                            </p>
                            <p class="text-base font-bold text-primary">
                                {{
                                    result.basis === 'profit'
                                        ? result.financial_summary.gross_sales >
                                          0
                                            ? (
                                                  (result.financial_summary
                                                      .net_profit /
                                                      result.financial_summary
                                                          .gross_sales) *
                                                  100
                                              ).toFixed(1) + '%'
                                            : '—'
                                        : fmt(
                                              result.financial_summary
                                                  .sales_base,
                                          )
                                }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Flow KPIs -->
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <div class="rounded-xl border bg-card p-4 shadow-sm">
                        <p
                            class="text-[10px] tracking-wide text-muted-foreground uppercase"
                        >
                            {{ result.base_label }}
                        </p>
                        <p class="mt-1 text-xl font-black">
                            {{ fmt(result.base_amount) }}
                        </p>
                    </div>
                    <template v-if="(result.incentive_pool ?? 0) > 0">
                        <div class="rounded-xl border bg-card p-4 shadow-sm">
                            <p
                                class="text-[10px] tracking-wide text-muted-foreground uppercase"
                            >
                                Incentive Pool
                            </p>
                            <p class="mt-1 text-xl font-black text-amber-600">
                                {{ fmt(result.incentive_pool) }}
                            </p>
                        </div>
                        <div class="rounded-xl border bg-card p-4 shadow-sm">
                            <p
                                class="text-[10px] tracking-wide text-muted-foreground uppercase"
                            >
                                Dividend Base
                            </p>
                            <p class="mt-1 text-xl font-black text-primary">
                                {{ fmt(result.distributable) }}
                            </p>
                        </div>
                    </template>
                    <template v-else>
                        <div
                            class="col-span-1 rounded-xl border bg-card p-4 shadow-sm"
                        >
                            <p
                                class="text-[10px] tracking-wide text-muted-foreground uppercase"
                            >
                                Distributable
                            </p>
                            <p class="mt-1 text-xl font-black text-primary">
                                {{ fmt(result.distributable) }}
                            </p>
                        </div>
                    </template>
                    <div
                        class="col-span-2 rounded-xl border bg-card p-4 shadow-sm lg:col-span-1"
                    >
                        <p
                            class="text-[10px] tracking-wide text-muted-foreground uppercase"
                        >
                            Company ({{ result.company_percentage }}%)
                        </p>
                        <p class="mt-1 text-xl font-black text-emerald-600">
                            {{ fmt(result.company_amount) }}
                        </p>
                    </div>
                </div>

                <!-- Two-column: Dividend + Incentive -->
                <div class="grid gap-4 lg:grid-cols-2">
                    <!-- ── Ownership Dividend ── -->
                    <div
                        class="overflow-hidden rounded-xl border bg-card shadow-sm"
                    >
                        <div class="border-b p-4">
                            <div class="mb-0.5 flex items-center gap-2">
                                <h3 class="text-sm font-bold">
                                    Ownership Dividend
                                </h3>
                                <span
                                    class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-400"
                                    >{{ result.members_percentage }}% of
                                    Distributable</span
                                >
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Based on ownership %. Company keeps its
                                {{ result.company_percentage }}%.
                            </p>
                        </div>
                        <!-- Pie -->
                        <div class="border-b p-4">
                            <apexchart
                                v-if="pieSeries.some((v: number) => v > 0)"
                                type="pie"
                                height="240"
                                :options="pieOptions"
                                :series="pieSeries"
                            />
                            <p
                                v-else
                                class="py-8 text-center text-sm text-muted-foreground"
                            >
                                No distributable amount.
                            </p>
                        </div>
                        <!-- Mobile cards -->
                        <div class="divide-y sm:hidden">
                            <div
                                v-for="m in result.members"
                                :key="m.shareholder_id"
                                class="space-y-1.5 p-3"
                            >
                                <div class="flex justify-between">
                                    <span class="text-sm font-semibold">{{
                                        m.name
                                    }}</span
                                    ><span class="text-xs text-muted-foreground"
                                        >{{ m.percentage }}%</span
                                    >
                                </div>
                                <div
                                    class="flex justify-between text-sm font-bold"
                                >
                                    <span>Dividend</span
                                    ><span class="text-blue-600">{{
                                        fmt(m.amount)
                                    }}</span>
                                </div>
                            </div>
                            <div
                                class="flex justify-between bg-muted/30 p-3 text-sm font-bold"
                            >
                                <span>Members total</span
                                ><span>{{ fmt(result.members_total) }}</span>
                            </div>
                            <div
                                class="flex justify-between bg-emerald-50 p-3 text-sm font-bold text-emerald-700 dark:bg-emerald-950/20 dark:text-emerald-400"
                            >
                                <span
                                    >Company ({{
                                        result.company_percentage
                                    }}%)</span
                                ><span>{{ fmt(result.company_amount) }}</span>
                            </div>
                        </div>
                        <!-- Desktop table -->
                        <table class="hidden w-full text-sm sm:table">
                            <thead
                                class="bg-muted/50 text-xs text-muted-foreground uppercase"
                            >
                                <tr>
                                    <th class="px-4 py-2 text-left">Member</th>
                                    <th class="px-4 py-2 text-right">
                                        Ownership
                                    </th>
                                    <th class="px-4 py-2 text-right">
                                        Dividend
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="m in result.members"
                                    :key="m.shareholder_id"
                                    class="hover:bg-muted/20"
                                >
                                    <td class="px-4 py-2 font-medium">
                                        {{ m.name }}
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        {{ m.percentage }}%
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right font-bold text-blue-600"
                                    >
                                        {{ fmt(m.amount) }}
                                    </td>
                                </tr>
                                <tr class="bg-muted/30 font-bold">
                                    <td class="px-4 py-2">Members total</td>
                                    <td class="px-4 py-2 text-right">
                                        {{ result.members_percentage }}%
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        {{ fmt(result.members_total) }}
                                    </td>
                                </tr>
                                <tr
                                    class="bg-emerald-50 font-bold text-emerald-700 dark:bg-emerald-950/20 dark:text-emerald-400"
                                >
                                    <td class="px-4 py-2">Company retained</td>
                                    <td class="px-4 py-2 text-right">
                                        {{ result.company_percentage }}%
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        {{ fmt(result.company_amount) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- ── Incentive (label + description differ by basis) ── -->
                    <div
                        class="overflow-hidden rounded-xl border bg-card shadow-sm"
                    >
                        <div class="border-b p-4">
                            <div
                                class="mb-0.5 flex items-center justify-between"
                            >
                                <div class="flex items-center gap-2">
                                    <h3 class="text-sm font-bold">
                                        {{
                                            result.basis === 'sales'
                                                ? 'Sales Incentive'
                                                : 'Product Ownership Incentive'
                                        }}
                                    </h3>
                                    <span
                                        class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-900/30 dark:text-amber-400"
                                        >{{
                                            fmt(result.incentive?.total ?? 0)
                                        }}</span
                                    >
                                </div>
                                <button
                                    @click="subTab = 'incentives'"
                                    class="text-xs text-muted-foreground underline hover:text-foreground"
                                >
                                    Manage
                                </button>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                <template v-if="result.basis === 'sales'"
                                    >A percentage of available profit, allocated
                                    by product sales and product ownership.
                                    Unallocated shares stay with the
                                    company.</template
                                >
                                <template v-else
                                    >Distributed proportionally by product
                                    sales, then split by product ownership
                                    %.</template
                                >
                            </p>
                        </div>

                        <!-- No rules configured (both modes) -->
                        <div
                            v-if="!result.incentive?.rules?.length"
                            class="p-6 text-center"
                        >
                            <Gift
                                class="mx-auto mb-2 h-8 w-8 text-muted-foreground"
                            />
                            <p class="text-sm text-muted-foreground">
                                <template v-if="result.basis === 'sales'"
                                    >No sales incentive rate
                                    configured.</template
                                >
                                <template v-else
                                    >No active incentive rules.</template
                                >
                            </p>
                            <button
                                @click="subTab = 'incentives'"
                                class="mt-3 rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90"
                            >
                                {{
                                    result.basis === 'sales'
                                        ? 'Set up Sales Incentive Rate'
                                        : 'Set up Incentive Rules'
                                }}
                            </button>
                        </div>

                        <template v-else>
                            <!-- Active rules summary -->
                            <div class="space-y-1 border-b p-3">
                                <p
                                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    Active Rules
                                </p>
                                <div
                                    v-for="r in result.incentive.rules"
                                    :key="r.id"
                                    class="flex items-center justify-between text-sm"
                                >
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{ r.name }}</span
                                    >
                                    <span
                                        class="text-xs font-bold text-amber-600"
                                    >
                                        <template
                                            v-if="
                                                r.pool_type ===
                                                'product_sales_pct'
                                            "
                                            >{{ r.rate }}% of available
                                            profit</template
                                        >
                                        <template
                                            v-else-if="
                                                r.pool_type === 'fixed_amount'
                                            "
                                            >₱{{ r.rate }} →
                                            {{ fmt(r.pool_amount) }}</template
                                        >
                                        <template v-else
                                            >{{ r.rate }}% →
                                            {{ fmt(r.pool_amount) }}</template
                                        >
                                    </span>
                                </div>
                            </div>

                            <!-- Incentive pie chart -->
                            <div v-if="incentivePieData" class="border-b p-4">
                                <p
                                    class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    Incentive Distribution
                                </p>
                                <apexchart
                                    type="pie"
                                    height="220"
                                    :options="incentivePieOptions"
                                    :series="incentivePieSeries"
                                />
                            </div>

                            <!-- No product breakdown when profit mode has rules but no sales -->
                            <div
                                v-if="!result.incentive.by_product?.length"
                                class="p-6 text-center"
                            >
                                <Package
                                    class="mx-auto mb-2 h-8 w-8 text-muted-foreground"
                                />
                                <p class="text-sm text-muted-foreground">
                                    No product sales in this period.
                                </p>
                            </div>

                            <!-- Product breakdown (mobile) -->
                            <div
                                v-else
                                class="max-h-80 divide-y overflow-y-auto sm:hidden"
                            >
                                <div
                                    v-for="p in result.incentive.by_product"
                                    :key="p.product_id"
                                    class="space-y-1.5 p-3"
                                >
                                    <div
                                        class="flex items-start justify-between"
                                    >
                                        <span
                                            class="text-sm leading-tight font-semibold"
                                            >{{ p.product_name }}</span
                                        >
                                        <span
                                            class="ml-2 shrink-0 text-sm font-bold text-amber-600"
                                            >{{
                                                fmt(p.product_incentive)
                                            }}</span
                                        >
                                    </div>
                                    <div
                                        class="flex justify-between text-xs text-muted-foreground"
                                    >
                                        <span
                                            >Sales:
                                            {{ fmt(p.sales_amount) }}</span
                                        >
                                        <span v-if="p.contribution_pct"
                                            >{{ p.contribution_pct }}%
                                            {{
                                                result.basis === 'sales'
                                                    ? 'of owned sales'
                                                    : 'of pool'
                                            }}</span
                                        >
                                    </div>
                                    <div
                                        v-if="p.owners.length"
                                        class="space-y-0.5"
                                    >
                                        <div
                                            v-for="o in p.owners"
                                            :key="o.shareholder_id"
                                            class="flex justify-between text-xs"
                                        >
                                            <span
                                                class="pl-2 text-muted-foreground"
                                                >→ {{ o.name }} ({{
                                                    o.ownership_pct
                                                }}%)</span
                                            >
                                            <span
                                                class="font-medium text-blue-600"
                                                >{{ fmt(o.amount) }}</span
                                            >
                                        </div>
                                    </div>
                                    <div
                                        v-else
                                        class="pl-2 text-xs text-muted-foreground"
                                    >
                                        → Company (unowned)
                                    </div>
                                </div>
                            </div>

                            <!-- Product breakdown (desktop) -->
                            <div
                                v-if="result.incentive.by_product?.length"
                                class="hidden max-h-80 overflow-x-auto overflow-y-auto sm:block"
                            >
                                <table class="w-full text-sm">
                                    <thead
                                        class="sticky top-0 bg-muted/50 text-xs text-muted-foreground uppercase"
                                    >
                                        <tr>
                                            <th class="px-3 py-2 text-left">
                                                Product
                                            </th>
                                            <th class="px-3 py-2 text-right">
                                                Sales
                                            </th>
                                            <th class="px-3 py-2 text-right">
                                                {{
                                                    result.basis === 'sales'
                                                        ? '% of Owned'
                                                        : 'Contrib %'
                                                }}
                                            </th>
                                            <th class="px-3 py-2 text-right">
                                                Incentive
                                            </th>
                                            <th class="px-3 py-2 text-left">
                                                Distribution
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y">
                                        <tr
                                            v-for="p in result.incentive
                                                .by_product"
                                            :key="p.product_id"
                                            class="hover:bg-muted/20"
                                        >
                                            <td
                                                class="max-w-[140px] truncate px-3 py-2 font-medium"
                                                :title="p.product_name"
                                            >
                                                {{ p.product_name }}
                                            </td>
                                            <td
                                                class="px-3 py-2 text-right text-xs"
                                            >
                                                {{ fmt(p.sales_amount) }}
                                            </td>
                                            <td
                                                class="px-3 py-2 text-right text-xs text-muted-foreground"
                                            >
                                                {{
                                                    p.contribution_pct
                                                        ? p.contribution_pct +
                                                          '%'
                                                        : '—'
                                                }}
                                            </td>
                                            <td
                                                class="px-3 py-2 text-right font-bold text-amber-600"
                                            >
                                                {{ fmt(p.product_incentive) }}
                                            </td>
                                            <td class="px-3 py-2 text-xs">
                                                <span
                                                    v-if="!p.owners.length"
                                                    class="text-muted-foreground italic"
                                                    >Company</span
                                                >
                                                <span v-else>{{
                                                    p.owners
                                                        .map(
                                                            (o: any) =>
                                                                o.name +
                                                                ' ' +
                                                                fmt(o.amount),
                                                        )
                                                        .join(', ')
                                                }}</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Shareholder + company summary -->
                            <div
                                v-if="
                                    result.incentive.by_shareholder?.length ||
                                    result.incentive.company_retained > 0
                                "
                                class="space-y-1.5 border-t p-3"
                            >
                                <p
                                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    {{
                                        result.basis === 'sales'
                                            ? 'Sales Incentive Summary'
                                            : 'Incentive Summary'
                                    }}
                                </p>
                                <div
                                    v-for="s in result.incentive.by_shareholder"
                                    :key="s.shareholder_id"
                                    class="flex justify-between text-sm"
                                >
                                    <span class="text-muted-foreground">{{
                                        s.name
                                    }}</span>
                                    <span class="font-bold text-blue-600">{{
                                        fmt(s.incentive_amount)
                                    }}</span>
                                </div>
                                <div
                                    v-if="result.incentive.company_retained > 0"
                                    class="flex justify-between text-sm"
                                >
                                    <span class="text-muted-foreground"
                                        >Company (unowned products)</span
                                    >
                                    <span class="font-bold text-emerald-600">{{
                                        fmt(result.incentive.company_retained)
                                    }}</span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Combined payout summary -->
                <div
                    v-if="combinedSummary.length"
                    class="overflow-hidden rounded-xl border bg-card shadow-sm"
                >
                    <div class="border-b p-4">
                        <h3 class="text-sm font-bold">
                            Total Payout per Shareholder
                        </h3>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Ownership dividend + sales incentive combined.
                        </p>
                    </div>
                    <!-- Combined pie chart -->
                    <div v-if="combinedPieData" class="border-b p-4">
                        <apexchart
                            type="pie"
                            height="260"
                            :options="combinedPieOptions"
                            :series="combinedPieSeries"
                        />
                    </div>
                    <!-- Mobile cards -->
                    <div class="divide-y sm:hidden">
                        <div
                            v-for="m in combinedSummary"
                            :key="m.shareholder_id"
                            class="space-y-1.5 p-3"
                        >
                            <div class="text-sm font-semibold">
                                {{ m.name }}
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-muted-foreground"
                                    >Dividend</span
                                ><span class="text-blue-600">{{
                                    fmt(m.amount)
                                }}</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-muted-foreground"
                                    >Incentive</span
                                ><span class="text-amber-600">{{
                                    fmt(m.incentive_amount)
                                }}</span>
                            </div>
                            <div class="flex justify-between text-sm font-bold">
                                <span>Total</span
                                ><span>{{ fmt(m.total_amount) }}</span>
                            </div>
                        </div>
                    </div>
                    <!-- Desktop table -->
                    <table class="hidden w-full text-sm sm:table">
                        <thead
                            class="bg-muted/50 text-xs text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="px-4 py-2 text-left">Shareholder</th>
                                <th class="px-4 py-2 text-right">Ownership</th>
                                <th class="px-4 py-2 text-right text-blue-600">
                                    Dividend
                                </th>
                                <th class="px-4 py-2 text-right text-amber-600">
                                    Incentive
                                </th>
                                <th class="px-4 py-2 text-right">
                                    Total Payout
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="m in combinedSummary"
                                :key="m.shareholder_id"
                                class="hover:bg-muted/20"
                            >
                                <td class="px-4 py-2 font-medium">
                                    {{ m.name }}
                                </td>
                                <td
                                    class="px-4 py-2 text-right text-muted-foreground"
                                >
                                    {{ m.percentage }}%
                                </td>
                                <td
                                    class="px-4 py-2 text-right font-medium text-blue-600"
                                >
                                    {{ fmt(m.amount) }}
                                </td>
                                <td
                                    class="px-4 py-2 text-right font-medium text-amber-600"
                                >
                                    {{ fmt(m.incentive_amount) }}
                                </td>
                                <td class="px-4 py-2 text-right font-bold">
                                    {{ fmt(m.total_amount) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </template>

        <!-- ── SHAREHOLDERS ───────────────────────────────────────────────── -->
        <template v-if="subTab === 'shareholders'">
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-bold">
                        Ownership ({{ totalOwnership }}% allocated · Company
                        keeps {{ companyPct }}%)
                    </h3>
                </div>
                <div
                    class="mb-4 flex h-2 overflow-hidden rounded-full bg-muted"
                >
                    <div
                        class="h-full bg-primary"
                        :style="{ width: totalOwnership + '%' }"
                    ></div>
                    <div
                        class="h-full bg-emerald-400"
                        :style="{ width: companyPct + '%' }"
                    ></div>
                </div>
                <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <div>
                        <label class="mb-1 block text-xs text-muted-foreground"
                            >Name *</label
                        ><input
                            v-model="shForm.name"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-muted-foreground"
                            >Email</label
                        ><input
                            v-model="shForm.email"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-muted-foreground"
                            >Ownership %</label
                        ><input
                            v-model="shForm.ownership_percentage"
                            type="number"
                            step="0.01"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-muted-foreground"
                            >Status</label
                        ><select
                            v-model="shForm.status"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm"
                        >
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button
                            @click="saveSh"
                            :disabled="shSaving || !shForm.name"
                            class="flex-1 rounded-lg bg-primary px-3 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            {{ shForm.id ? 'Update' : 'Add' }}
                        </button>
                        <button
                            v-if="shForm.id"
                            @click="resetShForm"
                            class="rounded-lg border px-3 py-2"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <!-- Mobile cards -->
                <div class="divide-y sm:hidden">
                    <div
                        v-for="s in shareholders"
                        :key="s.id"
                        class="space-y-1.5 p-3"
                    >
                        <div class="flex items-start justify-between">
                            <span class="text-sm font-semibold">{{
                                s.name
                            }}</span>
                            <span
                                :class="[
                                    'rounded-full px-2 py-0.5 text-xs',
                                    s.status === 'active'
                                        ? 'bg-green-100 text-green-700'
                                        : 'bg-gray-100 text-gray-500',
                                ]"
                                >{{ s.status }}</span
                            >
                        </div>
                        <div class="text-xs text-muted-foreground">
                            {{ s.email ?? '—' }}
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-bold"
                                >{{ s.ownership_percentage }}%</span
                            >
                            <div class="flex gap-1">
                                <button
                                    @click="editSh(s)"
                                    class="p-1.5 text-muted-foreground hover:text-blue-600"
                                >
                                    <Pencil class="h-4 w-4" />
                                </button>
                                <button
                                    @click="deleteSh(s)"
                                    class="p-1.5 text-muted-foreground hover:text-red-600"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <div
                        v-if="!shareholders.length"
                        class="px-4 py-8 text-center text-sm text-muted-foreground"
                    >
                        No shareholders yet.
                    </div>
                </div>
                <!-- Desktop table -->
                <table class="hidden w-full text-sm sm:table">
                    <thead
                        class="bg-muted/50 text-xs text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-2 text-left">Name</th>
                            <th class="px-4 py-2 text-left">Email</th>
                            <th class="px-4 py-2 text-right">Ownership</th>
                            <th class="px-4 py-2 text-center">Status</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="s in shareholders"
                            :key="s.id"
                            class="hover:bg-muted/20"
                        >
                            <td class="px-4 py-2 font-medium">{{ s.name }}</td>
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ s.email ?? '—' }}
                            </td>
                            <td class="px-4 py-2 text-right font-bold">
                                {{ s.ownership_percentage }}%
                            </td>
                            <td class="px-4 py-2 text-center">
                                <span
                                    :class="[
                                        'rounded-full px-2 py-0.5 text-xs',
                                        s.status === 'active'
                                            ? 'bg-green-100 text-green-700'
                                            : 'bg-gray-100 text-gray-500',
                                    ]"
                                    >{{ s.status }}</span
                                >
                            </td>
                            <td class="px-4 py-2 text-right">
                                <button
                                    @click="editSh(s)"
                                    class="p-1 text-muted-foreground hover:text-blue-600"
                                >
                                    <Pencil class="h-4 w-4" />
                                </button>
                                <button
                                    @click="deleteSh(s)"
                                    class="p-1 text-muted-foreground hover:text-red-600"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!shareholders.length">
                            <td
                                colspan="5"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No shareholders yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── INCENTIVES ───────────────────────────────────────────────── -->
        <template v-if="subTab === 'incentives'">
            <!-- Info banner -->
            <div
                class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/20 dark:text-amber-200"
            >
                <strong>How it works:</strong> Set an incentive rate (e.g., 2%
                of gross sales). Each product's share of the pool is
                proportional to its sales. That share is then split among the
                product's assigned owners by their ownership %. Unowned
                products' incentives stay with the company. This is independent
                of the ownership dividend.
            </div>

            <!-- Section 1: Incentive Rate Rules -->
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">
                    {{ iForm.id ? 'Edit' : 'Add' }} Incentive Rate Rule
                </h3>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-xs text-muted-foreground"
                            >Rule Name *</label
                        ><input
                            v-model="iForm.name"
                            placeholder="e.g. Monthly Product Incentive"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-muted-foreground"
                            >Pool Type</label
                        >
                        <select
                            v-model="iForm.pool_type"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm"
                        >
                            <optgroup label="Sales Mode">
                                <option value="product_sales_pct">
                                    % of Each Product's Sales
                                </option>
                            </optgroup>
                            <optgroup label="Profit Mode">
                                <option value="gross_sales_pct">
                                    % of Gross Sales
                                </option>
                                <option value="gross_profit_pct">
                                    % of Gross Profit
                                </option>
                                <option value="net_profit_pct">
                                    % of Net Profit
                                </option>
                                <option value="fixed_amount">
                                    Fixed ₱ Amount
                                </option>
                            </optgroup>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-muted-foreground"
                            >Rate ({{ poolTypeUnit(iForm.pool_type) }}) *</label
                        >
                        <input
                            v-model="iForm.rate"
                            type="number"
                            step="0.01"
                            :placeholder="
                                iForm.pool_type === 'fixed_amount'
                                    ? '5000'
                                    : '2.0'
                            "
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-muted-foreground"
                            >Effective Date *</label
                        ><input
                            v-model="iForm.effective_date"
                            type="date"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-muted-foreground"
                            >Expires (optional)</label
                        ><input
                            v-model="iForm.expiration_date"
                            type="date"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-muted-foreground"
                            >Notes</label
                        ><input
                            v-model="iForm.notes"
                            placeholder="Optional"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <div class="flex items-end gap-2">
                        <label
                            class="flex cursor-pointer items-center gap-2 select-none"
                        >
                            <div class="relative">
                                <input
                                    type="checkbox"
                                    v-model="iForm.is_active"
                                    class="peer sr-only"
                                />
                                <div
                                    class="peer h-5 w-9 rounded-full bg-muted transition peer-checked:bg-primary"
                                ></div>
                                <div
                                    class="absolute top-0.5 left-0.5 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-4"
                                ></div>
                            </div>
                            <span class="text-sm">Active</span>
                        </label>
                    </div>
                    <div class="flex items-end gap-2">
                        <button
                            @click="saveIncentive"
                            :disabled="iSaving || !iForm.name || !iForm.rate"
                            class="flex-1 rounded-lg bg-primary px-3 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            {{ iForm.id ? 'Update' : 'Add Rule' }}
                        </button>
                        <button
                            v-if="iForm.id"
                            @click="resetIForm"
                            class="rounded-lg border px-3 py-2"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>
            <!-- Active rules table -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <div class="divide-y sm:hidden">
                    <div
                        v-for="r in incentiveRules"
                        :key="r.id"
                        :class="[
                            'space-y-1.5 p-3',
                            !r.is_active && 'opacity-50',
                        ]"
                    >
                        <div class="flex items-start justify-between">
                            <span class="text-sm font-semibold">{{
                                r.name
                            }}</span>
                            <span class="text-xs font-bold text-amber-600">{{
                                r.pool_type === 'fixed_amount'
                                    ? fmt(r.rate)
                                    : r.rate +
                                      '% of ' +
                                      poolTypeLabel(r.pool_type).replace(
                                          '% of ',
                                          '',
                                      )
                            }}</span>
                        </div>
                        <div class="text-xs text-muted-foreground">
                            {{ poolTypeLabel(r.pool_type) }}
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-muted-foreground"
                                >{{ r.effective_date?.slice(0, 10) }} →
                                {{
                                    r.expiration_date?.slice(0, 10) ?? '∞'
                                }}</span
                            >
                            <div class="flex gap-1">
                                <button
                                    @click="editIncentive(r)"
                                    class="p-1.5 text-muted-foreground hover:text-blue-600"
                                >
                                    <Pencil class="h-4 w-4" />
                                </button>
                                <button
                                    @click="deleteIncentive(r)"
                                    class="p-1.5 text-muted-foreground hover:text-red-600"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <div
                        v-if="!incentiveRules.length"
                        class="px-4 py-6 text-center text-sm text-muted-foreground"
                    >
                        No incentive rules yet.
                    </div>
                </div>
                <table class="hidden w-full text-sm sm:table">
                    <thead
                        class="bg-muted/50 text-xs text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-2 text-left">Name</th>
                            <th class="px-4 py-2 text-left">Type</th>
                            <th class="px-4 py-2 text-right">Rate</th>
                            <th class="px-4 py-2 text-left">Window</th>
                            <th class="px-4 py-2 text-center">Active</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="r in incentiveRules"
                            :key="r.id"
                            :class="[
                                'hover:bg-muted/20',
                                !r.is_active && 'opacity-50',
                            ]"
                        >
                            <td class="px-4 py-2 font-medium">{{ r.name }}</td>
                            <td class="px-4 py-2 text-xs text-muted-foreground">
                                {{ poolTypeLabel(r.pool_type) }}
                            </td>
                            <td
                                class="px-4 py-2 text-right font-bold text-amber-600"
                            >
                                {{
                                    r.pool_type === 'fixed_amount'
                                        ? fmt(r.rate)
                                        : r.rate + '%'
                                }}
                            </td>
                            <td class="px-4 py-2 text-xs text-muted-foreground">
                                {{ r.effective_date?.slice(0, 10) }} →
                                {{ r.expiration_date?.slice(0, 10) ?? '∞' }}
                            </td>
                            <td class="px-4 py-2 text-center">
                                <span
                                    :class="[
                                        'rounded-full px-2 py-0.5 text-xs',
                                        r.is_active
                                            ? 'bg-green-100 text-green-700'
                                            : 'bg-gray-100 text-gray-500',
                                    ]"
                                    >{{ r.is_active ? 'Yes' : 'No' }}</span
                                >
                            </td>
                            <td class="px-4 py-2 text-right">
                                <button
                                    @click="editIncentive(r)"
                                    class="p-1 text-muted-foreground hover:text-blue-600"
                                >
                                    <Pencil class="h-4 w-4" />
                                </button>
                                <button
                                    @click="deleteIncentive(r)"
                                    class="p-1 text-muted-foreground hover:text-red-600"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!incentiveRules.length">
                            <td
                                colspan="6"
                                class="px-4 py-6 text-center text-muted-foreground"
                            >
                                No incentive rules yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Section 2: Product Ownership -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <div class="border-b p-4">
                    <div class="mb-1 flex items-center gap-2">
                        <Package class="h-4 w-4 text-primary" />
                        <h3 class="text-sm font-bold">Product Ownership</h3>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Assign shareholders as product owners. Ownership % per
                        product must total 100%. Products without owners are
                        company-owned.
                    </p>
                </div>

                <!-- Search -->
                <div class="border-b p-3">
                    <input
                        v-model="productFilter"
                        placeholder="Search products…"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm"
                    />
                </div>

                <!-- Edit panel -->
                <div
                    v-if="editProductId !== null"
                    class="border-b bg-amber-50 p-4 dark:bg-amber-950/20"
                >
                    <div class="mb-3 flex items-center justify-between">
                        <h4 class="text-sm font-semibold">
                            Editing:
                            <span class="text-primary">{{
                                editProductName
                            }}</span>
                        </h4>
                        <button
                            @click="editProductId = null"
                            class="rounded p-1 hover:bg-muted"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <!-- Owner rows -->
                    <div class="mb-3 space-y-2">
                        <div
                            v-for="(row, i) in editOwnerRows"
                            :key="i"
                            class="flex items-center gap-2"
                        >
                            <select
                                v-model="row.shareholder_id"
                                class="min-w-0 flex-1 rounded-lg border bg-background px-3 py-2 text-sm"
                            >
                                <option value="">Select shareholder…</option>
                                <option
                                    v-for="s in shareholders"
                                    :key="s.id"
                                    :value="s.id"
                                >
                                    {{ s.name }}
                                </option>
                            </select>
                            <div class="relative w-24 shrink-0">
                                <input
                                    v-model="row.ownership_percentage"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    max="100"
                                    placeholder="%"
                                    class="w-full rounded-lg border bg-background px-3 py-2 pr-6 text-right text-sm"
                                />
                                <span
                                    class="absolute top-1/2 right-2.5 -translate-y-1/2 text-xs text-muted-foreground"
                                    >%</span
                                >
                            </div>
                            <button
                                @click="removeOwnerRow(i)"
                                class="shrink-0 p-1.5 text-muted-foreground hover:text-red-600"
                            >
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                        <div
                            v-if="!editOwnerRows.length"
                            class="text-sm text-muted-foreground italic"
                        >
                            No owners — saving will make this product
                            company-owned.
                        </div>
                    </div>
                    <!-- Footer -->
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <div class="flex items-center gap-3">
                            <button
                                @click="addOwnerRow"
                                class="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium hover:bg-muted"
                            >
                                <Plus class="h-3 w-3" /> Add Owner
                            </button>
                            <span
                                v-if="editOwnerRows.length"
                                :class="[
                                    'text-xs font-bold tabular-nums',
                                    Math.abs(ownerTotalPct - 100) < 0.01
                                        ? 'text-emerald-600'
                                        : 'text-amber-600',
                                ]"
                            >
                                {{ ownerTotalPct.toFixed(1) }}% / 100%
                            </span>
                        </div>
                        <div class="flex gap-2">
                            <button
                                @click="editProductId = null"
                                class="rounded-lg border px-3 py-1.5 text-xs"
                            >
                                Cancel
                            </button>
                            <button
                                @click="saveProductOwnership"
                                :disabled="
                                    productOwnerSaving ||
                                    (editOwnerRows.length > 0 &&
                                        (Math.abs(ownerTotalPct - 100) > 0.01 ||
                                            editOwnerRows.some(
                                                (r) => !r.shareholder_id,
                                            )))
                                "
                                class="rounded-lg bg-primary px-3 py-1.5 text-xs font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                            >
                                {{ productOwnerSaving ? 'Saving…' : 'Save' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Mobile product cards -->
                <div class="divide-y sm:hidden">
                    <div
                        v-for="p in filteredProducts"
                        :key="p.product_id"
                        :class="[
                            'space-y-1.5 p-3',
                            editProductId === p.product_id && 'bg-muted/30',
                        ]"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-sm leading-tight font-semibold">{{
                                p.product_name
                            }}</span>
                            <div class="flex shrink-0 gap-1">
                                <button
                                    @click="startEditProduct(p)"
                                    class="p-1.5 text-muted-foreground hover:text-blue-600"
                                >
                                    <Pencil class="h-4 w-4" />
                                </button>
                                <button
                                    v-if="p.owners.length"
                                    @click="
                                        clearProductOwnership(
                                            p.product_id,
                                            p.product_name,
                                        )
                                    "
                                    class="p-1.5 text-muted-foreground hover:text-red-600"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                        <div v-if="p.owners.length" class="space-y-0.5">
                            <div
                                v-for="o in p.owners"
                                :key="o.shareholder_id"
                                class="text-xs text-muted-foreground"
                            >
                                {{ o.name }} — {{ o.ownership_percentage }}%
                            </div>
                            <span
                                :class="[
                                    'text-xs font-bold',
                                    p.total_percentage === 100
                                        ? 'text-emerald-600'
                                        : 'text-amber-600',
                                ]"
                                >Total: {{ p.total_percentage }}%</span
                            >
                        </div>
                        <div
                            v-else
                            class="text-xs text-muted-foreground italic"
                        >
                            Company owned
                        </div>
                    </div>
                    <div
                        v-if="!filteredProducts.length"
                        class="px-4 py-8 text-center text-sm text-muted-foreground"
                    >
                        No products found.
                    </div>
                </div>

                <!-- Desktop product table -->
                <table class="hidden w-full text-sm sm:table">
                    <thead
                        class="bg-muted/50 text-xs text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-2 text-left">Product</th>
                            <th class="px-4 py-2 text-left">Owners</th>
                            <th class="px-4 py-2 text-right">Total %</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="p in filteredProducts"
                            :key="p.product_id"
                            :class="[
                                'hover:bg-muted/20',
                                editProductId === p.product_id && 'bg-muted/30',
                            ]"
                        >
                            <td class="px-4 py-2 font-medium">
                                {{ p.product_name }}
                            </td>
                            <td class="px-4 py-2 text-xs">
                                <span
                                    v-if="!p.owners.length"
                                    class="text-muted-foreground italic"
                                    >Company owned</span
                                >
                                <span v-else>{{
                                    p.owners
                                        .map(
                                            (o: any) =>
                                                o.name +
                                                ' ' +
                                                o.ownership_percentage +
                                                '%',
                                        )
                                        .join(' · ')
                                }}</span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <span
                                    v-if="p.owners.length"
                                    :class="[
                                        'text-xs font-bold',
                                        p.total_percentage === 100
                                            ? 'text-emerald-600'
                                            : 'text-amber-600',
                                    ]"
                                    >{{ p.total_percentage }}%</span
                                >
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <button
                                    @click="startEditProduct(p)"
                                    class="p-1 text-muted-foreground hover:text-blue-600"
                                    title="Edit owners"
                                >
                                    <Pencil class="h-4 w-4" />
                                </button>
                                <button
                                    v-if="p.owners.length"
                                    @click="
                                        clearProductOwnership(
                                            p.product_id,
                                            p.product_name,
                                        )
                                    "
                                    class="p-1 text-muted-foreground hover:text-red-600"
                                    title="Remove all owners"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!filteredProducts.length">
                            <td
                                colspan="4"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No products found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── TRENDS ───────────────────────────────────────────────────────── -->
        <template v-if="subTab === 'trends'">
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <h3 class="mb-2 flex items-center gap-2 text-sm font-bold">
                    <TrendingUp class="h-4 w-4 text-primary" /> Monthly
                    Distribution Trend (this year)
                </h3>
                <apexchart
                    v-if="trend.length"
                    type="line"
                    height="320"
                    :options="trendOptions"
                    :series="trendSeries"
                />
                <p
                    v-else
                    class="py-10 text-center text-sm text-muted-foreground"
                >
                    No data.
                </p>
            </div>
        </template>

        <!-- ── HISTORY ──────────────────────────────────────────────────────── -->
        <template v-if="subTab === 'history'">
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <div class="border-b p-4">
                    <h3 class="text-sm font-bold">Distribution Snapshots</h3>
                </div>

                <!-- Mobile cards -->
                <div class="divide-y sm:hidden">
                    <div
                        v-for="s in snapshots"
                        :key="s.id"
                        class="space-y-1.5 p-3"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-muted-foreground"
                                >{{ s.period_start?.slice(0, 10) }} →
                                {{ s.period_end?.slice(0, 10) }}</span
                            >
                            <span
                                class="rounded-full bg-muted px-2 py-0.5 text-xs capitalize"
                                >{{ s.distribution_basis }}</span
                            >
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-muted-foreground"
                                >Distributable</span
                            ><span class="font-bold">{{
                                fmt(s.distributable_amount)
                            }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-muted-foreground">Members</span
                            ><span>{{ fmt(s.members_amount) }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-muted-foreground">Company</span
                            ><span class="font-medium text-emerald-600">{{
                                fmt(s.company_amount)
                            }}</span>
                        </div>
                        <div class="text-xs text-muted-foreground">
                            By: {{ s.creator?.name ?? '—' }}
                        </div>
                        <!-- Payout status -->
                        <div
                            v-if="s.paid_at"
                            class="flex items-center gap-1 text-xs font-medium text-emerald-600"
                        >
                            <CheckCircle2 class="h-3 w-3" /> Paid
                            {{ s.paid_at?.slice(0, 10) }} by
                            {{ s.payer?.name ?? '—' }}
                        </div>
                        <button
                            v-else
                            @click="openPayoutModal(s)"
                            class="flex items-center gap-1.5 rounded-lg border border-primary/40 px-2.5 py-1 text-xs font-semibold text-primary transition-colors hover:bg-primary/5"
                        >
                            <Banknote class="h-3 w-3" /> Record Payout
                        </button>
                    </div>
                    <div
                        v-if="!snapshots.length"
                        class="px-4 py-8 text-center text-sm text-muted-foreground"
                    >
                        No snapshots saved yet.
                    </div>
                </div>

                <!-- Desktop table -->
                <table class="hidden w-full text-sm sm:table">
                    <thead
                        class="bg-muted/50 text-xs text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-2 text-left">Period</th>
                            <th class="px-4 py-2 text-left">Basis</th>
                            <th class="px-4 py-2 text-right">Distributable</th>
                            <th class="px-4 py-2 text-right">Members</th>
                            <th class="px-4 py-2 text-right">Company</th>
                            <th class="px-4 py-2 text-left">By</th>
                            <th class="px-4 py-2 text-left">Payout</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="s in snapshots"
                            :key="s.id"
                            class="hover:bg-muted/20"
                        >
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{ s.period_start?.slice(0, 10) }} →
                                {{ s.period_end?.slice(0, 10) }}
                            </td>
                            <td class="px-4 py-2 capitalize">
                                {{ s.distribution_basis }}
                            </td>
                            <td class="px-4 py-2 text-right font-bold">
                                {{ fmt(s.distributable_amount) }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ fmt(s.members_amount) }}
                            </td>
                            <td class="px-4 py-2 text-right text-emerald-600">
                                {{ fmt(s.company_amount) }}
                            </td>
                            <td class="px-4 py-2 text-xs text-muted-foreground">
                                {{ s.creator?.name ?? '—' }}
                            </td>
                            <td class="px-4 py-2">
                                <div
                                    v-if="s.paid_at"
                                    class="flex items-center gap-1 text-xs font-medium whitespace-nowrap text-emerald-600"
                                >
                                    <CheckCircle2
                                        class="h-3.5 w-3.5 shrink-0"
                                    />
                                    {{ s.paid_at?.slice(0, 10) }} ·
                                    {{ s.payer?.name ?? '—' }}
                                </div>
                                <button
                                    v-else
                                    @click="openPayoutModal(s)"
                                    class="flex items-center gap-1 rounded-lg border border-primary/40 px-2.5 py-1 text-xs font-semibold whitespace-nowrap text-primary transition-colors hover:bg-primary/5"
                                >
                                    <Banknote class="h-3 w-3" /> Record Payout
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!snapshots.length">
                            <td
                                colspan="7"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No snapshots saved yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── PAYOUT MODAL ───────────────────────────────────────────────────── -->
        <div
            v-if="payoutModal.open"
            role="dialog"
            aria-modal="true"
            aria-label="Record member payout"
            @keydown.esc="!payoutModal.loading && (payoutModal.open = false)"
            class="sharing-modal fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="!payoutModal.loading && (payoutModal.open = false)"
        >
            <FocusScope
                trapped
                loop
                class="w-full max-w-md space-y-4 rounded-2xl bg-background p-6 shadow-2xl"
            >
                <div class="flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-base font-bold">
                        <Banknote class="h-4 w-4 text-primary" /> Record Payout
                    </h2>
                    <button
                        @click="payoutModal.open = false"
                        :disabled="payoutModal.loading"
                        aria-label="Close payout"
                        class="text-muted-foreground hover:text-foreground"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <!-- Snapshot summary -->
                <div class="space-y-2 rounded-xl bg-muted/40 p-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Period</span
                        ><span class="font-medium"
                            >{{
                                payoutModal.snapshot?.period_start?.slice(0, 10)
                            }}
                            →
                            {{
                                payoutModal.snapshot?.period_end?.slice(0, 10)
                            }}</span
                        >
                    </div>
                    <div class="space-y-1.5 border-t pt-2">
                        <div class="flex items-start justify-between">
                            <span class="text-muted-foreground"
                                >Members (snapshot entitlement)</span
                            >
                            <span class="font-bold text-primary">{{
                                fmt(payoutModal.snapshot?.members_amount)
                            }}</span>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            One transaction per member will be recorded. New
                            snapshots include dividends and incentives; older
                            snapshots retain their original amounts.
                        </p>
                    </div>
                    <div class="space-y-1.5 border-t pt-2">
                        <div class="flex items-start justify-between">
                            <span class="text-muted-foreground"
                                >Company (retained in business)</span
                            >
                            <span class="font-medium text-emerald-600">{{
                                fmt(payoutModal.snapshot?.company_amount)
                            }}</span>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            No cash disbursed — stays as retained earnings.
                        </p>
                    </div>
                </div>

                <!-- Tender select -->
                <div>
                    <label
                        class="mb-1.5 block text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >Payout Tender / Method</label
                    >
                    <select
                        v-model="payoutModal.tenderId"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                    >
                        <option value="" disabled>Select tender…</option>
                        <option v-for="t in tenders" :key="t.id" :value="t.id">
                            {{ t.name }}
                        </option>
                    </select>
                </div>

                <!-- Notes -->
                <div>
                    <label
                        class="mb-1.5 block text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >Notes
                        <span class="font-normal">(optional)</span></label
                    >
                    <textarea
                        v-model="payoutModal.notes"
                        rows="2"
                        placeholder="e.g. Cash payout during partner meeting"
                        class="w-full resize-none rounded-lg border bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                    />
                </div>

                <div class="flex gap-2 pt-1">
                    <button
                        @click="payoutModal.open = false"
                        :disabled="payoutModal.loading"
                        aria-label="Close payout"
                        class="flex-1 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors hover:bg-muted"
                    >
                        Cancel
                    </button>
                    <button
                        @click="submitPayout"
                        :disabled="payoutModal.loading || !payoutModal.tenderId"
                        class="flex flex-1 items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-50"
                    >
                        <RefreshCw
                            v-if="payoutModal.loading"
                            class="h-3.5 w-3.5 animate-spin"
                        />
                        <Banknote v-else class="h-3.5 w-3.5" />
                        Disburse to Members
                    </button>
                </div>
            </FocusScope>
        </div>

        <!-- ── HELP ───────────────────────────────────────────────────────────── -->
        <template v-if="subTab === 'help'">
            <div class="grid gap-4 lg:grid-cols-2">
                <div
                    class="space-y-3 rounded-xl border bg-card p-5 shadow-sm lg:col-span-2"
                >
                    <h3 class="flex items-center gap-2 text-base font-bold">
                        <HelpCircle class="h-5 w-5 text-primary" /> One profit
                        pool, two allocations
                    </h3>
                    <p class="text-sm leading-relaxed text-muted-foreground">
                        Incentives are reserved from available profit first. The
                        remainder is split by equity ownership. New snapshots
                        include both allocations; only member entitlements are
                        paid out.
                    </p>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        <div
                            class="rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-800 dark:bg-blue-950/20"
                        >
                            <p
                                class="mb-1 text-sm font-bold text-blue-700 dark:text-blue-400"
                            >
                                Ownership Dividend
                            </p>
                            <p
                                class="text-xs text-blue-800/80 dark:text-blue-300/80"
                            >
                                Shareholders receive their equity % of the
                                distributable pool. Company gets the remainder.
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/20"
                        >
                            <p
                                class="mb-1 text-sm font-bold text-amber-700 dark:text-amber-400"
                            >
                                Product Ownership Incentive
                            </p>
                            <p
                                class="text-xs text-amber-800/80 dark:text-amber-300/80"
                            >
                                A separate pool (e.g. 2% of gross sales). Each
                                product earns a share proportional to its sales.
                                That share is split among the product's assigned
                                owners. Unowned products' share stays with the
                                company.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="space-y-3 rounded-xl border bg-card p-5 shadow-sm">
                    <h3 class="text-sm font-bold">Worked example</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-[360px] text-sm">
                            <tbody class="[&_td]:py-1 [&_td]:pr-4">
                                <tr>
                                    <td class="text-muted-foreground">
                                        Total Gross Sales
                                    </td>
                                    <td class="text-right font-bold">
                                        ₱1,000,000
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted-foreground">
                                        Incentive Rate
                                    </td>
                                    <td class="text-right font-bold">2%</td>
                                </tr>
                                <tr class="border-t">
                                    <td class="text-muted-foreground">
                                        Incentive Pool
                                    </td>
                                    <td
                                        class="text-right font-bold text-amber-600"
                                    >
                                        ₱20,000
                                    </td>
                                </tr>
                                <tr class="border-t">
                                    <td
                                        colspan="2"
                                        class="pt-2 text-xs font-semibold text-muted-foreground"
                                    >
                                        Classic Burger — Sales ₱250,000 (25%)
                                    </td>
                                </tr>
                                <tr>
                                    <td class="pl-2 text-muted-foreground">
                                        Product Incentive
                                    </td>
                                    <td class="text-right">₱5,000</td>
                                </tr>
                                <tr>
                                    <td class="pl-4 text-muted-foreground">
                                        Shareholder A (60%)
                                    </td>
                                    <td class="text-right text-amber-600">
                                        ₱3,000
                                    </td>
                                </tr>
                                <tr>
                                    <td class="pl-4 text-muted-foreground">
                                        Shareholder B (40%)
                                    </td>
                                    <td class="text-right text-amber-600">
                                        ₱2,000
                                    </td>
                                </tr>
                                <tr class="border-t">
                                    <td
                                        colspan="2"
                                        class="pt-2 text-xs font-semibold text-muted-foreground"
                                    >
                                        Fries — Sales ₱100,000 (10%, no owners)
                                    </td>
                                </tr>
                                <tr>
                                    <td class="pl-2 text-muted-foreground">
                                        Product Incentive → Company
                                    </td>
                                    <td class="text-right text-emerald-600">
                                        ₱2,000
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="space-y-3 rounded-xl border bg-card p-5 shadow-sm">
                    <h3 class="text-sm font-bold">Setup steps</h3>
                    <ol
                        class="list-inside list-decimal space-y-2 text-sm text-muted-foreground"
                    >
                        <li>
                            <strong class="text-foreground"
                                >Add shareholders</strong
                            >
                            — go to Shareholders tab and enter each
                            shareholder's name and equity %.
                        </li>
                        <li>
                            <strong class="text-foreground"
                                >Set incentive rate</strong
                            >
                            — go to Incentives tab, add a rule (e.g. "2% of
                            Gross Sales").
                        </li>
                        <li>
                            <strong class="text-foreground"
                                >Assign product owners</strong
                            >
                            — in the same Incentives tab, find each product and
                            click Edit to assign shareholders with ownership
                            percentages (must sum to 100%).
                        </li>
                        <li>
                            <strong class="text-foreground">Compute</strong> —
                            go to Distribution tab and click Compute for your
                            chosen period.
                        </li>
                        <li>
                            <strong class="text-foreground">Snapshot</strong> —
                            click Snapshot before paying out to create a
                            permanent record.
                        </li>
                    </ol>
                </div>

                <div
                    class="space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-5 lg:col-span-2 dark:border-amber-800 dark:bg-amber-950/20"
                >
                    <h3
                        class="text-sm font-bold text-amber-800 dark:text-amber-300"
                    >
                        Tips
                    </h3>
                    <ul
                        class="list-inside list-disc space-y-1 text-sm text-amber-800/90 dark:text-amber-300/90"
                    >
                        <li>
                            Use <strong>This Week</strong> shortcut for weekly
                            settlements, <strong>Month</strong> for monthly.
                        </li>
                        <li>
                            Only <strong>paid</strong> orders count — matching
                            your Financial reports.
                        </li>
                        <li>
                            A product can be owned by multiple shareholders as
                            long as the percentages total exactly 100%.
                        </li>
                        <li>
                            Products with no assigned owners are automatically
                            company-owned (their incentive share is retained).
                        </li>
                        <li>
                            Multiple incentive rules stack — their pools are
                            added together before distribution.
                        </li>
                    </ul>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
.sharing-page {
    --background: #fffcf6;
    --foreground: #24231e;
    --card: #fffcf6;
    --card-foreground: #24231e;
    --primary: #c3441c;
    --primary-foreground: #fff;
    --muted: #ebe8dd;
    --muted-foreground: #68665f;
    --border: #ded7cb;
    color-scheme: light;
    background: #f6f2e9;
    color: #24231e;
    padding: clamp(16px, 3vw, 36px);
    min-width: 0;
    font-family: Arial, sans-serif;
}
.sharing-heading {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 8px 0 12px;
}
.sharing-eyebrow {
    font-size: 10px;
    letter-spacing: 0.16em;
    font-weight: 700;
    color: #68665f;
}
.sharing-heading h1 {
    font-family: Impact, 'Arial Narrow', sans-serif;
    font-size: clamp(38px, 5vw, 64px);
    line-height: 1.1;
    margin: 10px 0;
    letter-spacing: -0.02em;
}
.sharing-heading h1 em {
    color: #c3441c;
    font-family: Georgia, serif;
    font-weight: 400;
}
.sharing-intro {
    font-size: 14px;
    line-height: 1.6;
    color: #68665f;
}
.sharing-tabs {
    background: #ebe8dd;
    padding: 5px;
    border-radius: 14px;
    scrollbar-width: thin;
}
.sharing-tabs button {
    border: 0;
    border-radius: 10px;
    flex-shrink: 0;
}
.sharing-tabs button[aria-current] {
    background: #24231e;
    color: #fff;
}
.sharing-summary {
    display: grid;
    grid-template-columns: 1.5fr 1fr;
    gap: 24px;
    background: #24231e;
    color: #fffcf6;
    padding: 28px;
    border-radius: 20px;
}
.sharing-summary h2 {
    font:
        italic 28px Georgia,
        serif;
    margin: 10px 0;
}
.sharing-summary p:not(.sharing-eyebrow) {
    color: #ded7cb;
    font-size: 13px;
    line-height: 1.6;
    max-width: 520px;
}
.sharing-summary .sharing-eyebrow,
.sharing-summary small {
    color: #d3ccbd;
}
.sharing-summary > div:last-child {
    border-left: 1px solid #58564e;
    padding-left: 24px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 8px;
}
.sharing-summary span,
.sharing-summary small {
    font-size: 12px;
}
.sharing-summary strong {
    font-size: clamp(24px, 3vw, 38px);
    overflow-wrap: anywhere;
    font-variant-numeric: tabular-nums;
}
.sharing-notice {
    border: 1px solid #d6bd91;
    background: #fff1d9;
    color: #684719;
    padding: 14px 18px;
    border-radius: 12px;
    font-size: 14px;
}
.sharing-page :deep(button),
.sharing-page :deep(input),
.sharing-page :deep(select) {
    min-height: 44px;
}
.sharing-page :deep(button) {
    cursor: pointer;
}
.sharing-page :deep(button:disabled) {
    opacity: 0.45;
    cursor: not-allowed;
}
.sharing-page :deep(:focus-visible) {
    outline: 2px solid #c3441c;
    outline-offset: 3px;
}
.sharing-page :deep(.bg-card) {
    border-radius: 18px;
    box-shadow: 0 3px 14px #24231e04;
}
.sharing-page :deep(.text-blue-600),
.sharing-page :deep(.text-blue-700),
.sharing-page :deep(.text-purple-600) {
    color: #b33d18;
}
.sharing-page :deep(.bg-blue-100),
.sharing-page :deep(.bg-blue-50) {
    background: #f7e5d7;
}
.sharing-page :deep(.text-emerald-600),
.sharing-page :deep(.text-emerald-700) {
    color: #52643c;
}
.sharing-page :deep(.bg-emerald-50) {
    background: #eef0e4;
}
.sharing-page :deep(.text-amber-600) {
    color: #865413;
}
.sharing-page :deep(.text-red-500) {
    color: #b52c24;
}
.sharing-page :deep([class~='text-[10px]']) {
    font-size: 11px;
}
.sharing-page :deep(th) {
    letter-spacing: 0.06em;
    font-size: 10px;
}
.sharing-page :deep(td) {
    font-variant-numeric: tabular-nums;
}
.sharing-page :deep(.overflow-hidden:has(> table)) {
    overflow-x: auto;
}
.sharing-page :deep(.apexcharts-canvas) {
    max-width: 100%;
}
.sharing-modal > div {
    max-height: calc(100dvh - 32px);
    overflow-y: auto;
    overscroll-behavior: contain;
}
@media (max-width: 639px) {
    .sharing-page {
        padding: 16px 12px 28px;
    }
    .sharing-summary {
        grid-template-columns: 1fr;
        padding: 20px;
        gap: 18px;
    }
    .sharing-summary > div:last-child {
        border-left: 0;
        border-top: 1px solid #58564e;
        padding: 18px 0 0;
    }
    .sharing-filters > div {
        width: 100%;
        min-width: 0;
    }
    .sharing-filters input,
    .sharing-filters select {
        width: 100%;
        min-width: 0;
        font-size: 16px;
    }
    .sharing-page :deep(.flex.justify-between) {
        gap: 8px;
        flex-wrap: wrap;
    }
    .sharing-page :deep(.grid > *) {
        min-width: 0;
        overflow-wrap: anywhere;
    }
    .sharing-page :deep(.text-xl) {
        font-size: 19px;
    }
    .sharing-page :deep(.p-4) {
        padding: 14px;
    }
    .sharing-modal {
        align-items: flex-end;
        padding: 8px;
    }
    .sharing-modal > div {
        max-height: calc(100dvh - 16px);
        padding: 20px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .sharing-page *,
    .sharing-page :deep(*) {
        animation: none !important;
        transition: none !important;
    }
}
</style>
