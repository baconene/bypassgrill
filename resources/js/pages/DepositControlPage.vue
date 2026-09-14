<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import type { Auth } from '@/types/auth';
import api from '@/utils/api';

interface Tender {
    id: number | null;
    name: string;
    balance: number;
}
interface Snapshot {
    captured_at: string;
    business_date: string;
    running_balance: number;
    net_balance: number;
    expense: number;
    income_adjustment: number;
    payroll_deductions: number;
    income: number;
    asset_deductions: number;
    payout_shares: number;
    balance_by_tender: Tender[];
}
interface Shift {
    id: number;
    user_id: number;
    user: { name: string };
    opened_at: string;
    closed_at: string | null;
    opening_snapshot: Snapshot;
    closing_snapshot: Snapshot | null;
    reconciliation: null | {
        lockbox_amount: number;
        actual_total: number;
        system_shift_change: number;
        overall_variance: number;
        notes: string | null;
        tenders: (Tender & {
            outside_lockbox: number;
            actual: number;
            variance: number;
        })[];
    };
}
const page = usePage<{ auth: Auth & { roles: string[] } }>();
const active = ref<Shift | null>(null);
const history = ref<Shift[]>([]);
const historyPage = ref(1);
const lastPage = ref(1);
const loaded = ref(false);
const busy = ref(false);
const error = ref('');
const lockbox = ref('');
const lockboxTender = ref<number | null>(null);
const counts = ref<Record<number, string>>({});
const notes = ref('');
const canStart = computed(() =>
    page.props.auth.roles.some((role: string) =>
        ['cashier', 'admin'].includes(role),
    ),
);
const isOwner = computed(
    () => active.value?.user_id === page.props.auth.user.id,
);
const closingTenders = computed(
    () =>
        active.value?.closing_snapshot?.balance_by_tender.filter(
            (t): t is Tender & { id: number } => t.id !== null,
        ) ?? [],
);
const money = (value: number) =>
    new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(value);
const date = (value: string) => new Date(value).toLocaleString('en-PH');
const metrics: {
    key: keyof Pick<
        Snapshot,
        | 'running_balance'
        | 'net_balance'
        | 'income'
        | 'expense'
        | 'income_adjustment'
        | 'payroll_deductions'
        | 'asset_deductions'
        | 'payout_shares'
    >;
    label: string;
}[] = [
    { key: 'running_balance', label: 'Running balance (all time)' },
    { key: 'net_balance', label: 'Net balance (snapshot day)' },
    { key: 'income', label: 'Payment income' },
    { key: 'expense', label: 'Expense' },
    { key: 'income_adjustment', label: 'Income adjustment' },
    { key: 'payroll_deductions', label: 'Payroll deductions' },
    { key: 'asset_deductions', label: 'Asset deductions' },
    { key: 'payout_shares', label: 'Payout shares' },
];
const actual = computed(
    () =>
        (Number(lockbox.value) || 0) +
        Object.values(counts.value).reduce(
            (sum, value) => sum + (Number(value) || 0),
            0,
        ),
);
const validAmount = (value: string) =>
    /^\d+(\.\d{1,2})?$/.test(value) && Number(value) <= 9999999999.99;
const valid = computed(
    () =>
        validAmount(lockbox.value) &&
        lockboxTender.value !== null &&
        closingTenders.value.every((t) =>
            validAmount(counts.value[t.id] ?? ''),
        ),
);

function showError(e: unknown) {
    const response = (e as { response?: { data?: { message?: string } } })
        .response;
    error.value =
        response?.data?.message ??
        'Unable to reach the server. Check your connection and refresh before retrying.';
}
async function load(targetPage = historyPage.value) {
    const { data } = await api.get('/api/v1/deposit-controls', {
        params: { page: targetPage },
    });
    active.value = data.active;
    history.value = data.history.data;
    historyPage.value = data.history.current_page;
    lastPage.value = data.history.last_page;
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
        await api.post(
            url,
            action === 'reconcile'
                ? {
                      lockbox_amount: lockbox.value,
                      lockbox_tender_id: lockboxTender.value,
                      tenders: closingTenders.value.map((t) => ({
                          id: t.id,
                          amount: counts.value[t.id],
                      })),
                      notes: notes.value,
                  }
                : {},
        );
        lockbox.value = '';
        lockboxTender.value = null;
        counts.value = {};
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
    <Head title="Deposit Control" />
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-8">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">Deposit Control</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Capture shift balances, count funds, and review shortages or
                    overages.
                </p>
            </div>
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
        <p class="text-sm text-muted-foreground">
            Snapshots cover the shared POS ledger and include all deductions.
            Sync pending offline payments before capturing a snapshot. Positive
            variance means over; negative means short.
        </p>
        <section v-if="loaded" class="rounded-xl border p-5">
            <template v-if="!active">
                <h2 class="text-lg font-semibold">1. Start of shift</h2>
                <p class="my-3 text-sm text-muted-foreground">
                    Save the opening financial snapshot. Only one shared POS
                    shift can be active at a time.
                </p>
                <button
                    v-if="canStart"
                    class="rounded-lg bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50"
                    :disabled="busy"
                    @click="act('start')"
                >
                    Start shift &amp; snapshot
                </button>
            </template>
            <template v-else>
                <h2 class="text-lg font-semibold">
                    Shift #{{ active.id }} · {{ active.user.name }}
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Opened {{ date(active.opened_at) }}
                </p>
                <p v-if="!isOwner" class="mt-3 text-sm">
                    This shift must be completed by {{ active.user.name }}.
                </p>
                <div v-if="!active.closed_at" class="mt-4">
                    <h3 class="font-semibold">2. End of shift</h3>
                    <p class="my-2 text-sm text-muted-foreground">
                        Capture final system balances after all shift
                        transactions are recorded. This snapshot is permanent.
                    </p>
                    <button
                        v-if="isOwner"
                        class="rounded-lg bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50"
                        :disabled="busy"
                        @click="act('close')"
                    >
                        Capture closing snapshot
                    </button>
                </div>
                <form
                    v-else-if="isOwner"
                    class="mt-5 space-y-4"
                    @submit.prevent="act('reconcile')"
                >
                    <h3 class="font-semibold">3. Count actual funds</h3>
                    <p class="text-sm text-muted-foreground">
                        Enter all funds held at closing. Tender balances exclude
                        the lockbox. For Cash, enter drawer cash only; for
                        GCash, enter the wallet balance. Lockbox cash is added
                        once to the tender you select.
                    </p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="space-y-1 text-sm"
                            >Lockbox content (PHP)<input
                                v-model="lockbox"
                                required
                                type="number"
                                min="0"
                                max="9999999999.99"
                                step="0.01"
                                class="block w-full rounded-lg border bg-background p-2"
                        /></label>
                        <label class="space-y-1 text-sm"
                            >Lockbox tender<select
                                v-model="lockboxTender"
                                required
                                class="block w-full rounded-lg border bg-background p-2"
                            >
                                <option :value="null" disabled>
                                    Select cash tender
                                </option>
                                <option
                                    v-for="t in closingTenders"
                                    :key="t.id"
                                    :value="t.id"
                                >
                                    {{ t.name }}
                                </option>
                            </select></label
                        >
                        <label
                            v-for="t in closingTenders"
                            :key="t.id"
                            class="space-y-1 text-sm"
                            >{{ t.name }} outside lockbox (PHP)<input
                                v-model="counts[t.id]"
                                required
                                type="number"
                                min="0"
                                max="9999999999.99"
                                step="0.01"
                                class="block w-full rounded-lg border bg-background p-2"
                        /></label>
                    </div>
                    <label class="block text-sm"
                        >Count notes<textarea
                            v-model="notes"
                            maxlength="2000"
                            class="mt-1 block w-full rounded-lg border bg-background p-2"
                        />
                    </label>
                    <p v-if="valid" class="font-semibold">
                        Actual total: {{ money(actual) }} · Overall variance:
                        {{
                            money(
                                actual -
                                    active.closing_snapshot!.running_balance,
                            )
                        }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Submitting saves final counts and completes this shift.
                        Review all amounts first.
                    </p>
                    <button
                        class="rounded-lg bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50"
                        :disabled="busy || !valid"
                    >
                        Save counts &amp; complete shift
                    </button>
                </form>
            </template>
        </section>
        <template
            v-for="shift in [...(active ? [active] : []), ...history]"
            :key="shift.id"
        >
            <details
                class="rounded-xl border p-5"
                :open="shift.id === active?.id"
            >
                <summary class="cursor-pointer font-semibold">
                    Shift #{{ shift.id }} · {{ shift.user.name }} ·
                    {{ date(shift.opened_at) }}
                    <span
                        v-if="shift.reconciliation"
                        class="ml-2"
                        :class="
                            shift.reconciliation.overall_variance === 0
                                ? 'text-green-700'
                                : 'text-red-600'
                        "
                        >Variance
                        {{ money(shift.reconciliation.overall_variance) }}</span
                    >
                </summary>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left">
                                <th class="p-2">Financial snapshot</th>
                                <th class="p-2">Opening</th>
                                <th class="p-2">Closing</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b">
                                <td class="p-2">Captured</td>
                                <td class="p-2">
                                    {{
                                        date(shift.opening_snapshot.captured_at)
                                    }}
                                </td>
                                <td class="p-2">
                                    {{
                                        shift.closing_snapshot
                                            ? date(
                                                  shift.closing_snapshot
                                                      .captured_at,
                                              )
                                            : 'Pending'
                                    }}
                                </td>
                            </tr>
                            <tr
                                v-for="metric in metrics"
                                :key="metric.key"
                                class="border-b"
                            >
                                <td class="p-2">{{ metric.label }}</td>
                                <td class="p-2">
                                    {{
                                        money(
                                            shift.opening_snapshot[metric.key],
                                        )
                                    }}
                                </td>
                                <td class="p-2">
                                    {{
                                        shift.closing_snapshot
                                            ? money(
                                                  shift.closing_snapshot[
                                                      metric.key
                                                  ],
                                              )
                                            : '—'
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-muted-foreground">
                    Income and deductions are for each snapshot's calendar day
                    up to capture time. Running and tender balances are
                    cumulative. Shift change includes any backdated entries or
                    edits made between snapshots.
                </p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="(snapshot, i) in [
                            shift.opening_snapshot,
                            shift.closing_snapshot,
                        ]"
                        :key="i"
                    >
                        <h3 class="font-semibold">
                            {{ i === 0 ? 'Opening' : 'Closing' }} balance by
                            tender
                        </h3>
                        <p
                            v-for="t in snapshot?.balance_by_tender ?? []"
                            :key="t.id ?? 'untagged'"
                            class="mt-1 flex justify-between gap-4 text-sm"
                        >
                            <span>{{ t.name }}</span
                            ><span>{{ money(t.balance) }}</span>
                        </p>
                    </div>
                </div>
                <div
                    v-if="shift.reconciliation"
                    class="mt-5 space-y-3 border-t pt-4"
                >
                    <p>
                        Lockbox:
                        <strong>{{
                            money(shift.reconciliation.lockbox_amount)
                        }}</strong>
                        · Actual overall:
                        <strong>{{
                            money(shift.reconciliation.actual_total)
                        }}</strong>
                        · System shift change:
                        <strong>{{
                            money(shift.reconciliation.system_shift_change)
                        }}</strong>
                    </p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left">
                                    <th class="p-2">Tender</th>
                                    <th class="p-2">System</th>
                                    <th class="p-2">Outside lockbox</th>
                                    <th class="p-2">Actual incl. lockbox</th>
                                    <th class="p-2">Over / short</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="t in shift.reconciliation.tenders"
                                    :key="t.id!"
                                    class="border-b"
                                >
                                    <td class="p-2">{{ t.name }}</td>
                                    <td class="p-2">{{ money(t.balance) }}</td>
                                    <td class="p-2">
                                        {{ money(t.outside_lockbox) }}
                                    </td>
                                    <td class="p-2">{{ money(t.actual) }}</td>
                                    <td class="p-2">{{ money(t.variance) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Overall variance includes the system's untagged balance.
                        A shift-only actual variance requires an opening
                        physical count; these records compare the closing actual
                        total against the closing running balance.
                    </p>
                    <p
                        v-if="shift.reconciliation.notes"
                        class="text-sm whitespace-pre-wrap"
                    >
                        {{ shift.reconciliation.notes }}
                    </p>
                </div>
            </details>
        </template>
        <p
            v-if="loaded && history.length === 0"
            class="text-sm text-muted-foreground"
        >
            No completed shifts yet.
        </p>
        <div v-if="lastPage > 1" class="flex items-center gap-4">
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
