<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Banknote,
    Check,
    ChevronDown,
    ClipboardCheck,
    Clock,
    History,
    Lock,
    Play,
    RefreshCw,
    Scale,
    Smartphone,
    TriangleAlert,
    Wallet,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import type { Auth } from '@/types/auth';
import api from '@/utils/api';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Deposit Control', href: '/deposit-control' }],
    },
});

interface Snapshot {
    captured_at: string;
    running_balance: number;
    cumulative_totals: Record<string, number>;
    balance_by_tender: { id: number | null; name: string; balance: number }[];
    opening_cash?: number;
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
type Tone = 'pending' | 'balanced' | 'short' | 'over';

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
const confirmClose = ref(false);
const confirmSave = ref(false);
const now = ref(Date.now());
const freshCounts = (): Counts => ({
    drawer_cash: '',
    shift_gcash: '',
    lockbox_total: '',
    total_gcash: '',
});
const counts = ref<Counts>(freshCounts());
const notes = ref('');
const openingCash = ref('');
const openingCashValid = computed(() => validAmount(openingCash.value));
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
const time = (value: string) =>
    new Date(value).toLocaleTimeString('en-PH', {
        hour: 'numeric',
        minute: '2-digit',
    });
const elapsed = (from: string, to?: string | null) => {
    const end = to ? new Date(to).getTime() : now.value;
    const minutes = Math.max(
        0,
        Math.floor((end - new Date(from).getTime()) / 60000),
    );
    const hours = Math.floor(minutes / 60);

    return hours ? `${hours}h ${minutes % 60}m` : `${minutes}m`;
};
const validAmount = (value: string) =>
    /^\d+(\.\d{1,2})?$/.test(value) && Number(value) <= 9999999999.99;
const cents = (value: string | number) => Math.round(Number(value) * 100);
const lockboxError = computed(() =>
    validAmount(counts.value.lockbox_total) &&
    validAmount(counts.value.drawer_cash) &&
    Number(counts.value.lockbox_total) < Number(counts.value.drawer_cash)
        ? 'The lockbox total includes the drawer cash, so it cannot be lower.'
        : '',
);
const valid = computed(
    () => Object.values(counts.value).every(validAmount) && !lockboxError.value,
);
const metrics = [
    { key: 'payment', label: 'Payment income', sign: 1 },
    { key: 'income_adjustment', label: 'Income adjustment', sign: 1 },
    { key: 'expense', label: 'Expense', sign: -1 },
    { key: 'payroll', label: 'Payroll deductions', sign: -1 },
    { key: 'asset_deduction', label: 'Asset deductions', sign: -1 },
    { key: 'payout_share', label: 'Payout shares', sign: -1 },
];
const steps = [
    { title: 'Start shift', hint: 'Count the opening cash' },
    { title: 'Close shift', hint: 'Capture final balances' },
    { title: 'Count & submit', hint: 'Compare actual funds' },
];
const currentStep = computed(() =>
    !active.value ? 1 : !active.value.closed_at ? 2 : 3,
);
const stepState = (step: number) =>
    step < currentStep.value
        ? 'done'
        : step === currentStep.value
          ? 'current'
          : 'upcoming';
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
const tone = (value: number | null | undefined): Tone =>
    value == null
        ? 'pending'
        : value === 0
          ? 'balanced'
          : value < 0
            ? 'short'
            : 'over';

// Mirrors DepositReconciliation so the cashier sees each result while typing.
const livePreview = computed(() => {
    const shift = active.value;

    if (!shift?.closing_snapshot) {
        return null;
    }

    const c = counts.value;
    const shiftReady = validAmount(c.drawer_cash) && validAmount(c.shift_gcash);
    const overallReady =
        validAmount(c.lockbox_total) && validAmount(c.total_gcash);
    const shiftActual = cents(c.drawer_cash) + cents(c.shift_gcash);
    const overallActual = cents(c.lockbox_total) + cents(c.total_gcash);
    const overallExpected = shift.closing_snapshot.running_balance;

    return {
        shiftExpected: shiftNet(shift),
        shiftActual: shiftReady ? shiftActual / 100 : null,
        shiftVariance: shiftReady
            ? (shiftActual - cents(shiftNet(shift))) / 100
            : null,
        overallExpected,
        overallActual: overallReady ? overallActual / 100 : null,
        overallVariance: overallReady
            ? (overallActual - cents(overallExpected)) / 100
            : null,
    };
});
const historyStats = computed(() => ({
    balanced: history.value.filter(
        (shift) =>
            shift.reconciliation?.shift_variance === 0 &&
            shift.reconciliation?.overall_variance === 0,
    ).length,
    short: history.value.filter(
        (shift) =>
            (shift.reconciliation?.shift_variance ?? 0) < 0 ||
            (shift.reconciliation?.overall_variance ?? 0) < 0,
    ).length,
}));

watch(counts, () => (confirmSave.value = false), { deep: true });

function scrollToId(id: string) {
    const reduce = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    document.getElementById(id)?.scrollIntoView({
        behavior: reduce ? 'auto' : 'smooth',
        block: 'start',
    });
}
async function toggleReport(id: number) {
    selectedId.value = selectedId.value === id ? null : id;

    if (selectedId.value) {
        await nextTick();
        scrollToId(`report-${id}`);
    }
}
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
        const payload =
            action === 'reconcile'
                ? { ...counts.value, notes: notes.value }
                : action === 'start'
                  ? { opening_cash: openingCash.value }
                  : {};
        const { data } = await api.post(url, payload);

        if (action === 'reconcile' && active.value) {
            completed.value = { ...data, user: active.value.user };
        }

        if (action === 'start') {
            completed.value = null;
            openingCash.value = '';
        }

        counts.value = freshCounts();
        notes.value = '';
        confirmClose.value = false;
        await load(1);
        confirmSave.value = false;
        await nextTick();

        if (action === 'close') {
            scrollToId('counts');
            document
                .getElementById('drawer-cash')
                ?.focus({ preventScroll: true });
        } else if (action === 'reconcile' && completed.value) {
            scrollToId(`report-${completed.value.id}`);
        }
    } catch (e) {
        showError(e);
    } finally {
        busy.value = false;
    }
}
function submitCounts() {
    if (!valid.value) {
        return;
    }

    if (!confirmSave.value) {
        confirmSave.value = true;

        return;
    }

    act('reconcile');
}

let clock: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    refresh();
    clock = setInterval(() => (now.value = Date.now()), 30000);
});
onUnmounted(() => clearInterval(clock));
</script>

<template>
    <Head :title="historyView ? 'Snapshot History' : 'Deposit Control'" />
    <div class="grill-page">
        <header class="page-heading">
            <div>
                <p class="eyebrow">
                    <Wallet :size="14" aria-hidden="true" /> BYPASS GRILL /
                    DEPOSIT CONTROL
                </p>
                <h1 v-if="historyView">Previous <em>snapshots.</em></h1>
                <h1 v-else>Start. Count. <em>Close.</em></h1>
                <p class="intro">
                    {{
                        historyView
                            ? 'Review completed shifts, their saved counts, and any over or short amounts.'
                            : "Check this shift's collections and your overall funds separately."
                    }}
                </p>
            </div>
            <div class="heading-meta">
                <button
                    class="refresh-button"
                    :disabled="busy"
                    @click="refresh()"
                >
                    <RefreshCw
                        :size="14"
                        :class="{ spinning: busy }"
                        aria-hidden="true"
                    />{{ busy ? 'Refreshing…' : 'Refresh' }}
                </button>
            </div>
        </header>

        <nav class="view-tabs" aria-label="Deposit control views">
            <Link
                href="/deposit-control"
                :class="{ active: !historyView }"
                :aria-current="historyView ? undefined : 'page'"
                ><Wallet :size="15" aria-hidden="true" />Current shift</Link
            ><Link
                href="/deposit-control/history"
                :class="{ active: historyView }"
                :aria-current="historyView ? 'page' : undefined"
                ><History :size="15" aria-hidden="true" />Previous
                snapshots</Link
            >
        </nav>

        <p v-if="error" class="page-error" role="alert">
            <TriangleAlert :size="16" aria-hidden="true" />{{ error }}
        </p>
        <div v-if="!loaded && !error" class="panel loading" role="status">
            Loading deposit control…
        </div>

        <template v-if="loaded && !historyView">
            <ol class="stepper" aria-label="Shift progress">
                <li
                    v-for="(step, index) in steps"
                    :key="step.title"
                    :class="stepState(index + 1)"
                    :aria-current="
                        stepState(index + 1) === 'current' ? 'step' : undefined
                    "
                >
                    <span class="step-dot"
                        ><Check
                            v-if="stepState(index + 1) === 'done'"
                            :size="14"
                            aria-hidden="true"
                        /><template v-else>{{ index + 1 }}</template></span
                    >
                    <div>
                        <strong>{{ step.title }}</strong
                        ><small>{{ step.hint }}</small>
                    </div>
                </li>
            </ol>

            <section class="work-bar" aria-label="Current step">
                <template v-if="!active">
                    <div class="work-bar-copy">
                        <strong>{{
                            completed
                                ? 'Shift complete. Ready for the next one?'
                                : 'Ready to open the drawer?'
                        }}</strong
                        ><span
                            >Count the cash already in the drawer, enter it,
                            then start the shift. The report appears after
                            closing.</span
                        >
                    </div>
                    <form
                        v-if="canStart"
                        class="work-form"
                        @submit.prevent="openingCashValid && act('start')"
                    >
                        <label for="opening-cash"
                            >Cash in drawer at start</label
                        >
                        <div class="work-form-row">
                            <div class="money-input dark">
                                <span aria-hidden="true">₱</span
                                ><input
                                    id="opening-cash"
                                    v-model="openingCash"
                                    type="number"
                                    inputmode="decimal"
                                    min="0"
                                    step="0.01"
                                    placeholder="0.00"
                                    required
                                />
                            </div>
                            <button
                                class="primary-action"
                                :disabled="busy || !openingCashValid"
                            >
                                <Play :size="16" aria-hidden="true" />Start
                                shift
                            </button>
                        </div>
                    </form>
                    <p v-else class="work-note">
                        Only cashiers and admins can start a shift.
                    </p>
                </template>

                <template v-else-if="!active.closed_at">
                    <div class="work-bar-copy">
                        <strong>Shift #{{ active.id }} is open</strong
                        ><span
                            >{{ active.user.name }} · opened
                            {{ time(active.opened_at) }} · running
                            {{ elapsed(active.opened_at) }}</span
                        >
                    </div>
                    <div v-if="isOwner" class="work-actions">
                        <button
                            v-if="!confirmClose"
                            class="primary-action"
                            :disabled="busy"
                            @click="confirmClose = true"
                        >
                            <Lock :size="16" aria-hidden="true" />Close shift
                        </button>
                        <div
                            v-else
                            class="confirm-box"
                            role="alertdialog"
                            aria-labelledby="close-confirm-text"
                        >
                            <p id="close-confirm-text">
                                Have you recorded and synced every payment and
                                deduction? Closing saves the final balances and
                                cannot be undone.
                            </p>
                            <div>
                                <button
                                    class="primary-action"
                                    :disabled="busy"
                                    @click="act('close')"
                                >
                                    Yes, close shift
                                </button>
                                <button
                                    class="secondary-action"
                                    :disabled="busy"
                                    @click="confirmClose = false"
                                >
                                    Not yet
                                </button>
                            </div>
                        </div>
                    </div>
                    <p v-else class="work-note">
                        Only {{ active.user.name }} can close this shift.
                    </p>
                </template>

                <template v-else>
                    <div class="work-bar-copy">
                        <strong>Shift closed. Count the money.</strong
                        ><span
                            >Final balances are saved. Enter the four actual
                            amounts below to complete the report.</span
                        >
                    </div>
                    <div class="work-actions">
                        <button
                            v-if="isOwner"
                            class="primary-action"
                            @click="scrollToId('counts')"
                        >
                            <ClipboardCheck
                                :size="16"
                                aria-hidden="true"
                            />Enter counts
                        </button>
                        <p v-else class="work-note">
                            Only {{ active.user.name }} can enter the counts.
                        </p>
                    </div>
                </template>
            </section>

            <section
                v-if="active"
                class="metric-grid"
                aria-label="Shift details"
            >
                <article class="metric">
                    <p>Cashier</p>
                    <strong class="metric-text">{{ active.user.name }}</strong
                    ><span>Shift #{{ active.id }}</span>
                </article>
                <article class="metric">
                    <p><Clock :size="12" aria-hidden="true" /> Duration</p>
                    <strong>{{
                        elapsed(active.opened_at, active.closed_at)
                    }}</strong
                    ><span
                        >{{ time(active.opened_at) }}
                        {{
                            active.closed_at
                                ? `to ${time(active.closed_at)}`
                                : 'to now'
                        }}</span
                    >
                </article>
                <article class="metric">
                    <p>Opening cash in drawer</p>
                    <strong>{{
                        active.opening_snapshot.opening_cash != null
                            ? money(active.opening_snapshot.opening_cash)
                            : '—'
                    }}</strong
                    ><span>Float counted at start</span>
                </article>
                <article class="metric metric-featured">
                    <p>Opening running balance</p>
                    <strong>{{
                        money(active.opening_snapshot.running_balance)
                    }}</strong
                    ><span>All funds recorded at opening</span>
                </article>
            </section>

            <section v-if="!active && !completed" class="panel empty-state">
                <Scale :size="28" aria-hidden="true" />
                <h2>No open shift.</h2>
                <p>
                    The closing report appears here after you start and close a
                    shift.
                </p>
            </section>
        </template>

        <template v-if="loaded && historyView">
            <section
                v-if="history.length"
                class="metric-grid three"
                aria-label="Summary for this page"
            >
                <article class="metric">
                    <p>Shifts on this page</p>
                    <strong>{{ history.length }}</strong
                    ><span>Page {{ historyPage }} of {{ lastPage }}</span>
                </article>
                <article class="metric">
                    <p>Fully balanced</p>
                    <strong class="text-balanced">{{
                        historyStats.balanced
                    }}</strong
                    ><span>Both checks matched exactly</span>
                </article>
                <article class="metric">
                    <p>With a shortage</p>
                    <strong
                        :class="
                            historyStats.short ? 'text-short' : 'text-balanced'
                        "
                        >{{ historyStats.short }}</strong
                    ><span>Shift or overall count came up short</span>
                </article>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">COMPLETED SHIFTS</p>
                        <h2>Snapshot history</h2>
                    </div>
                    <History :size="20" class="muted-icon" aria-hidden="true" />
                </div>
                <div
                    v-if="history.length"
                    class="table-scroll"
                    tabindex="0"
                    role="region"
                    aria-label="Completed deposit control snapshots"
                >
                    <table>
                        <caption class="sr-only">
                            Completed deposit control snapshots, latest first.
                            Select a row for its full report.
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">Shift / closed</th>
                                <th scope="col">Cashier</th>
                                <th scope="col" class="amount">Drawer cash</th>
                                <th scope="col" class="amount">Shift GCash</th>
                                <th scope="col" class="amount">Expected net</th>
                                <th scope="col">Shift check</th>
                                <th scope="col" class="amount">Lockbox</th>
                                <th scope="col" class="amount">Total GCash</th>
                                <th scope="col">Overall check</th>
                                <th scope="col">
                                    <span class="sr-only">Report</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in history"
                                :key="row.id"
                                class="clickable"
                                :class="{ selected: selectedId === row.id }"
                                @click="toggleReport(row.id)"
                            >
                                <td>
                                    <strong>#{{ row.id }}</strong
                                    ><small>{{ date(row.closed_at!) }}</small>
                                </td>
                                <td>{{ row.user.name }}</td>
                                <td class="amount">
                                    {{ money(row.reconciliation!.drawer_cash) }}
                                </td>
                                <td class="amount">
                                    {{ money(row.reconciliation!.shift_gcash) }}
                                </td>
                                <td class="amount">
                                    {{ money(row.reconciliation!.shift_net) }}
                                </td>
                                <td>
                                    <span
                                        class="pill"
                                        :class="`pill-${tone(row.reconciliation!.shift_variance)}`"
                                        >{{
                                            varianceLabel(
                                                row.reconciliation!
                                                    .shift_variance,
                                            )
                                        }}</span
                                    >
                                </td>
                                <td class="amount">
                                    {{
                                        money(row.reconciliation!.lockbox_total)
                                    }}
                                </td>
                                <td class="amount">
                                    {{ money(row.reconciliation!.total_gcash) }}
                                </td>
                                <td>
                                    <span
                                        class="pill"
                                        :class="`pill-${tone(row.reconciliation!.overall_variance)}`"
                                        >{{
                                            varianceLabel(
                                                row.reconciliation!
                                                    .overall_variance,
                                            )
                                        }}</span
                                    >
                                </td>
                                <td>
                                    <button
                                        class="row-toggle"
                                        :aria-expanded="selectedId === row.id"
                                        :aria-label="`${selectedId === row.id ? 'Hide' : 'View'} report for shift ${row.id}`"
                                        @click.stop="toggleReport(row.id)"
                                    >
                                        {{
                                            selectedId === row.id
                                                ? 'Hide'
                                                : 'View'
                                        }}
                                        <ChevronDown
                                            :size="14"
                                            :class="{
                                                flipped: selectedId === row.id,
                                            }"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="empty-state">
                    <History :size="26" aria-hidden="true" />
                    <h3>No completed snapshots yet.</h3>
                    <p>Shifts appear here once their counts are saved.</p>
                </div>
                <div v-if="lastPage > 1" class="pager">
                    <button
                        :disabled="busy || historyPage === 1"
                        @click="refresh(historyPage - 1)"
                    >
                        Previous
                    </button>
                    <span>Page {{ historyPage }} of {{ lastPage }}</span>
                    <button
                        :disabled="busy || historyPage === lastPage"
                        @click="refresh(historyPage + 1)"
                    >
                        Next
                    </button>
                </div>
            </section>
        </template>

        <section
            v-for="shift in reports"
            :id="`report-${shift.id}`"
            :key="shift.id"
            class="panel report"
        >
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">CLOSING REPORT</p>
                    <h2>Shift #{{ shift.id }}</h2>
                    <p class="panel-sub">
                        {{ shift.user.name }} · {{ date(shift.opened_at) }} to
                        {{ date(shift.closed_at!) }}
                    </p>
                </div>
                <button
                    v-if="historyView"
                    class="text-link"
                    @click="toggleReport(shift.id)"
                >
                    Hide report
                </button>
            </div>

            <div class="check-grid">
                <article
                    class="check-card"
                    :class="`card-${tone(shift.reconciliation?.shift_variance)}`"
                >
                    <header>
                        <div>
                            <p class="eyebrow">CHECK 1</p>
                            <h3>This shift's net balance</h3>
                        </div>
                        <span
                            class="pill"
                            :class="`pill-${tone(shift.reconciliation?.shift_variance)}`"
                            >{{
                                shift.reconciliation
                                    ? varianceLabel(
                                          shift.reconciliation.shift_variance,
                                      )
                                    : 'Awaiting counts'
                            }}</span
                        >
                    </header>
                    <dl class="summary-list">
                        <div
                            v-for="metric in metrics"
                            :key="metric.key"
                            :class="{
                                'is-zero': movement(shift, metric.key) === 0,
                            }"
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
                        <div class="summary-total">
                            <dt>Expected net balance</dt>
                            <dd>{{ money(shiftNet(shift)) }}</dd>
                        </div>
                    </dl>
                    <dl v-if="shift.reconciliation" class="summary-list actual">
                        <div>
                            <dt>
                                <Banknote :size="13" aria-hidden="true" />Cash
                                in the drawer
                            </dt>
                            <dd>
                                {{ money(shift.reconciliation.drawer_cash) }}
                            </dd>
                        </div>
                        <div>
                            <dt>
                                <Smartphone
                                    :size="13"
                                    aria-hidden="true"
                                />GCash for this shift
                            </dt>
                            <dd>
                                {{ money(shift.reconciliation.shift_gcash) }}
                            </dd>
                        </div>
                        <div class="summary-total">
                            <dt>Actual combined net</dt>
                            <dd>
                                {{ money(shift.reconciliation.shift_actual) }}
                            </dd>
                        </div>
                    </dl>
                    <p class="card-note">
                        Payment income plus adjustments, less all deductions,
                        between opening and closing.
                    </p>
                </article>

                <article
                    class="check-card"
                    :class="`card-${tone(shift.reconciliation?.overall_variance)}`"
                >
                    <header>
                        <div>
                            <p class="eyebrow">CHECK 2</p>
                            <h3>Overall balance</h3>
                        </div>
                        <span
                            class="pill"
                            :class="`pill-${tone(shift.reconciliation?.overall_variance)}`"
                            >{{
                                shift.reconciliation
                                    ? varianceLabel(
                                          shift.reconciliation.overall_variance,
                                      )
                                    : 'Awaiting counts'
                            }}</span
                        >
                    </header>
                    <dl class="summary-list">
                        <div>
                            <dt>Opening running balance</dt>
                            <dd>
                                {{
                                    money(
                                        shift.opening_snapshot.running_balance,
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt>This shift's net balance</dt>
                            <dd>{{ money(shiftNet(shift)) }}</dd>
                        </div>
                        <div class="summary-total">
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
                    <dl v-if="shift.reconciliation" class="summary-list actual">
                        <div>
                            <dt>
                                <Lock :size="13" aria-hidden="true" />Counted
                                lockbox total
                            </dt>
                            <dd>
                                {{ money(shift.reconciliation.lockbox_total) }}
                            </dd>
                        </div>
                        <div>
                            <dt>
                                <Smartphone
                                    :size="13"
                                    aria-hidden="true"
                                />Total GCash wallet
                            </dt>
                            <dd>
                                {{ money(shift.reconciliation.total_gcash) }}
                            </dd>
                        </div>
                        <div class="summary-total">
                            <dt>Actual overall balance</dt>
                            <dd>
                                {{ money(shift.reconciliation.overall_actual) }}
                            </dd>
                        </div>
                    </dl>
                    <p class="card-note">
                        All funds accumulated through closing, including prior
                        shifts.
                    </p>
                </article>
            </div>

            <form
                v-if="
                    shift.id === active?.id && isOwner && !shift.reconciliation
                "
                id="counts"
                class="counts-form"
                @submit.prevent="submitCounts"
            >
                <div class="form-heading">
                    <p class="eyebrow">STEP 3</p>
                    <h3>Enter actual balances</h3>
                    <p>
                        Each check updates as you type, so you can recount
                        before saving.
                    </p>
                </div>
                <fieldset :disabled="busy" class="count-grid">
                    <div
                        class="count-group"
                        role="group"
                        aria-labelledby="shift-group-title"
                    >
                        <h4 id="shift-group-title">This shift only</h4>
                        <label for="drawer-cash">Cash in the drawer</label>
                        <div class="money-input">
                            <span aria-hidden="true">₱</span
                            ><input
                                id="drawer-cash"
                                v-model="counts.drawer_cash"
                                required
                                type="number"
                                inputmode="decimal"
                                min="0"
                                max="9999999999.99"
                                step="0.01"
                                placeholder="0.00"
                                aria-describedby="drawer-hint"
                            />
                        </div>
                        <small id="drawer-hint" class="field-hint"
                            >This shift's net cash, before moving it to the
                            lockbox.<template
                                v-if="shift.opening_snapshot.opening_cash"
                            >
                                Leave out the
                                {{ money(shift.opening_snapshot.opening_cash) }}
                                opening float.</template
                            ></small
                        >
                        <label for="shift-gcash">GCash for this shift</label>
                        <div class="money-input">
                            <span aria-hidden="true">₱</span
                            ><input
                                id="shift-gcash"
                                v-model="counts.shift_gcash"
                                required
                                type="number"
                                inputmode="decimal"
                                min="0"
                                max="9999999999.99"
                                step="0.01"
                                placeholder="0.00"
                                aria-describedby="gcash-hint"
                            />
                        </div>
                        <small id="gcash-hint" class="field-hint"
                            >Only this shift's net GCash collections, after
                            deductions.</small
                        >
                        <div
                            v-if="livePreview"
                            class="live-check"
                            :class="`live-${tone(livePreview.shiftVariance)}`"
                            aria-live="polite"
                        >
                            <span
                                >Expected
                                <b>{{
                                    money(livePreview.shiftExpected)
                                }}</b></span
                            ><span
                                >Counted
                                <b>{{
                                    livePreview.shiftActual == null
                                        ? '—'
                                        : money(livePreview.shiftActual)
                                }}</b></span
                            ><strong>{{
                                livePreview.shiftVariance == null
                                    ? 'Enter both amounts'
                                    : varianceLabel(livePreview.shiftVariance)
                            }}</strong>
                        </div>
                    </div>

                    <div
                        class="count-group"
                        role="group"
                        aria-labelledby="overall-group-title"
                    >
                        <h4 id="overall-group-title">All funds at closing</h4>
                        <label for="lockbox-total"
                            >Manually counted lockbox total</label
                        >
                        <div
                            class="money-input"
                            :class="{ invalid: lockboxError }"
                        >
                            <span aria-hidden="true">₱</span
                            ><input
                                id="lockbox-total"
                                v-model="counts.lockbox_total"
                                required
                                type="number"
                                inputmode="decimal"
                                :min="counts.drawer_cash || 0"
                                max="9999999999.99"
                                step="0.01"
                                placeholder="0.00"
                                :aria-invalid="!!lockboxError"
                                aria-describedby="lockbox-hint"
                            />
                        </div>
                        <small
                            id="lockbox-hint"
                            class="field-hint"
                            :class="{ 'field-error': lockboxError }"
                            >{{
                                lockboxError ||
                                'Move the drawer cash into the lockbox first, then count everything inside.'
                            }}</small
                        >
                        <label for="total-gcash"
                            >Total GCash wallet value</label
                        >
                        <div class="money-input">
                            <span aria-hidden="true">₱</span
                            ><input
                                id="total-gcash"
                                v-model="counts.total_gcash"
                                required
                                type="number"
                                inputmode="decimal"
                                min="0"
                                max="9999999999.99"
                                step="0.01"
                                placeholder="0.00"
                                aria-describedby="total-gcash-hint"
                            />
                        </div>
                        <small id="total-gcash-hint" class="field-hint"
                            >The full wallet balance, including earlier
                            shifts.</small
                        >
                        <div
                            v-if="livePreview"
                            class="live-check"
                            :class="`live-${tone(livePreview.overallVariance)}`"
                            aria-live="polite"
                        >
                            <span
                                >Expected
                                <b>{{
                                    money(livePreview.overallExpected)
                                }}</b></span
                            ><span
                                >Counted
                                <b>{{
                                    livePreview.overallActual == null
                                        ? '—'
                                        : money(livePreview.overallActual)
                                }}</b></span
                            ><strong>{{
                                livePreview.overallVariance == null
                                    ? 'Enter both amounts'
                                    : varianceLabel(livePreview.overallVariance)
                            }}</strong>
                        </div>
                    </div>
                </fieldset>
                <label for="count-notes" class="notes-label"
                    >Notes <span>(optional)</span></label
                >
                <textarea
                    id="count-notes"
                    v-model="notes"
                    :disabled="busy"
                    maxlength="2000"
                    rows="3"
                    placeholder="Explain any over or short amount."
                />

                <div v-if="confirmSave" class="final-confirm" role="alert">
                    <TriangleAlert :size="18" aria-hidden="true" />
                    <div>
                        <strong>Saved counts are final.</strong>
                        <p>
                            Drawer {{ money(Number(counts.drawer_cash)) }} ·
                            Shift GCash
                            {{ money(Number(counts.shift_gcash)) }} · Lockbox
                            {{ money(Number(counts.lockbox_total)) }} · Wallet
                            {{ money(Number(counts.total_gcash)) }}
                        </p>
                    </div>
                </div>
                <div class="form-actions">
                    <button
                        type="submit"
                        class="primary-action"
                        :disabled="busy || !valid"
                    >
                        <Check :size="16" aria-hidden="true" />{{
                            confirmSave ? 'Save final counts' : 'Review & save'
                        }}
                    </button>
                    <button
                        v-if="confirmSave"
                        type="button"
                        class="ghost-action"
                        :disabled="busy"
                        @click="confirmSave = false"
                    >
                        Edit amounts
                    </button>
                    <small v-else-if="!valid" class="field-hint"
                        >Fill in all four amounts to continue.</small
                    >
                </div>
            </form>

            <div v-if="shift.reconciliation?.notes" class="saved-notes">
                <p class="eyebrow">NOTES</p>
                <p>{{ shift.reconciliation.notes }}</p>
            </div>

            <details class="tender-details">
                <summary>
                    System balances by tender
                    <ChevronDown :size="15" aria-hidden="true" />
                </summary>
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Tender</th>
                            <th scope="col" class="amount">Closing balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="t in shift.closing_snapshot!
                                .balance_by_tender"
                            :key="t.id ?? 'untagged'"
                        >
                            <td>{{ t.name }}</td>
                            <td class="amount">{{ money(t.balance) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="card-note">
                    System totals include every tender and untagged entries.
                    Actual counts cover Cash and GCash; other tenders can leave
                    an unexplained difference. Backdated ledger changes between
                    snapshots also affect this shift's net balance.
                </p>
            </details>
        </section>

        <footer class="page-footer">
            <span>BYPASS GRILL · MAKE EVERY PESO COUNT.</span
            ><span>Figures update when you open or refresh this page.</span>
        </footer>
    </div>
</template>

<style scoped>
.grill-page {
    background: #f6f2e9;
    color: #24231e;
    min-height: 100%;
    padding: 34px clamp(16px, 3vw, 44px);
    font-family: Arial, Helvetica, sans-serif;
    color-scheme: light;
    display: flex;
    flex-direction: column;
    gap: 22px;
}
.page-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
}
.eyebrow {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1.6px;
    color: #ad3b19;
}
.page-heading h1 {
    font-size: clamp(32px, 3.5vw, 48px);
    font-weight: 850;
    letter-spacing: -1.8px;
    line-height: 1.1;
    margin: 12px 0;
}
.page-heading h1 em {
    font-family: Georgia, serif;
    font-weight: 400;
    color: #ad3b19;
}
.intro {
    font-size: 13px;
    line-height: 1.6;
    color: #68665f;
    max-width: 560px;
}
.heading-meta {
    flex-shrink: 0;
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
    color: #24231e;
}
.refresh-button:hover {
    background: #efeadf;
}
.refresh-button:disabled {
    opacity: 0.6;
    cursor: wait;
}

/* View tabs */
.view-tabs {
    display: flex;
    gap: 4px;
    padding: 4px;
    background: #ebe5d8;
    border-radius: 6px;
    width: max-content;
    max-width: 100%;
}
.view-tabs a {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 14px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 700;
    color: #68665f;
    white-space: nowrap;
}
.view-tabs a:hover {
    color: #24231e;
}
.view-tabs a.active {
    background: #fffcf6;
    color: #a23817;
    box-shadow: 0 1px 2px #24231e1a;
}

.page-error {
    display: flex;
    align-items: center;
    gap: 9px;
    background: #fbe9e4;
    color: #a03015;
    border: 1px solid #edc4b7;
    border-radius: 5px;
    padding: 12px 14px;
    font-size: 13px;
}
.loading {
    font-size: 13px;
    color: #68665f;
}

/* Stepper */
.stepper {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    list-style: none;
    padding: 0;
    margin: 0;
}
.stepper li {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    border: 1px solid #ded7cb;
    border-radius: 6px;
    background: #fffcf6;
    color: #777268;
    min-width: 0;
}
.stepper strong {
    display: block;
    font-size: 13px;
    color: inherit;
}
.stepper small {
    display: block;
    font-size: 10px;
    margin-top: 3px;
}
.step-dot {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    border: 1px solid #d4cdbf;
    font-size: 12px;
    font-weight: 800;
}
.stepper li.current {
    border-color: #e4c4ab;
    background: #f2e5d8;
    color: #24231e;
    box-shadow: inset 3px 0 0 #c3441c;
}
.stepper li.current .step-dot {
    background: #c3441c;
    border-color: #c3441c;
    color: #fff;
}
.stepper li.done .step-dot {
    background: #e4edde;
    border-color: #b9cfae;
    color: #376229;
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
}
.work-bar-copy strong {
    font-size: 17px;
    display: block;
}
.work-bar-copy span {
    font-size: 12px;
    color: #c3bfb3;
    display: block;
    line-height: 1.7;
    margin-top: 5px;
    max-width: 480px;
}
.work-form {
    display: flex;
    flex-direction: column;
    gap: 7px;
    flex-shrink: 0;
}
.work-form label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.5px;
    color: #c3bfb3;
}
.work-form-row {
    display: flex;
    gap: 10px;
}
.work-actions {
    display: flex;
    gap: 10px;
    flex-shrink: 0;
    flex-wrap: wrap;
}
.work-note {
    font-size: 12px;
    color: #c3bfb3;
}
.confirm-box {
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-width: 380px;
    padding: 14px;
    border: 1px solid #ffffff30;
    border-radius: 5px;
    background: #ffffff0d;
}
.confirm-box p {
    font-size: 12px;
    line-height: 1.6;
    color: #f6f2e9;
}
.confirm-box > div {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.primary-action,
.secondary-action,
.ghost-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 800;
    padding: 12px 16px;
    white-space: nowrap;
}
.primary-action {
    background: #c3441c;
    color: #fff;
}
.primary-action:hover:not(:disabled) {
    background: #a73513;
}
.secondary-action {
    border: 1px solid #ffffff40;
    color: #f6f2e9;
}
.secondary-action:hover:not(:disabled) {
    background: #ffffff12;
}
.ghost-action {
    border: 1px solid #d4cdbf;
    color: #24231e;
    background: #fffcf6;
}
.ghost-action:hover:not(:disabled) {
    background: #efeadf;
}
.primary-action:disabled,
.secondary-action:disabled,
.ghost-action:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Money inputs */
.money-input {
    display: flex;
    align-items: center;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    background: #fff;
    transition:
        border-color 0.15s,
        box-shadow 0.15s;
}
.money-input:focus-within {
    border-color: #ad3b19;
    box-shadow: 0 0 0 3px #ad3b1926;
}
.money-input span {
    padding-left: 12px;
    font-size: 14px;
    font-weight: 700;
    color: #93897b;
}
.money-input input {
    width: 100%;
    min-width: 0;
    border: 0;
    background: transparent;
    padding: 11px 12px 11px 6px;
    font-size: 15px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: #24231e;
    outline: none;
}
.money-input.dark {
    background: #ffffff10;
    border-color: #ffffff40;
    min-width: 170px;
}
.money-input.dark span {
    color: #c3bfb3;
}
.money-input.dark input {
    color: #f6f2e9;
}
.money-input.invalid {
    border-color: #c0392b;
}

/* Metrics */
.metric-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}
.metric-grid.three {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}
.metric {
    background: #fffcf6;
    border: 1px solid #ded7cb;
    padding: 19px;
    border-radius: 5px;
    min-width: 0;
}
.metric-featured {
    background: #f2e5d8;
    border-color: #e4c4ab;
}
.metric p {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    color: #68665f;
}
.metric strong {
    display: block;
    font-size: clamp(20px, 2.2vw, 28px);
    letter-spacing: -1px;
    line-height: 1.3;
    margin: 10px 0 6px;
    overflow-wrap: anywhere;
    font-variant-numeric: tabular-nums;
}
.metric strong.metric-text {
    font-size: clamp(17px, 1.8vw, 22px);
    letter-spacing: -0.4px;
}
.metric-featured strong {
    color: #ad3b19;
}
.metric > span {
    font-size: 10px;
    line-height: 1.6;
    color: #777268;
    display: block;
}
.text-balanced {
    color: #376229;
}
.text-short {
    color: #9c3028;
}

/* Panels */
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
    margin-bottom: 20px;
}
.panel h2 {
    font-size: 20px;
    font-weight: 800;
    letter-spacing: -0.5px;
    line-height: 1.3;
}
.panel-heading .eyebrow {
    margin-bottom: 7px;
    font-size: 8px;
}
.panel-sub {
    font-size: 12px;
    color: #68665f;
    margin-top: 4px;
}
.text-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 11px;
    font-weight: 700;
    color: #ad3b19;
    white-space: nowrap;
}
.text-link:hover {
    text-decoration: underline;
    text-underline-offset: 4px;
}
.muted-icon {
    color: #93897b;
}
.empty-state {
    padding: 26px 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 10px;
    color: #777268;
}
.empty-state > svg {
    color: #ad3b19;
}
.empty-state h2,
.empty-state h3 {
    color: #24231e;
    font-size: 15px;
    font-weight: 700;
}
.empty-state p {
    font-size: 12px;
    line-height: 1.7;
    max-width: 350px;
}

/* Tables */
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
}
td small {
    display: block;
    color: #777268;
    font-size: 9px;
    margin-top: 4px;
}
.amount {
    text-align: right;
    font-variant-numeric: tabular-nums;
    font-weight: 700;
}
tr.clickable {
    cursor: pointer;
    transition: background 0.15s;
}
tr.clickable:hover {
    background: #f7f1e6;
}
tr.selected,
tr.selected:hover {
    background: #f4dfcf;
}
tr.selected td:first-child {
    box-shadow: inset 3px 0 0 #c3441c;
    padding-left: 10px;
}
.row-toggle {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    padding: 6px 10px;
    font-size: 11px;
    font-weight: 700;
    background: #fffcf6;
}
.flipped {
    transform: rotate(180deg);
}
.pager {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-top: 1px solid #ece5da;
    margin-top: 6px;
    padding-top: 15px;
    font-size: 12px;
    color: #68665f;
}
.pager button {
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    padding: 8px 14px;
    font-size: 11px;
    font-weight: 700;
    background: #fffcf6;
    color: #24231e;
}
.pager button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Status pills */
.pill {
    display: inline-block;
    font-size: 10px;
    font-weight: 700;
    padding: 4px 8px;
    border-radius: 20px;
    white-space: nowrap;
}
.pill-pending {
    background: #efeadf;
    color: #625b4c;
}
.pill-balanced {
    background: #e4edde;
    color: #376229;
}
.pill-short {
    background: #f7e1df;
    color: #9c3028;
}
.pill-over {
    background: #f7eccf;
    color: #7b5815;
}

/* Report */
.report {
    scroll-margin-top: 20px;
}
.check-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}
.check-card {
    border: 1px solid #ded7cb;
    border-top-width: 3px;
    border-radius: 5px;
    padding: 18px;
    background: #fff;
    min-width: 0;
}
.card-balanced {
    border-top-color: #6f9a5d;
}
.card-short {
    border-top-color: #c0392b;
}
.card-over {
    border-top-color: #c79a2e;
}
.card-pending {
    border-top-color: #d4cdbf;
}
.check-card header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 12px;
}
.check-card header .eyebrow {
    font-size: 8px;
    margin-bottom: 5px;
}
.check-card h3 {
    font-size: 15px;
    font-weight: 800;
}
.summary-list {
    font-size: 12px;
}
.summary-list > div {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 9px 0;
    border-bottom: 1px solid #ece5da;
}
.summary-list dt {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #68665f;
}
.summary-list dd {
    font-weight: 700;
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.summary-list .is-zero dt,
.summary-list .is-zero dd {
    color: #aaa294;
    font-weight: 400;
}
.summary-total {
    border-bottom: 0 !important;
}
.summary-total dt,
.summary-total dd {
    font-weight: 800;
    color: #24231e;
}
.summary-list.actual {
    margin-top: 10px;
    padding: 4px 12px;
    background: #f6f2e9;
    border-radius: 4px;
}
.card-note {
    font-size: 10px;
    line-height: 1.7;
    color: #777268;
    margin-top: 10px;
}

/* Counts form */
.counts-form {
    border-top: 1px solid #ece5da;
    margin-top: 22px;
    padding-top: 22px;
    scroll-margin-top: 20px;
}
.form-heading {
    margin-bottom: 16px;
}
.form-heading .eyebrow {
    font-size: 8px;
    margin-bottom: 5px;
}
.form-heading h3 {
    font-size: 17px;
    font-weight: 800;
}
.form-heading p:not(.eyebrow) {
    font-size: 12px;
    color: #68665f;
    margin-top: 4px;
}
.count-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    border: 0;
    padding: 0;
    margin: 0;
}
.count-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 18px;
    border-radius: 5px;
    background: #f2ede2;
    min-width: 0;
}
.count-group h4 {
    font-size: 13px;
    font-weight: 800;
    margin-bottom: 6px;
}
.count-group label {
    font-size: 12px;
    font-weight: 700;
    margin-top: 6px;
}
.field-hint {
    font-size: 10px;
    line-height: 1.6;
    color: #777268;
}
.field-error {
    color: #9c3028;
    font-weight: 700;
}
.live-check {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 14px;
    margin-top: 10px;
    padding: 11px 12px;
    border-radius: 4px;
    font-size: 11px;
    background: #fffcf6;
    border: 1px solid #ded7cb;
    color: #68665f;
}
.live-check b {
    color: #24231e;
    font-variant-numeric: tabular-nums;
}
.live-check strong {
    margin-left: auto;
    font-size: 12px;
}
.live-balanced {
    border-color: #b9cfae;
    background: #eef4ea;
}
.live-balanced strong {
    color: #376229;
}
.live-short {
    border-color: #e8b7b1;
    background: #fbeeec;
}
.live-short strong {
    color: #9c3028;
}
.live-over {
    border-color: #e6d09b;
    background: #fbf4e2;
}
.live-over strong {
    color: #7b5815;
}
.notes-label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    margin: 18px 0 6px;
}
.notes-label span {
    font-weight: 400;
    color: #777268;
}
textarea {
    display: block;
    width: 100%;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    background: #fff;
    padding: 10px 12px;
    font-size: 13px;
    color: #24231e;
    resize: vertical;
}
textarea:focus {
    outline: none;
    border-color: #ad3b19;
    box-shadow: 0 0 0 3px #ad3b1926;
}
.final-confirm {
    display: flex;
    gap: 10px;
    margin-top: 16px;
    padding: 13px 14px;
    border-radius: 5px;
    background: #fbf4e2;
    border: 1px solid #e6d09b;
    color: #7b5815;
}
.final-confirm strong {
    display: block;
    font-size: 13px;
    color: #24231e;
}
.final-confirm p {
    font-size: 12px;
    margin-top: 3px;
    font-variant-numeric: tabular-nums;
}
.form-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 16px;
}
.saved-notes {
    margin-top: 18px;
    padding: 14px;
    background: #f6f2e9;
    border-radius: 5px;
}
.saved-notes .eyebrow {
    font-size: 8px;
    margin-bottom: 6px;
}
.saved-notes p:not(.eyebrow) {
    font-size: 13px;
    line-height: 1.6;
    white-space: pre-wrap;
}
.tender-details {
    margin-top: 18px;
    border-top: 1px solid #ece5da;
    padding-top: 14px;
}
.tender-details summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    list-style: none;
    font-size: 12px;
    font-weight: 700;
    color: #ad3b19;
}
.tender-details summary::-webkit-details-marker {
    display: none;
}
.tender-details summary svg {
    transition: transform 0.15s;
}
.tender-details[open] summary svg {
    transform: rotate(180deg);
}
.tender-details table {
    margin-top: 12px;
}

.page-footer {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    margin-top: 8px;
    padding-top: 18px;
    border-top: 1px solid #ded7cb;
    font-size: 9px;
    color: #777268;
}
.page-footer > span:first-child {
    letter-spacing: 1px;
    font-weight: 700;
}
.grill-page :focus-visible {
    outline: 2px solid #ad3b19;
    outline-offset: 3px;
}
.grill-page button {
    cursor: pointer;
}
.spinning {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

@media (max-width: 1150px) {
    .work-bar {
        align-items: flex-start;
        flex-direction: column;
    }
    .panel {
        padding: 19px;
    }
}
@media (max-width: 900px) {
    .metric-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .check-grid,
    .count-grid {
        grid-template-columns: 1fr;
    }
    .page-heading {
        flex-wrap: wrap;
    }
}
@media (max-width: 640px) {
    .grill-page {
        padding: 24px 16px;
        gap: 18px;
    }
    .page-heading h1 {
        font-size: 34px;
    }
    .intro {
        font-size: 12px;
    }
    .stepper {
        grid-template-columns: 1fr;
        gap: 6px;
    }
    .stepper li {
        padding: 10px 12px;
    }
    .stepper li.upcoming small,
    .stepper li.done small {
        display: none;
    }
    .view-tabs {
        width: 100%;
    }
    .view-tabs a {
        flex: 1;
        justify-content: center;
        padding: 9px 8px;
    }
    .work-bar {
        padding: 20px;
    }
    .work-form,
    .work-actions,
    .confirm-box {
        width: 100%;
        max-width: none;
    }
    .work-form-row {
        flex-direction: column;
    }
    .work-actions .primary-action,
    .confirm-box .primary-action,
    .confirm-box .secondary-action,
    .work-form .primary-action {
        width: 100%;
    }
    .metric-grid,
    .metric-grid.three {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }
    .metric {
        padding: 14px;
    }
    .check-card,
    .count-group {
        padding: 14px;
    }
    .form-actions .primary-action,
    .form-actions .ghost-action {
        width: 100%;
    }
    .page-footer {
        flex-direction: column;
        line-height: 1.6;
    }
}
@media (prefers-reduced-motion: reduce) {
    .spinning {
        animation: none;
    }
    tr.clickable,
    .money-input,
    .tender-details summary svg {
        transition: none;
    }
}
</style>
