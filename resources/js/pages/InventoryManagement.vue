<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Package,
    RefreshCw,
    X,
    Plus,
    Pencil,
    ShoppingBag,
    HelpCircle,
    Trash2,
} from 'lucide-vue-next';
import { ref, computed } from 'vue';
import { toast } from 'vue-sonner';
import InventoryCostReport from '@/components/InventoryCostReport.vue';
import api from '@/utils/api';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Inventory', href: '/inventory' },
        ],
    },
});

interface Ingredient {
    id: number;
    name: string;
    item_type: string;
    unit: string;
    current_quantity: number;
    min_quantity: number;
    cost_per_unit: number;
    is_low_stock: boolean;
}

const ITEM_TYPES = [
    {
        value: 'ingredient',
        label: 'Ingredient',
        color: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    },
    {
        value: 'tool',
        label: 'Tool',
        color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    },
    {
        value: 'equipment',
        label: 'Equipment',
        color: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    },
    {
        value: 'supply',
        label: 'Supply',
        color: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
    },
];
const itemTypeColor = (t: string) =>
    ITEM_TYPES.find((x) => x.value === t)?.color ??
    'bg-muted text-muted-foreground';
const itemTypeLabel = (t: string) =>
    ITEM_TYPES.find((x) => x.value === t)?.label ?? t;
interface Transaction {
    id: number;
    ingredient_name: string;
    type: string;
    quantity: number;
    old_quantity: number;
    new_quantity: number;
    user_name: string;
    reference: string | null;
    order_id: number | null;
    notes: string | null;
    created_at: string;
}

const props = defineProps<{
    ingredients: Ingredient[];
    recentTransactions: Transaction[];
}>();

const view = ref<'stock' | 'reports'>('stock');
const stockValue = computed(() =>
    props.ingredients.reduce(
        (sum, i) => sum + i.current_quantity * i.cost_per_unit,
        0,
    ),
);
const search = ref('');
const typeFilter = ref(''); // '' = all
const selectedItem = ref<Ingredient | null>(null);
const adjustType = ref('stock_in');
const adjustQty = ref<number>(0);
const adjustUnitCost = ref<number>(0);
const adjustNotes = ref('');
const submitting = ref(false);
const showLowOnly = ref(false);

const filtered = computed(() => {
    let list = props.ingredients;

    if (typeFilter.value) {
        list = list.filter((i) => i.item_type === typeFilter.value);
    }

    if (showLowOnly.value) {
        list = list.filter((i) => i.is_low_stock);
    }

    if (search.value.trim()) {
        const q = search.value.toLowerCase();
        list = list.filter((i) => i.name.toLowerCase().includes(q));
    }

    return list;
});

const lowCount = computed(
    () => props.ingredients.filter((i) => i.is_low_stock).length,
);

const openAdjust = (item: Ingredient) => {
    selectedItem.value = item;
    adjustType.value = 'stock_in';
    adjustQty.value = 0;
    adjustUnitCost.value = Number(item.cost_per_unit ?? 0);
    adjustNotes.value = '';
};

const submitAdjustment = async () => {
    if (
        !selectedItem.value ||
        !Number.isFinite(adjustQty.value) ||
        adjustQty.value < 0 ||
        (adjustType.value !== 'adjustment' && adjustQty.value === 0)
    ) {
        toast.warning('Enter a positive quantity, or zero for a stock count');

        return;
    }

    submitting.value = true;

    try {
        await api.post('/api/v1/inventory/adjust', {
            ingredient_id: selectedItem.value.id,
            type: adjustType.value,
            quantity: adjustQty.value,
            unit_cost:
                adjustType.value === 'stock_in'
                    ? adjustUnitCost.value
                    : undefined,
            notes: adjustNotes.value,
        });
        toast.success(`${selectedItem.value.name} adjusted successfully`);
        selectedItem.value = null;
        router.reload({ only: ['ingredients', 'recentTransactions'] });
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Adjustment failed');
    } finally {
        submitting.value = false;
    }
};

// ─── Add Ingredient ───────────────────────────────────────────────────────────
const showAddIngredient = ref(false);
const addingIngredient = ref(false);
const newIngredient = ref({
    name: '',
    item_type: 'ingredient',
    unit: '',
    current_quantity: 0,
    min_quantity: 0,
    cost_per_unit: 0,
});

const openAddIngredient = () => {
    newIngredient.value = {
        name: '',
        item_type: 'ingredient',
        unit: '',
        current_quantity: 0,
        min_quantity: 0,
        cost_per_unit: 0,
    };
    showAddIngredient.value = true;
};

const submitAddIngredient = async () => {
    if (!newIngredient.value.name || !newIngredient.value.unit) {
        toast.warning('Name and unit are required');

        return;
    }

    addingIngredient.value = true;

    try {
        await api.post('/api/v1/inventory', newIngredient.value);
        toast.success(`${newIngredient.value.name} added to inventory`);
        showAddIngredient.value = false;
        router.reload({ only: ['ingredients'] });
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to add ingredient');
    } finally {
        addingIngredient.value = false;
    }
};

// ─── Edit Ingredient ──────────────────────────────────────────────────────────
const editingIngredient = ref<Ingredient | null>(null);
const editForm = ref({
    name: '',
    item_type: 'ingredient',
    unit: '',
    min_quantity: 0,
    cost_per_unit: 0,
});
const savingEdit = ref(false);

const openEdit = (item: Ingredient) => {
    editingIngredient.value = item;
    editForm.value = {
        name: item.name,
        item_type: item.item_type ?? 'ingredient',
        unit: item.unit,
        min_quantity: item.min_quantity,
        cost_per_unit: item.cost_per_unit,
    };
};

const submitEdit = async () => {
    if (!editingIngredient.value) {
        return;
    }

    savingEdit.value = true;

    try {
        await api.patch(
            `/api/v1/inventory/${editingIngredient.value.id}`,
            editForm.value,
        );
        toast.success(`${editForm.value.name} updated`);
        editingIngredient.value = null;
        router.reload({ only: ['ingredients'] });
    } catch (err: any) {
        toast.error(
            err.response?.data?.message ?? 'Failed to update ingredient',
        );
    } finally {
        savingEdit.value = false;
    }
};

// ─── Delete Ingredient ────────────────────────────────────────────────────────
const deletingIngredient = ref<Ingredient | null>(null);
const confirmingDelete = ref(false);
const deleteSaving = ref(false);

const openDelete = (item: Ingredient) => {
    deletingIngredient.value = item;
    confirmingDelete.value = true;
};

const confirmDelete = async () => {
    if (!deletingIngredient.value) {
        return;
    }

    deleteSaving.value = true;

    try {
        await api.delete(`/api/v1/inventory/${deletingIngredient.value.id}`);
        toast.success(`${deletingIngredient.value.name} deleted`);
        confirmingDelete.value = false;
        deletingIngredient.value = null;
        router.reload({ only: ['ingredients', 'recentTransactions'] });
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to delete item');
    } finally {
        deleteSaving.value = false;
    }
};

const showHelp = ref(false);

const typeLabel: Record<string, string> = {
    stock_in: 'Stock In',
    stock_out: 'Stock Out',
    adjustment: 'Adjustment',
    waste: 'Waste',
    usage: 'Usage',
    purchase: 'Purchase',
};
const typeColor: Record<string, string> = {
    stock_in: 'text-green-600',
    stock_out: 'text-red-600',
    waste: 'text-orange-600',
    adjustment: 'text-blue-600',
    usage: 'text-yellow-600',
    purchase: 'text-purple-600',
};
</script>

<template>
    <Head title="Inventory Management" />

    <div class="inventory-theme inventory-page space-y-6">
        <header class="inventory-heading">
            <div>
                <p class="inventory-eyebrow">BYPASS GRILL / STOCK ROOM</p>
                <h1>Stock <em>&amp; supplies.</em></h1>
                <p>
                    Keep the kitchen stocked and every movement accounted for.
                </p>
            </div>
            <button class="inventory-primary" @click="showAddIngredient = true">
                <Plus class="h-4 w-4" /> Add item
            </button>
        </header>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
            <div class="rounded-xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">
                    Active inventory items
                </p>
                <p class="mt-2 text-3xl font-bold">{{ ingredients.length }}</p>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">Need restocking</p>
                <p class="mt-2 text-3xl font-bold">{{ lowCount }}</p>
            </div>
            <div
                class="inventory-total col-span-2 rounded-xl p-5 lg:col-span-1"
            >
                <p class="text-xs">Current stock value</p>
                <p class="mt-2 text-2xl font-bold">
                    {{
                        new Intl.NumberFormat('en-PH', {
                            style: 'currency',
                            currency: 'PHP',
                        }).format(stockValue)
                    }}
                </p>
                <p class="mt-1 text-xs">
                    Current quantity multiplied by average cost
                </p>
            </div>
        </div>
        <nav class="inventory-tabs" aria-label="Inventory views">
            <button
                :aria-current="view === 'stock' ? 'page' : undefined"
                @click="view = 'stock'"
            >
                Manage stock</button
            ><button
                :aria-current="view === 'reports' ? 'page' : undefined"
                @click="view = 'reports'"
            >
                Inventory reports
            </button>
        </nav>
        <InventoryCostReport v-if="view === 'reports'" />
        <template v-else>
            <p class="inventory-notice">
                Stock entries update inventory quantity and value only. They do
                not create Financial expenses or change Cash / GCash balances.
                Record actual payments separately in Financial.
            </p>
            <!-- Low Stock Alert Banner -->
            <div
                v-if="lowCount > 0"
                class="flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950/20"
            >
                <AlertTriangle class="h-5 w-5 shrink-0 text-red-500" />
                <div class="flex-1">
                    <p
                        class="text-sm font-semibold text-red-700 dark:text-red-400"
                    >
                        {{ lowCount }} item{{ lowCount > 1 ? 's are' : ' is' }}
                        below minimum stock level
                    </p>
                    <p class="text-xs text-red-600/70 dark:text-red-400/70">
                        Review and restock as needed
                    </p>
                </div>
                <button
                    @click="showLowOnly = !showLowOnly"
                    class="shrink-0 text-xs text-red-700 underline dark:text-red-400"
                >
                    {{ showLowOnly ? 'Show all' : 'Show only low stock' }}
                </button>
            </div>

            <!-- Type filter tabs -->
            <div
                class="flex flex-wrap gap-1 rounded-xl border bg-card p-1.5 shadow-sm"
            >
                <button
                    @click="typeFilter = ''"
                    :class="[
                        'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                        typeFilter === ''
                            ? 'bg-primary text-primary-foreground shadow-sm'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                    ]"
                >
                    All Items
                </button>
                <button
                    v-for="t in ITEM_TYPES"
                    :key="t.value"
                    @click="typeFilter = t.value"
                    :class="[
                        'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                        typeFilter === t.value
                            ? 'bg-primary text-primary-foreground shadow-sm'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                    ]"
                >
                    {{ t.label }}s
                </button>
            </div>

            <!-- Controls -->
            <div class="flex flex-wrap items-center gap-3">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search inventory…"
                    class="min-w-48 flex-1 rounded-lg border bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                />
                <button
                    @click="router.reload()"
                    class="flex items-center gap-1.5 rounded-lg border bg-background px-3 py-2 text-sm hover:bg-muted"
                >
                    <RefreshCw class="h-3.5 w-3.5" /> Refresh
                </button>
                <button
                    @click="openAddIngredient"
                    class="flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90"
                >
                    <Plus class="h-3.5 w-3.5" /> Add Item
                </button>
                <button
                    @click="showHelp = true"
                    class="flex items-center gap-1.5 rounded-lg border bg-background px-3 py-2 text-sm text-muted-foreground hover:bg-muted"
                    title="Help & Instructions"
                >
                    <HelpCircle class="h-3.5 w-3.5" /> Help
                </button>
            </div>

            <!-- Inventory Cards -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <div
                    class="divide-y sm:grid sm:grid-cols-2 sm:gap-2 sm:divide-none sm:p-3 lg:grid-cols-3"
                >
                    <div
                        v-for="item in filtered"
                        :key="item.id"
                        @click="openAdjust(item)"
                        role="button"
                        tabindex="0"
                        :aria-label="'Adjust stock for ' + item.name"
                        @keydown.enter.self="openAdjust(item)"
                        @keydown.space.self.prevent="openAdjust(item)"
                        :class="[
                            'flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors sm:rounded-xl sm:border sm:px-3 sm:py-3',
                            item.is_low_stock
                                ? 'bg-red-50/60 hover:bg-red-100/60 sm:border-red-200 dark:bg-red-950/10 dark:hover:bg-red-950/20 dark:sm:border-red-800/60'
                                : 'hover:bg-muted/30 sm:border-border',
                        ]"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <Package
                                    class="h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                />
                                <p class="truncate text-sm font-semibold">
                                    {{ item.name }}
                                </p>
                            </div>
                            <div
                                class="mt-1 flex flex-wrap items-center gap-1.5"
                            >
                                <span
                                    :class="[
                                        'rounded-full px-2 py-0.5 text-xs font-semibold',
                                        itemTypeColor(item.item_type),
                                    ]"
                                >
                                    {{ itemTypeLabel(item.item_type) }}
                                </span>
                                <span class="text-xs text-muted-foreground">{{
                                    item.unit
                                }}</span>
                                <span
                                    v-if="item.is_low_stock"
                                    class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300"
                                >
                                    Low
                                </span>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5">
                            <div class="text-right">
                                <p
                                    class="text-lg leading-none font-bold tabular-nums"
                                    :class="
                                        item.is_low_stock ? 'text-red-600' : ''
                                    "
                                >
                                    {{ item.current_quantity.toFixed(2) }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ item.unit }}
                                </p>
                            </div>
                            <button
                                @click.stop="openEdit(item)"
                                class="shrink-0 rounded-full p-1.5 text-muted-foreground transition hover:bg-muted"
                                title="Edit item"
                            >
                                <Pencil class="h-3.5 w-3.5" />
                            </button>
                            <button
                                @click.stop="openDelete(item)"
                                class="shrink-0 rounded-full p-1.5 text-muted-foreground transition hover:bg-red-100 hover:text-red-600 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                title="Delete item"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>
                    <div
                        v-if="filtered.length === 0"
                        class="px-4 py-10 text-center text-sm text-muted-foreground sm:col-span-3"
                    >
                        No items found.
                    </div>
                </div>
            </div>

            <!-- Recent Transactions — card list -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <div class="border-b p-4">
                    <h2 class="text-sm font-semibold">Recent Transactions</h2>
                </div>
                <div class="divide-y">
                    <div
                        v-for="tx in recentTransactions"
                        :key="tx.id"
                        class="px-4 py-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold">
                                    {{ tx.ingredient_name }}
                                </p>
                                <div
                                    class="mt-1 flex flex-wrap items-center gap-2"
                                >
                                    <span
                                        :class="[
                                            'text-xs font-semibold',
                                            typeColor[tx.type] ??
                                                'text-muted-foreground',
                                        ]"
                                    >
                                        {{ typeLabel[tx.type] ?? tx.type }}
                                    </span>
                                    <span
                                        class="text-xs text-muted-foreground tabular-nums"
                                    >
                                        {{ tx.old_quantity.toFixed(2) }} →
                                        <strong class="text-foreground">{{
                                            tx.new_quantity.toFixed(2)
                                        }}</strong>
                                    </span>
                                </div>
                                <div
                                    class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-muted-foreground"
                                >
                                    <span v-if="tx.user_name">{{
                                        tx.user_name
                                    }}</span>
                                    <span
                                        v-if="tx.notes"
                                        class="max-w-[200px] truncate"
                                        >{{ tx.notes }}</span
                                    >
                                </div>
                                <a
                                    v-if="tx.order_id"
                                    :href="`/orders/${tx.order_id}`"
                                    class="mt-1 inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary hover:bg-primary/20"
                                >
                                    <ShoppingBag class="h-3 w-3" /> Order #{{
                                        tx.order_id
                                    }}
                                </a>
                            </div>
                            <div class="shrink-0 text-right">
                                <p
                                    class="font-bold tabular-nums"
                                    :class="
                                        ['stock_in', 'purchase'].includes(
                                            tx.type,
                                        )
                                            ? 'text-green-600'
                                            : 'text-red-600'
                                    "
                                >
                                    {{
                                        ['stock_in', 'purchase'].includes(
                                            tx.type,
                                        )
                                            ? '+'
                                            : '-'
                                    }}{{ tx.quantity }}
                                </p>
                                <p
                                    class="mt-0.5 text-xs whitespace-nowrap text-muted-foreground"
                                >
                                    {{
                                        tx.created_at
                                            ? new Date(
                                                  tx.created_at,
                                              ).toLocaleDateString('en-PH', {
                                                  month: 'short',
                                                  day: 'numeric',
                                              })
                                            : '—'
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div
                        v-if="recentTransactions.length === 0"
                        class="px-4 py-8 text-center text-sm text-muted-foreground"
                    >
                        No recent transactions.
                    </div>
                </div>
            </div>
        </template>
    </div>

    <!-- Add Ingredient Modal -->
    <Teleport to="body">
        <Transition name="fade">
            <div
                v-if="showAddIngredient"
                class="inventory-theme inventory-modal fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:bg-black/50 sm:p-4"
                @click.self="showAddIngredient = false"
            >
                <div
                    class="max-h-[90vh] w-full overflow-y-auto rounded-t-2xl bg-background shadow-2xl sm:max-w-md sm:rounded-2xl"
                >
                    <div class="flex items-center justify-between border-b p-5">
                        <h3 class="text-lg font-bold">Add Inventory Item</h3>
                        <button
                            @click="showAddIngredient = false"
                            class="rounded-full p-1 hover:bg-muted"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="space-y-4 p-5">
                        <div>
                            <label
                                class="mb-1.5 block text-xs font-medium text-muted-foreground"
                                >Item Type *</label
                            >
                            <div class="grid grid-cols-2 gap-2">
                                <label
                                    v-for="t in ITEM_TYPES"
                                    :key="t.value"
                                    :class="[
                                        'flex cursor-pointer items-center gap-2 rounded-lg border p-2.5 transition',
                                        newIngredient.item_type === t.value
                                            ? 'border-primary bg-primary/5'
                                            : 'hover:bg-muted/40',
                                    ]"
                                >
                                    <input
                                        type="radio"
                                        v-model="newIngredient.item_type"
                                        :value="t.value"
                                        class="accent-primary"
                                    />
                                    <span
                                        :class="[
                                            'rounded-full px-2 py-0.5 text-xs font-semibold',
                                            t.color,
                                        ]"
                                        >{{ t.label }}</span
                                    >
                                </label>
                            </div>
                        </div>
                        <div>
                            <label
                                class="mb-1.5 block text-xs font-medium text-muted-foreground"
                                >Name *</label
                            >
                            <input
                                v-model="newIngredient.name"
                                type="text"
                                placeholder="e.g. Pork Ribs"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                            />
                        </div>
                        <div>
                            <label
                                class="mb-1.5 block text-xs font-medium text-muted-foreground"
                                >Unit *</label
                            >
                            <input
                                v-model="newIngredient.unit"
                                type="text"
                                placeholder="e.g. kg, pcs, liters"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label
                                    class="mb-1.5 block text-xs font-medium text-muted-foreground"
                                    >Starting Stock</label
                                >
                                <input
                                    v-model.number="
                                        newIngredient.current_quantity
                                    "
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                                />
                            </div>
                            <div>
                                <label
                                    class="mb-1.5 block text-xs font-medium text-muted-foreground"
                                    >Minimum Stock</label
                                >
                                <input
                                    v-model.number="newIngredient.min_quantity"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                                />
                            </div>
                        </div>
                        <div>
                            <label
                                class="mb-1.5 block text-xs font-medium text-muted-foreground"
                                >Cost per Unit (₱)</label
                            >
                            <input
                                v-model.number="newIngredient.cost_per_unit"
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                            />
                            <p class="mt-1 text-xs text-muted-foreground">
                                Used to calculate product cost and COGS for
                                P&amp;L reports.
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-3 border-t p-5">
                        <button
                            @click="showAddIngredient = false"
                            class="flex-1 rounded-lg border py-2 text-sm font-medium hover:bg-muted"
                        >
                            Cancel
                        </button>
                        <button
                            @click="submitAddIngredient"
                            :disabled="addingIngredient"
                            class="flex-1 rounded-lg bg-primary py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            {{
                                addingIngredient ? 'Adding…' : 'Add Ingredient'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- Adjustment Modal -->
    <Teleport to="body">
        <Transition name="fade">
            <div
                v-if="selectedItem"
                class="inventory-theme inventory-modal fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:bg-black/50 sm:p-4"
                @click.self="selectedItem = null"
            >
                <div
                    class="max-h-[90vh] w-full overflow-y-auto rounded-t-2xl bg-background shadow-2xl sm:max-w-md sm:rounded-2xl"
                >
                    <div class="flex items-start justify-between border-b p-5">
                        <div>
                            <h3 class="text-lg font-bold">Adjust Stock</h3>
                            <p class="text-sm text-muted-foreground">
                                {{ selectedItem.name }}
                            </p>
                        </div>
                        <button
                            @click="selectedItem = null"
                            class="rounded-full p-1 hover:bg-muted"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="space-y-4 p-5">
                        <div
                            class="flex justify-between rounded-lg bg-muted/40 p-3 text-sm"
                        >
                            <span class="text-muted-foreground"
                                >Current Stock</span
                            >
                            <span class="font-bold"
                                >{{ selectedItem.current_quantity.toFixed(2) }}
                                {{ selectedItem.unit }}</span
                            >
                        </div>
                        <div>
                            <label
                                class="mb-1.5 block text-xs font-medium text-muted-foreground"
                                >Adjustment Type</label
                            >
                            <select
                                v-model="adjustType"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                            >
                                <option value="stock_in">Stock In (Add)</option>
                                <option value="stock_out">
                                    Stock Out (Remove)
                                </option>
                                <option value="adjustment">
                                    Stock Count (Set quantity)
                                </option>
                                <option value="waste">Waste (Deduct)</option>
                            </select>
                        </div>
                        <div>
                            <label
                                class="mb-1.5 block text-xs font-medium text-muted-foreground"
                            >
                                {{
                                    adjustType === 'adjustment'
                                        ? 'New Quantity'
                                        : 'Quantity'
                                }}
                                ({{ selectedItem.unit }})
                            </label>
                            <input
                                v-model.number="adjustQty"
                                type="number"
                                min="0"
                                step="0.01"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                            />
                        </div>
                        <div v-if="adjustType === 'stock_in'">
                            <label
                                for="stock-unit-cost"
                                class="mb-1.5 block text-xs font-medium text-muted-foreground"
                                >Purchase cost per
                                {{ selectedItem.unit }}</label
                            >
                            <input
                                id="stock-unit-cost"
                                v-model.number="adjustUnitCost"
                                type="number"
                                min="0"
                                step="0.0001"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm"
                            />
                            <p class="mt-1 text-xs text-muted-foreground">
                                New purchases update the weighted-average
                                ingredient cost. Earlier cost entries keep their
                                original value.
                            </p>
                        </div>
                        <div>
                            <label
                                class="mb-1.5 block text-xs font-medium text-muted-foreground"
                                >Notes (optional)</label
                            >
                            <textarea
                                v-model="adjustNotes"
                                rows="2"
                                class="w-full resize-none rounded-lg border bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                                placeholder="Reason for adjustment…"
                            />
                        </div>
                    </div>
                    <div class="flex gap-3 border-t p-5">
                        <button
                            @click="selectedItem = null"
                            class="flex-1 rounded-lg border py-2 text-sm font-medium hover:bg-muted"
                        >
                            Cancel
                        </button>
                        <button
                            @click="submitAdjustment"
                            :disabled="submitting"
                            class="flex-1 rounded-lg bg-primary py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            <RefreshCw
                                v-if="submitting"
                                class="mr-1 inline h-3 w-3 animate-spin"
                            />
                            {{ submitting ? 'Saving…' : 'Save Adjustment' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- Confirm Delete Modal -->
    <Teleport to="body">
        <Transition name="fade">
            <div
                v-if="confirmingDelete"
                class="inventory-theme inventory-modal fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:bg-black/50 sm:p-4"
                @click.self="confirmingDelete = false"
            >
                <div
                    class="w-full overflow-hidden rounded-t-2xl bg-background shadow-2xl sm:max-w-sm sm:rounded-2xl"
                >
                    <div class="p-5">
                        <div class="mb-3 flex items-center gap-3">
                            <div
                                class="shrink-0 rounded-full bg-red-100 p-2.5 dark:bg-red-950/40"
                            >
                                <Trash2
                                    class="h-5 w-5 text-red-600 dark:text-red-400"
                                />
                            </div>
                            <div>
                                <h3 class="text-base font-bold">Delete Item</h3>
                                <p class="text-sm text-muted-foreground">
                                    This cannot be undone.
                                </p>
                            </div>
                        </div>
                        <p class="mb-5 text-sm text-muted-foreground">
                            Are you sure you want to delete
                            <span class="font-semibold text-foreground">{{
                                deletingIngredient?.name
                            }}</span
                            >? All transaction history for this item will also
                            be removed.
                        </p>
                        <div class="flex gap-2">
                            <button
                                @click="confirmingDelete = false"
                                class="flex-1 rounded-lg border py-2.5 text-sm font-semibold transition hover:bg-muted"
                            >
                                Cancel
                            </button>
                            <button
                                @click="confirmDelete"
                                :disabled="deleteSaving"
                                class="flex-1 rounded-lg bg-red-600 py-2.5 text-sm font-bold text-white transition hover:bg-red-700 disabled:opacity-60"
                            >
                                {{ deleteSaving ? 'Deleting…' : 'Delete' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- Help Modal -->
    <Teleport to="body">
        <Transition name="fade">
            <div
                v-if="showHelp"
                class="inventory-theme inventory-modal fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:bg-black/50 sm:p-4"
                @click.self="showHelp = false"
            >
                <div
                    class="max-h-[90vh] w-full overflow-y-auto rounded-t-2xl bg-background shadow-2xl sm:max-w-lg sm:rounded-2xl"
                >
                    <div
                        class="sticky top-0 z-10 flex items-center justify-between border-b bg-background p-5"
                    >
                        <div class="flex items-center gap-2">
                            <HelpCircle class="h-5 w-5 text-primary" />
                            <h3 class="text-lg font-bold">Inventory Help</h3>
                        </div>
                        <button
                            @click="showHelp = false"
                            class="rounded-full p-1 hover:bg-muted"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="space-y-5 p-5 text-sm">
                        <div>
                            <p class="mb-2 text-base font-bold">
                                📦 Managing Items
                            </p>
                            <ul class="space-y-1.5 text-muted-foreground">
                                <li>
                                    <span class="font-semibold text-foreground"
                                        >Tap any card</span
                                    >
                                    — opens the stock adjustment form for that
                                    item.
                                </li>
                                <li>
                                    <span class="font-semibold text-foreground"
                                        >Pencil icon</span
                                    >
                                    — edit the item's name, type, unit, minimum
                                    stock, and cost.
                                </li>
                                <li>
                                    <span class="font-semibold text-red-600"
                                        >Trash icon</span
                                    >
                                    — permanently delete the item and all its
                                    transaction history.
                                </li>
                                <li>
                                    <span class="font-semibold text-foreground"
                                        >Add Item</span
                                    >
                                    — create a new inventory item.
                                </li>
                                <li>
                                    <span class="font-semibold text-foreground"
                                        >Refresh</span
                                    >
                                    — reload the latest stock levels from the
                                    server.
                                </li>
                            </ul>
                        </div>

                        <div>
                            <p class="mb-3 text-base font-bold">
                                📝 How to Adjust Stock
                            </p>
                            <ol
                                class="list-none space-y-2.5 text-muted-foreground"
                            >
                                <li class="flex gap-2.5">
                                    <span
                                        class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground"
                                        >1</span
                                    >
                                    <span
                                        ><span
                                            class="font-semibold text-foreground"
                                            >Tap the item card</span
                                        >
                                        you want to update. The adjustment form
                                        will slide up from the bottom.</span
                                    >
                                </li>
                                <li class="flex gap-2.5">
                                    <span
                                        class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground"
                                        >2</span
                                    >
                                    <span
                                        ><span
                                            class="font-semibold text-foreground"
                                            >Choose the adjustment type</span
                                        >
                                        that best describes why the stock is
                                        changing (see types below).</span
                                    >
                                </li>
                                <li class="flex gap-2.5">
                                    <span
                                        class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground"
                                        >3</span
                                    >
                                    <span
                                        ><span
                                            class="font-semibold text-foreground"
                                            >Enter the quantity</span
                                        >
                                        — for Stock In/Out, Waste, and Purchase
                                        this is the amount to add or remove. For
                                        Manual Adjustment, enter the new total
                                        stock count.</span
                                    >
                                </li>
                                <li class="flex gap-2.5">
                                    <span
                                        class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground"
                                        >4</span
                                    >
                                    <span
                                        ><span
                                            class="font-semibold text-foreground"
                                            >Add a note</span
                                        >
                                        (optional) — write a short reason such
                                        as "Supplier delivery" or "Monthly
                                        count". Notes help you trace changes
                                        later in the transaction log.</span
                                    >
                                </li>
                                <li class="flex gap-2.5">
                                    <span
                                        class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground"
                                        >5</span
                                    >
                                    <span
                                        ><span
                                            class="font-semibold text-foreground"
                                            >Tap Save</span
                                        >
                                        — the stock level updates immediately
                                        and the change is recorded in Recent
                                        Transactions.</span
                                    >
                                </li>
                            </ol>
                        </div>

                        <div>
                            <p class="mb-3 text-base font-bold">
                                🔄 Adjustment Types
                            </p>
                            <div class="space-y-3">
                                <div
                                    class="rounded-lg border border-green-200 bg-green-50 p-3 dark:border-green-900 dark:bg-green-950/30"
                                >
                                    <p
                                        class="mb-1 font-semibold text-green-700 dark:text-green-400"
                                    >
                                        Stock In
                                    </p>
                                    <p class="text-muted-foreground">
                                        Use when you receive new stock from any
                                        source. The quantity you enter is
                                        <span class="font-semibold">added</span>
                                        to the current stock. Example: a bag of
                                        flour arrives from the supplier — enter
                                        the number of bags received.
                                    </p>
                                </div>
                                <div
                                    class="rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-900 dark:bg-red-950/30"
                                >
                                    <p
                                        class="mb-1 font-semibold text-red-700 dark:text-red-400"
                                    >
                                        Stock Out
                                    </p>
                                    <p class="text-muted-foreground">
                                        Use when stock leaves for any unplanned
                                        reason not covered by other types (e.g.
                                        transferred to another branch, given
                                        away). The quantity is
                                        <span class="font-semibold"
                                            >deducted</span
                                        >
                                        from current stock.
                                    </p>
                                </div>
                                <div
                                    class="rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-900 dark:bg-blue-950/30"
                                >
                                    <p
                                        class="mb-1 font-semibold text-blue-700 dark:text-blue-400"
                                    >
                                        Manual Adjustment
                                    </p>
                                    <p class="text-muted-foreground">
                                        Use after a physical stock count when
                                        the actual quantity on hand differs from
                                        what the system shows. Enter the
                                        <span class="font-semibold"
                                            >exact new total</span
                                        >
                                        — the system will set the stock to that
                                        number regardless of the previous value.
                                    </p>
                                </div>
                                <div
                                    class="rounded-lg border border-orange-200 bg-orange-50 p-3 dark:border-orange-900 dark:bg-orange-950/30"
                                >
                                    <p
                                        class="mb-1 font-semibold text-orange-700 dark:text-orange-400"
                                    >
                                        Waste
                                    </p>
                                    <p class="text-muted-foreground">
                                        Use when items are spoiled, expired,
                                        dropped, or otherwise unusable. The
                                        quantity is
                                        <span class="font-semibold"
                                            >deducted</span
                                        >
                                        and logged separately so waste can be
                                        tracked and reported over time.
                                    </p>
                                </div>
                                <div
                                    class="rounded-lg border border-purple-200 bg-purple-50 p-3 dark:border-purple-900 dark:bg-purple-950/30"
                                >
                                    <p
                                        class="mb-1 font-semibold text-purple-700 dark:text-purple-400"
                                    >
                                        Purchase
                                    </p>
                                    <p class="text-muted-foreground">
                                        Use when you buy stock specifically as a
                                        procurement event. Like Stock In, the
                                        quantity is
                                        <span class="font-semibold">added</span
                                        >, but it is tagged as a purchase so
                                        spending can be tracked separately from
                                        other stock additions.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <p class="mb-2 text-base font-bold">
                                🔴 Low Stock Alert
                            </p>
                            <p class="text-muted-foreground">
                                An item is flagged
                                <span class="font-semibold text-red-600"
                                    >Low</span
                                >
                                when its current stock falls below its set
                                minimum. Use
                                <span class="font-semibold"
                                    >Show only low stock</span
                                >
                                on the alert banner to filter those items
                                quickly.
                            </p>
                        </div>

                        <div>
                            <p class="mb-2 text-base font-bold">
                                🏷️ Item Types
                            </p>
                            <ul class="space-y-1.5 text-muted-foreground">
                                <li>
                                    <span
                                        class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-900/40 dark:text-green-300"
                                        >Ingredient</span
                                    >
                                    — used in recipes and deducted automatically
                                    when orders are completed.
                                </li>
                                <li>
                                    <span
                                        class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300"
                                        >Tool</span
                                    >
                                    — equipment tracked for maintenance or
                                    reorder purposes.
                                </li>
                                <li>
                                    <span
                                        class="rounded-full bg-purple-100 px-2 py-0.5 text-xs font-semibold text-purple-700 dark:bg-purple-900/40 dark:text-purple-300"
                                        >Equipment</span
                                    >
                                    — larger assets tracked in inventory.
                                </li>
                                <li>
                                    <span
                                        class="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-semibold text-orange-700 dark:bg-orange-900/40 dark:text-orange-300"
                                        >Supply</span
                                    >
                                    — consumable supplies not used directly in
                                    recipes.
                                </li>
                            </ul>
                        </div>

                        <div>
                            <p class="mb-2 text-base font-bold">
                                📋 Recent Transactions
                            </p>
                            <p class="text-muted-foreground">
                                The transaction log at the bottom shows the
                                latest stock changes including automatic
                                deductions from completed orders, manual
                                adjustments, and purchases.
                            </p>
                        </div>
                    </div>
                    <div class="border-t p-5">
                        <button
                            @click="showHelp = false"
                            class="w-full rounded-lg bg-primary py-2.5 text-sm font-bold text-primary-foreground hover:bg-primary/90"
                        >
                            Got it
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- Edit Ingredient Modal -->
    <Teleport to="body">
        <Transition name="fade">
            <div
                v-if="editingIngredient"
                class="inventory-theme inventory-modal fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:bg-black/50 sm:p-4"
                @click.self="editingIngredient = null"
            >
                <div
                    class="max-h-[90vh] w-full overflow-y-auto rounded-t-2xl bg-background shadow-2xl sm:max-w-md sm:rounded-2xl"
                >
                    <div class="flex items-center justify-between border-b p-5">
                        <h3 class="text-lg font-bold">Edit Ingredient</h3>
                        <button
                            @click="editingIngredient = null"
                            class="rounded-full p-1 hover:bg-muted"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="space-y-4 p-5">
                        <div>
                            <label
                                class="mb-1.5 block text-xs font-medium text-muted-foreground"
                                >Item Type *</label
                            >
                            <div class="grid grid-cols-2 gap-2">
                                <label
                                    v-for="t in ITEM_TYPES"
                                    :key="t.value"
                                    :class="[
                                        'flex cursor-pointer items-center gap-2 rounded-lg border p-2.5 transition',
                                        editForm.item_type === t.value
                                            ? 'border-primary bg-primary/5'
                                            : 'hover:bg-muted/40',
                                    ]"
                                >
                                    <input
                                        type="radio"
                                        v-model="editForm.item_type"
                                        :value="t.value"
                                        class="accent-primary"
                                    />
                                    <span
                                        :class="[
                                            'rounded-full px-2 py-0.5 text-xs font-semibold',
                                            t.color,
                                        ]"
                                        >{{ t.label }}</span
                                    >
                                </label>
                            </div>
                        </div>
                        <div>
                            <label
                                class="mb-1.5 block text-xs font-medium text-muted-foreground"
                                >Name *</label
                            >
                            <input
                                v-model="editForm.name"
                                type="text"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                            />
                        </div>
                        <div>
                            <label
                                class="mb-1.5 block text-xs font-medium text-muted-foreground"
                                >Unit *</label
                            >
                            <input
                                v-model="editForm.unit"
                                type="text"
                                placeholder="e.g. kg, pcs, liters"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                            />
                        </div>
                        <div>
                            <label
                                class="mb-1.5 block text-xs font-medium text-muted-foreground"
                                >Minimum Stock</label
                            >
                            <input
                                v-model.number="editForm.min_quantity"
                                type="number"
                                min="0"
                                step="0.01"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                            />
                        </div>
                        <div>
                            <label
                                class="mb-1.5 block text-xs font-medium text-muted-foreground"
                                >Cost per Unit (₱)</label
                            >
                            <input
                                v-model.number="editForm.cost_per_unit"
                                type="number"
                                min="0"
                                step="0.0001"
                                placeholder="0.00"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                            />
                            <p class="mt-1 text-xs text-muted-foreground">
                                Cost per {{ editForm.unit || 'unit' }}. Used to
                                calculate product COGS for P&amp;L reports.
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-3 border-t p-5">
                        <button
                            @click="editingIngredient = null"
                            class="flex-1 rounded-lg border py-2 text-sm font-medium hover:bg-muted"
                        >
                            Cancel
                        </button>
                        <button
                            @click="submitEdit"
                            :disabled="savingEdit"
                            class="flex-1 rounded-lg bg-primary py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            {{ savingEdit ? 'Saving…' : 'Save Changes' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.15s;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
<style src="../../css/inventory-theme.css"></style>
