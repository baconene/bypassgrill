<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import api from '@/utils/api';

interface Entry {
    id: number;
    kind: string;
    source: string;
    ingredient_name: string | null;
    quantity: number;
    unit_cost: number;
    total_cost: number;
    reference: string | null;
    recognized_at: string;
}
interface Report {
    period: { start: string; end: string };
    purchases: number;
    consumption: number;
    losses: number;
    sources: { source: string; entries: number; consumed_cost: number }[];
    ingredients: { id: number; name: string; deleted_at: string | null }[];
    entries: {
        data: Entry[];
        current_page: number;
        last_page: number;
        total: number;
    };
}
const today = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
}).format(new Date());
const start = ref(today.slice(0, 7) + '-01');
const end = ref(today);
const ingredient = ref('');
const kind = ref('');
const result = ref<Report | null>(null);
const loading = ref(false);
const error = ref('');
const applied = ref('');
const key = computed(() =>
    [start.value, end.value, ingredient.value, kind.value].join('|'),
);
const stale = computed(() => key.value !== applied.value);
let requestId = 0;
const names: Record<string, string> = {
    purchase: 'Stock received',
    purchase_reversal: 'Receipt reversal',
    consumption: 'Order consumption',
    consumption_reversal: 'Order restoration',
    waste: 'Waste',
    count_loss: 'Count loss / stock removed',
    count_gain: 'Count gain',
    ingredient: 'Tracked ingredients',
    untracked_ingredient: 'Untracked recipe ingredients',
    product_fallback: 'Product cost fallback',
};
const money = (value: number, digits = 2) =>
    new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(Number(value));
async function load(page = 1) {
    if (!start.value || !end.value || end.value < start.value) {
        error.value = 'Choose a valid date range.';

        return;
    }

    const id = ++requestId;
    const requestedKey = key.value;
    loading.value = true;
    error.value = '';

    try {
        const { data } = await api.get('/api/v1/inventory-cost-report', {
            params: {
                start_date: start.value,
                end_date: end.value,
                ingredient_id: ingredient.value || undefined,
                kind: kind.value || undefined,
                page,
            },
        });

        if (id !== requestId) {
            return;
        }

        result.value = data;
        applied.value = requestedKey;
    } catch {
        if (id === requestId) {
            error.value = 'Unable to load inventory costs. Try again.';
        }
    } finally {
        if (id === requestId) {
            loading.value = false;
        }
    }
}
function month(previous = false) {
    const date = new Date(today.slice(0, 7) + '-01T12:00:00Z');

    if (previous) {
        date.setUTCDate(0);
    }

    end.value = previous ? date.toISOString().slice(0, 10) : today;
    start.value = end.value.slice(0, 7) + '-01';
    load();
}
onMounted(() => load());
</script>

<template>
    <section class="space-y-5" aria-label="Inventory cost reports">
        <div>
            <h2 class="text-xl font-bold">Inventory reports</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Stock value and usage by movement date. These figures are not
                cash spending or the paid-order P&amp;L.
            </p>
        </div>
        <form
            class="inventory-report-filters rounded-xl border bg-card p-4"
            @submit.prevent="load()"
        >
            <label>From<input v-model="start" type="date" required /></label
            ><label
                >To<input v-model="end" type="date" :min="start" required
            /></label>
            <label
                >Item<select v-model="ingredient">
                    <option value="">All items and product fallbacks</option>
                    <option
                        v-for="i in result?.ingredients ?? []"
                        :key="i.id"
                        :value="String(i.id)"
                    >
                        {{ i.name }}{{ i.deleted_at ? ' (archived)' : '' }}
                    </option>
                </select></label
            >
            <label
                >Movement<select v-model="kind">
                    <option value="">All movements</option>
                    <option
                        v-for="k in [
                            'purchase',
                            'purchase_reversal',
                            'consumption',
                            'consumption_reversal',
                            'waste',
                            'count_loss',
                            'count_gain',
                        ]"
                        :key="k"
                        :value="k"
                    >
                        {{ names[k] }}
                    </option>
                </select></label
            >
            <button class="inventory-primary" :disabled="loading">
                {{ loading ? 'Loading…' : 'Apply filters' }}
            </button>
            <div class="inventory-shortcuts">
                <button type="button" @click="month()">This month</button
                ><button type="button" @click="month(true)">Last month</button>
            </div>
        </form>
        <p v-if="error" role="alert" class="inventory-notice">{{ error }}</p>
        <p v-else-if="loading" role="status" class="inventory-notice">
            Loading inventory report…
        </p>
        <p v-else-if="result && stale" role="status" class="inventory-notice">
            Filters changed. Apply filters to update these figures.
        </p>
        <template v-if="result">
            <p class="text-sm text-muted-foreground">
                {{ result.period.start }} to {{ result.period.end }} ·
                {{ result.entries.total }} cost entries
            </p>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-xs text-muted-foreground">
                        Stock received value
                    </p>
                    <p class="mt-2 text-2xl font-bold">
                        {{ money(result.purchases) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Receipts less reversals; not cash paid
                    </p>
                </div>
                <div class="inventory-total rounded-xl p-5">
                    <p class="text-xs">Net order consumption</p>
                    <p class="mt-2 text-2xl font-bold">
                        {{ money(result.consumption) }}
                    </p>
                    <p class="mt-1 text-xs">Usage less restorations</p>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-xs text-muted-foreground">
                        Net inventory losses
                    </p>
                    <p class="mt-2 text-2xl font-bold">
                        {{ money(result.losses) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Waste + count losses − count gains
                    </p>
                </div>
            </div>
            <div
                v-if="result.sources.length"
                class="rounded-xl border bg-card p-4"
            >
                <h3 class="mb-3 font-bold">Consumption by cost source</h3>
                <div
                    v-for="s in result.sources"
                    :key="s.source"
                    class="flex flex-wrap justify-between gap-2 border-t py-3 text-sm"
                >
                    <span>{{ names[s.source] }}</span
                    ><strong>{{ money(s.consumed_cost) }}</strong>
                </div>
            </div>
            <div
                v-if="!result.entries.total"
                class="rounded-xl border bg-card p-8 text-center"
            >
                <h3 class="font-bold">No inventory cost entries found</h3>
                <p class="mt-2 text-sm text-muted-foreground">
                    Try another period or item. The new ledger only contains
                    movements recorded since its deployment.
                </p>
            </div>
            <div v-else class="overflow-hidden rounded-xl border bg-card">
                <h3 class="border-b p-4 font-bold">Cost ledger</h3>
                <div class="divide-y md:hidden">
                    <article
                        v-for="e in result.entries.data"
                        :key="e.id"
                        class="space-y-2 p-4"
                    >
                        <div class="flex flex-wrap justify-between gap-2">
                            <strong>{{
                                e.ingredient_name ?? 'Product cost fallback'
                            }}</strong
                            ><strong>{{ money(e.total_cost) }}</strong>
                        </div>
                        <p class="text-sm">
                            {{ names[e.kind] }} ·
                            {{ Number(e.quantity).toLocaleString() }} units ×
                            {{ money(e.unit_cost, 4) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                new Intl.DateTimeFormat('en-CA', {
                                    timeZone: 'Asia/Manila',
                                    year: 'numeric',
                                    month: '2-digit',
                                    day: '2-digit',
                                }).format(new Date(e.recognized_at))
                            }}
                            · {{ e.reference || 'Manual movement' }} · #{{
                                e.id
                            }}
                        </p>
                    </article>
                </div>
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Item / movement</th>
                                <th>Quantity</th>
                                <th>Unit cost</th>
                                <th>Signed cost</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in result.entries.data" :key="e.id">
                                <td>
                                    {{
                                        new Intl.DateTimeFormat('en-CA', {
                                            timeZone: 'Asia/Manila',
                                            year: 'numeric',
                                            month: '2-digit',
                                            day: '2-digit',
                                        }).format(new Date(e.recognized_at))
                                    }}
                                </td>
                                <td>
                                    <strong>{{
                                        e.ingredient_name ??
                                        'Product cost fallback'
                                    }}</strong>
                                    <p class="text-xs text-muted-foreground">
                                        {{ names[e.kind] }}
                                    </p>
                                </td>
                                <td>
                                    {{ Number(e.quantity).toLocaleString() }}
                                </td>
                                <td>{{ money(e.unit_cost, 4) }}</td>
                                <td class="font-bold">
                                    {{ money(e.total_cost) }}
                                </td>
                                <td>{{ e.reference || 'Manual movement' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-t p-4"
                >
                    <button
                        :disabled="
                            loading || stale || result.entries.current_page <= 1
                        "
                        @click="load(result.entries.current_page - 1)"
                    >
                        Previous</button
                    ><span class="text-sm"
                        >Page {{ result.entries.current_page }} of
                        {{ result.entries.last_page }}</span
                    ><button
                        :disabled="
                            loading ||
                            stale ||
                            result.entries.current_page >=
                                result.entries.last_page
                        "
                        @click="load(result.entries.current_page + 1)"
                    >
                        Next
                    </button>
                </div>
            </div>
        </template>
    </section>
</template>
