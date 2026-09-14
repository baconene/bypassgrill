<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import type { Auth } from '@/types/auth';
import api from '@/utils/api';

interface Snapshot {
    captured_at: string;
    running_balance: number;
    cumulative_totals: Record<string, number>;
    balance_by_tender: { id: number | null; name: string; balance: number }[];
}
interface Counts {
    drawer_cash: string;
    shift_gcash: string;
    lockbox_total: string;
    total_gcash: string;
}
interface Reconciliation {
    drawer_cash: number;
    shift_gcash: number;
    lockbox_total: number;
    total_gcash: number;
    shift_actual: number;
    shift_net: number;
    shift_variance: number;
    overall_actual: number;
    overall_expected: number;
    overall_variance: number;
    notes: string | null;
}
interface Shift {
    id: number;
    user_id: number;
    user: { name: string };
    opened_at: string;
    closed_at: string | null;
    opening_snapshot: Snapshot;
    closing_snapshot: Snapshot | null;
    reconciliation: Reconciliation | null;
}
const props = withDefaults(defineProps<{ historyView?: boolean }>(), {
    historyView: false,
});
const selectedId = ref<number | null>(null);
const completed = ref<Shift | null>(null);
const page = usePage<{ auth: Auth & { roles: string[] } }>();
const active = ref<Shift | null>(null);
const history = ref<Shift[]>([]);
const historyPage = ref(1);
const lastPage = ref(1);
const loaded = ref(false);
const busy = ref(false);
const error = ref('');
const freshCounts = (): Counts => ({
    drawer_cash: '',
    shift_gcash: '',
    lockbox_total: '',
    total_gcash: '',
});
const counts = ref<Counts>(freshCounts());
const notes = ref('');
const canStart = computed(() =>
    page.props.auth.roles.some((role) => ['cashier', 'admin'].includes(role)),
);
const isOwner = computed(
    () => active.value?.user_id === page.props.auth.user.id,
);
const reports = computed(() =>
    props.historyView
        ? history.value.filter((shift) => shift.id === selectedId.value)
        : active.value?.closed_at
          ? [active.value]
          : completed.value
            ? [completed.value]
            : [],
);
const money = (value: number) =>
    new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(value);
const date = (value: string) => new Date(value).toLocaleString('en-PH');
const validAmount = (value: string) =>
    /^\d+(\.\d{1,2})?$/.test(value) && Number(value) <= 9999999999.99;
const valid = computed(
    () =>
        Object.values(counts.value).every(validAmount) &&
        Number(counts.value.lockbox_total) >= Number(counts.value.drawer_cash),
);
const metrics = [
    { key: 'payment', label: 'Payment income', sign: 1 },
    { key: 'income_adjustment', label: 'Income adjustment', sign: 1 },
    { key: 'expense', label: 'Expense', sign: -1 },
    { key: 'payroll', label: 'Payroll deductions', sign: -1 },
    { key: 'asset_deduction', label: 'Asset deductions', sign: -1 },
    { key: 'payout_share', label: 'Payout shares', sign: -1 },
];
const shiftNet = (shift: Shift) =>
    Math.round(
        ((shift.closing_snapshot?.running_balance ?? 0) -
            shift.opening_snapshot.running_balance) *
            100,
    ) / 100;
const movement = (shift: Shift, key: string) =>
    Math.round(
        ((shift.closing_snapshot?.cumulative_totals[key] ?? 0) -
            (shift.opening_snapshot.cumulative_totals[key] ?? 0)) *
            100,
    ) / 100;
const varianceLabel = (value: number) =>
    value === 0
        ? 'Balanced'
        : `${money(Math.abs(value))} ${value < 0 ? 'short' : 'over'}`;
const varianceColor = (value: number) =>
    value === 0 ? 'text-green-700' : 'text-red-600';

function showError(e: unknown) {
    error.value =
        (e as { response?: { data?: { message?: string } } }).response?.data
            ?.message ?? 'Unable to reach the server. Refresh before retrying.';
}
async function load(targetPage = historyPage.value) {
    const { data } = await api.get('/api/v1/deposit-controls', {
        params: { page: targetPage },
    });
    active.value = data.active;
    history.value = data.history.data;
    historyPage.value = data.history.current_page;
    lastPage.value = data.history.last_page;

    if (!history.value.some((shift) => shift.id === selectedId.value)) {
selectedId.value = null;
}

    loaded.value = true;
}
async function refresh(targetPage = historyPage.value) {
    busy.value = true;
    error.value = '';

    try {
        await load(targetPage);
    } catch (e) {
        showError(e);
    } finally {
        busy.value = false;
    }
}
async function act(action: 'start' | 'close' | 'reconcile') {
    busy.value = true;
    error.value = '';

    try {
        const url =
            action === 'start'
                ? '/api/v1/deposit-controls'
                : `/api/v1/deposit-controls/${active.value?.id}/${action}`;
        const { data } = await api.post(
            url,
            action === 'reconcile'
                ? { ...counts.value, notes: notes.value }
                : {},
        );

        if (action === 'reconcile' && active.value) {
completed.value = { ...data, user: active.value.user };
}

        if (action === 'start') {
completed.value = null;
}

        counts.value = freshCounts();
        notes.value = '';
        await load(1);
    } catch (e) {
        showError(e);
    } finally {
        busy.value = false;
    }
}
onMounted(() => refresh());
</script>

<template>
    <Head :title="historyView ? 'Snapshot History' : 'Deposit Control'" />
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">
                    {{ historyView ? 'Snapshot History' : 'Deposit Control' }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        historyView
                            ? 'Review completed shifts and their saved counts.'
                            : "Check this shift's collections and your overall funds separately."
                    }}
                </p>
            </div>
            <Link
                :href="
                    historyView
                        ? '/deposit-control'
                        : '/deposit-control/history'
                "
                class="rounded-lg border px-4 py-2 text-sm"
                >{{
                    historyView ? 'Back to current shift' : 'Previous snapshots'
                }}</Link
            >
            <button
                class="rounded-lg border px-4 py-2 disabled:opacity-50"
                :disabled="busy"
                @click="refresh()"
            >
                Refresh
            </button>
        </div>
        <p
            v-if="error"
            role="alert"
            class="rounded-lg border border-red-300 bg-red-50 p-4 text-red-800"
        >
            {{ error }}
        </p>
        <section v-if="loaded && !historyView" class="rounded-xl border p-5">
            <template v-if="!active">
                <h2 class="text-lg font-semibold">Start of shift</h2>
                <p class="my-3 text-sm text-muted-foreground">
                    Sync pending payments, then save your opening snapshot. The
                    report appears after closing.
                </p>
                <button
                    v-if="canStart"
                    class="rounded-lg bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50"
                    :disabled="busy"
                    @click="act('start')"
                >
                    Start shift
                </button>
            </template>
            <template v-else>
                <h2 class="text-lg font-semibold">
                    Shift #{{ active.id }} · {{ active.user.name }}
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Opened {{ date(active.opened_at) }}. Opening snapshot saved.
                </p>
                <p v-if="!isOwner" class="mt-3 text-sm">
                    This shift must be completed by {{ active.user.name }}.
                </p>
                <div v-if="!active.closed_at" class="mt-4">
                    <p class="my-3 text-sm text-muted-foreground">
                        At shift end, record and sync all payments and
                        deductions. Close to capture final system balances and
                        view the breakdown.
                    </p>
                    <button
                        v-if="isOwner"
                        class="rounded-lg bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50"
                        :disabled="busy"
                        @click="act('close')"
                    >
                        Close shift &amp; view breakdown
                    </button>
                </div>
            </template>
        </section>

        <div
            v-if="historyView && loaded"
            class="overflow-x-auto rounded-xl border"
        >
            <table class="w-full text-sm whitespace-nowrap">
                <caption class="sr-only">
                    Completed deposit control snapshots, latest first. Select a
                    report for its full breakdown.
                </caption>
                <thead class="bg-muted text-left">
                    <tr>
                        <th class="p-3">Shift / closed</th>
                        <th class="p-3">Cashier</th>
                        <th class="p-3">Drawer cash</th>
                        <th class="p-3">Shift GCash</th>
                        <th class="p-3">Expected net</th>
                        <th class="p-3">Shift over / short</th>
                        <th class="p-3">Lockbox</th>
                        <th class="p-3">Total GCash</th>
                        <th class="p-3">Overall over / short</th>
                        <th class="p-3">Report</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in history"
                        :key="row.id"
                        class="border-t"
                        :class="selectedId === row.id ? 'bg-muted/50' : ''"
                    >
                        <td class="p-3">
                            #{{ row.id
                            }}<span
                                class="block text-xs text-muted-foreground"
                                >{{ date(row.closed_at!) }}</span
                            >
                        </td>
                        <td class="p-3">{{ row.user.name }}</td>
                        <td class="p-3">
                            {{ money(row.reconciliation!.drawer_cash) }}
                        </td>
                        <td class="p-3">
                            {{ money(row.reconciliation!.shift_gcash) }}
                        </td>
                        <td class="p-3">
                            {{ money(row.reconciliation!.shift_net) }}
                        </td>
                        <td
                            class="p-3"
                            :class="
                                varianceColor(
                                    row.reconciliation!.shift_variance,
                                )
                            "
                        >
                            {{
                                varianceLabel(
                                    row.reconciliation!.shift_variance,
                                )
                            }}
                        </td>
                        <td class="p-3">
                            {{ money(row.reconciliation!.lockbox_total) }}
                        </td>
                        <td class="p-3">
                            {{ money(row.reconciliation!.total_gcash) }}
                        </td>
                        <td
                            class="p-3"
                            :class="
                                varianceColor(
                                    row.reconciliation!.overall_variance,
                                )
                            "
                        >
                            {{
                                varianceLabel(
                                    row.reconciliation!.overall_variance,
                                )
                            }}
                        </td>
                        <td class="p-3">
                            <button
                                class="rounded border px-3 py-2"
                                :aria-expanded="selectedId === row.id"
                                @click="
                                    selectedId =
                                        selectedId === row.id ? null : row.id
                                "
                            >
                                {{
                                    selectedId === row.id ? 'Hide' : 'View'
                                }}
                                #{{ row.id }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p
                v-if="history.length === 0"
                class="p-6 text-sm text-muted-foreground"
            >
                No completed snapshots yet.
            </p>
        </div>
        <section
            v-for="shift in reports"
            :key="shift.id"
            class="space-y-5 rounded-xl border p-5"
        >
            <div>
                <h2 class="text-lg font-semibold">
                    Closing report · Shift #{{ shift.id }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ shift.user.name }} · {{ date(shift.opened_at) }} to
                    {{ date(shift.closed_at!) }}
                </p>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <div class="space-y-3 rounded-lg border p-4">
                    <h3 class="font-semibold">1. This shift's net balance</h3>
                    <dl class="space-y-2 text-sm">
                        <div
                            v-for="metric in metrics"
                            :key="metric.key"
                            class="flex justify-between gap-4"
                        >
                            <dt>{{ metric.label }}</dt>
                            <dd>
                                {{
                                    money(
                                        movement(shift, metric.key) *
                                            metric.sign,
                                    )
                                }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between gap-4 border-t pt-2 font-bold"
                        >
                            <dt>Expected net balance</dt>
                            <dd>{{ money(shiftNet(shift)) }}</dd>
                        </div>
                    </dl>
                    <p class="text-xs text-muted-foreground">
                        Payment income plus adjustments, less all deductions,
                        between opening and closing.
                    </p>
                    <template v-if="shift.reconciliation">
                        <dl class="space-y-2 border-t pt-3 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt>Cash in the drawer</dt>
                                <dd>
                                    {{
                                        money(shift.reconciliation.drawer_cash)
                                    }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt>GCash amount for this shift</dt>
                                <dd>
                                    {{
                                        money(shift.reconciliation.shift_gcash)
                                    }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4 font-bold">
                                <dt>Actual combined net balance</dt>
                                <dd>
                                    {{
                                        money(shift.reconciliation.shift_actual)
                                    }}
                                </dd>
                            </div>
                        </dl>
                        <p
                            class="font-semibold"
                            :class="
                                varianceColor(
                                    shift.reconciliation.shift_variance,
                                )
                            "
                        >
                            {{
                                varianceLabel(
                                    shift.reconciliation.shift_variance,
                                )
                            }}
                        </p>
                    </template>
                    <p v-else class="text-sm text-muted-foreground">
                        Enter drawer cash and shift GCash below to compare.
                    </p>
                </div>
                <div class="space-y-3 rounded-lg border p-4">
                    <h3 class="font-semibold">2. Overall balance</h3>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt>Opening running balance</dt>
                            <dd>
                                {{
                                    money(
                                        shift.opening_snapshot.running_balance,
                                    )
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt>This shift's net balance</dt>
                            <dd>{{ money(shiftNet(shift)) }}</dd>
                        </div>
                        <div
                            class="flex justify-between gap-4 border-t pt-2 font-bold"
                        >
                            <dt>Expected overall balance</dt>
                            <dd>
                                {{
                                    money(
                                        shift.closing_snapshot!.running_balance,
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
                    <p class="text-xs text-muted-foreground">
                        All funds accumulated through closing, including prior
                        shifts.
                    </p>
                    <template v-if="shift.reconciliation">
                        <dl class="space-y-2 border-t pt-3 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt>Counted lockbox total</dt>
                                <dd>
                                    {{
                                        money(
                                            shift.reconciliation.lockbox_total,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt>Total GCash wallet value</dt>
                                <dd>
                                    {{
                                        money(shift.reconciliation.total_gcash)
                                    }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4 font-bold">
                                <dt>Actual overall balance</dt>
                                <dd>
                                    {{
                                        money(
                                            shift.reconciliation.overall_actual,
                                        )
                                    }}
                                </dd>
                            </div>
                        </dl>
                        <p
                            class="font-semibold"
                            :class="
                                varianceColor(
                                    shift.reconciliation.overall_variance,
                                )
                            "
                        >
                            {{
                                varianceLabel(
                                    shift.reconciliation.overall_variance,
                                )
                            }}
                        </p>
                    </template>
                    <p v-else class="text-sm text-muted-foreground">
                        Enter your full lockbox count and total GCash wallet
                        value below.
                    </p>
                </div>
            </div>
            <form
                v-if="
                    shift.id === active?.id && isOwner && !shift.reconciliation
                "
                class="space-y-4 border-t pt-5"
                @submit.prevent="act('reconcile')"
            >
                <h3 class="font-semibold">Enter actual balances</h3>
                <fieldset :disabled="busy" class="grid gap-5 md:grid-cols-2">
                    <div class="space-y-4 rounded-lg bg-muted/40 p-4">
                        <p class="font-medium">This shift only</p>
                        <label class="block text-sm"
                            >Cash in the drawer (PHP)<input
                                v-model="counts.drawer_cash"
                                required
                                type="number"
                                min="0"
                                max="9999999999.99"
                                step="0.01"
                                class="mt-1 block w-full rounded-lg border bg-background p-2"
                        /></label>
                        <label class="block text-sm"
                            >GCash amount for this shift (PHP)<input
                                v-model="counts.shift_gcash"
                                required
                                type="number"
                                min="0"
                                max="9999999999.99"
                                step="0.01"
                                class="mt-1 block w-full rounded-lg border bg-background p-2"
                        /></label>
                        <p class="text-xs text-muted-foreground">
                            Count the shift's net cash before moving it to the
                            lockbox. Exclude any opening cash float. Enter only
                            this shift's net GCash collections, after
                            deductions.
                        </p>
                    </div>
                    <div class="space-y-4 rounded-lg bg-muted/40 p-4">
                        <p class="font-medium">All funds at closing</p>
                        <label class="block text-sm"
                            >Manually counted lockbox total (PHP)<input
                                v-model="counts.lockbox_total"
                                required
                                type="number"
                                :min="counts.drawer_cash || 0"
                                max="9999999999.99"
                                step="0.01"
                                class="mt-1 block w-full rounded-lg border bg-background p-2"
                        /></label>
                        <label class="block text-sm"
                            >Total GCash wallet value (PHP)<input
                                v-model="counts.total_gcash"
                                required
                                type="number"
                                min="0"
                                max="9999999999.99"
                                step="0.01"
                                class="mt-1 block w-full rounded-lg border bg-background p-2"
                        /></label>
                        <p class="text-xs text-muted-foreground">
                            Move drawer cash into the lockbox, then count the
                            entire lockbox. Enter the full GCash wallet balance.
                            These totals already include this shift's amounts.
                        </p>
                    </div>
                </fieldset>
                <label class="block text-sm"
                    >Notes<textarea
                        v-model="notes"
                        :disabled="busy"
                        maxlength="2000"
                        class="mt-1 block w-full rounded-lg border bg-background p-2"
                    />
                </label>
                <p class="text-sm text-muted-foreground">
                    Review all four amounts. Saving completes the shift and
                    makes the counts final.
                </p>
                <button
                    class="rounded-lg bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50"
                    :disabled="busy || !valid"
                >
                    Save actual balances &amp; complete report
                </button>
            </form>
            <p
                v-if="shift.reconciliation?.notes"
                class="text-sm whitespace-pre-wrap"
            >
                {{ shift.reconciliation.notes }}
            </p>
            <details class="border-t pt-3">
                <summary class="cursor-pointer text-sm font-medium">
                    System balances by tender
                </summary>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left">
                                <th class="p-2">Tender</th>
                                <th class="p-2">Closing balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="t in shift.closing_snapshot!
                                    .balance_by_tender"
                                :key="t.id ?? 'untagged'"
                                class="border-b"
                            >
                                <td class="p-2">{{ t.name }}</td>
                                <td class="p-2">{{ money(t.balance) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-muted-foreground">
                    System totals include every tender and untagged entries.
                    Actual counts above cover Cash and GCash; other tenders can
                    leave an unexplained difference. Backdated ledger changes
                    between snapshots also affect this shift's net balance.
                </p>
            </details>
        </section>
        <p
            v-if="loaded && !historyView && reports.length === 0"
            class="text-sm text-muted-foreground"
        >
            No closing reports yet.
        </p>
        <div v-if="historyView && lastPage > 1" class="flex items-center gap-4">
            <button
                :disabled="busy || historyPage === 1"
                class="rounded border px-3 py-2 disabled:opacity-50"
                @click="refresh(historyPage - 1)"
            >
                Previous</button
            ><span>History page {{ historyPage }} of {{ lastPage }}</span
            ><button
                :disabled="busy || historyPage === lastPage"
                class="rounded border px-3 py-2 disabled:opacity-50"
                @click="refresh(historyPage + 1)"
            >
                Next
            </button>
        </div>
    </div>
</template>
