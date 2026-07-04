<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import { AlertTriangle, Package, RefreshCw, X, Plus, Pencil, ShoppingBag } from 'lucide-vue-next'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Inventory', href: '/inventory' },
        ],
    },
})

interface Ingredient {
    id: number; name: string; item_type: string; unit: string
    current_quantity: number; min_quantity: number; cost_per_unit: number; is_low_stock: boolean
}

const ITEM_TYPES = [
    { value: 'ingredient', label: 'Ingredient',  color: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' },
    { value: 'tool',       label: 'Tool',        color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' },
    { value: 'equipment',  label: 'Equipment',   color: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300' },
    { value: 'supply',     label: 'Supply',      color: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300' },
]
const itemTypeColor = (t: string) => ITEM_TYPES.find(x => x.value === t)?.color ?? 'bg-muted text-muted-foreground'
const itemTypeLabel = (t: string) => ITEM_TYPES.find(x => x.value === t)?.label ?? t
interface Transaction {
    id: number; ingredient_name: string; type: string; quantity: number
    old_quantity: number; new_quantity: number; user_name: string
    reference: string | null; order_id: number | null; notes: string | null; created_at: string
}

const props = defineProps<{ ingredients: Ingredient[]; recentTransactions: Transaction[] }>()

const search = ref('')
const typeFilter = ref('') // '' = all
const selectedItem = ref<Ingredient | null>(null)
const adjustType = ref('stock_in')
const adjustQty = ref<number>(0)
const adjustNotes = ref('')
const submitting = ref(false)
const showLowOnly = ref(false)

const filtered = computed(() => {
    let list = props.ingredients
    if (typeFilter.value) list = list.filter((i) => i.item_type === typeFilter.value)
    if (showLowOnly.value) list = list.filter((i) => i.is_low_stock)
    if (search.value.trim()) {
        const q = search.value.toLowerCase()
        list = list.filter((i) => i.name.toLowerCase().includes(q))
    }
    return list
})

const lowCount = computed(() => props.ingredients.filter((i) => i.is_low_stock).length)

const openAdjust = (item: Ingredient) => {
    selectedItem.value = item
    adjustType.value = 'stock_in'
    adjustQty.value = 0
    adjustNotes.value = ''
}

const submitAdjustment = async () => {
    if (!selectedItem.value || adjustQty.value <= 0) {
        toast.warning('Enter a quantity greater than 0')
        return
    }
    submitting.value = true
    try {
        await api.post('/api/v1/inventory/adjust', {
            ingredient_id: selectedItem.value.id,
            type: adjustType.value,
            quantity: adjustQty.value,
            notes: adjustNotes.value,
        })
        toast.success(`${selectedItem.value.name} adjusted successfully`)
        selectedItem.value = null
        router.reload({ only: ['ingredients', 'recentTransactions'] })
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Adjustment failed')
    } finally {
        submitting.value = false
    }
}

// ─── Add Ingredient ───────────────────────────────────────────────────────────
const showAddIngredient = ref(false)
const addingIngredient  = ref(false)
const newIngredient = ref({ name: '', item_type: 'ingredient', unit: '', current_quantity: 0, min_quantity: 0, cost_per_unit: 0 })

const openAddIngredient = () => {
    newIngredient.value = { name: '', item_type: 'ingredient', unit: '', current_quantity: 0, min_quantity: 0, cost_per_unit: 0 }
    showAddIngredient.value = true
}

const submitAddIngredient = async () => {
    if (!newIngredient.value.name || !newIngredient.value.unit) {
        toast.warning('Name and unit are required')
        return
    }
    addingIngredient.value = true
    try {
        await api.post('/api/v1/inventory', newIngredient.value)
        toast.success(`${newIngredient.value.name} added to inventory`)
        showAddIngredient.value = false
        router.reload({ only: ['ingredients'] })
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to add ingredient')
    } finally {
        addingIngredient.value = false
    }
}

// ─── Edit Ingredient ──────────────────────────────────────────────────────────
const editingIngredient = ref<Ingredient | null>(null)
const editForm = ref({ name: '', item_type: 'ingredient', unit: '', min_quantity: 0, cost_per_unit: 0 })
const savingEdit = ref(false)

const openEdit = (item: Ingredient) => {
    editingIngredient.value = item
    editForm.value = {
        name:          item.name,
        item_type:     item.item_type ?? 'ingredient',
        unit:          item.unit,
        min_quantity:  item.min_quantity,
        cost_per_unit: item.cost_per_unit,
    }
}

const submitEdit = async () => {
    if (!editingIngredient.value) return
    savingEdit.value = true
    try {
        await api.patch(`/api/v1/inventory/${editingIngredient.value.id}`, editForm.value)
        toast.success(`${editForm.value.name} updated`)
        editingIngredient.value = null
        router.reload({ only: ['ingredients'] })
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to update ingredient')
    } finally {
        savingEdit.value = false
    }
}

const typeLabel: Record<string, string> = {
    stock_in: 'Stock In', stock_out: 'Stock Out',
    adjustment: 'Adjustment', waste: 'Waste', usage: 'Usage', purchase: 'Purchase',
}
const typeColor: Record<string, string> = {
    stock_in: 'text-green-600', stock_out: 'text-red-600',
    waste: 'text-orange-600', adjustment: 'text-blue-600', usage: 'text-yellow-600', purchase: 'text-purple-600',
}
</script>

<template>
    <Head title="Inventory Management" />

    <div class="space-y-6">
        <!-- Low Stock Alert Banner -->
        <div v-if="lowCount > 0" class="flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 dark:bg-red-950/20 dark:border-red-800 p-4">
            <AlertTriangle class="h-5 w-5 text-red-500 shrink-0" />
            <div class="flex-1">
                <p class="font-semibold text-red-700 dark:text-red-400 text-sm">
                    {{ lowCount }} item{{ lowCount > 1 ? 's are' : ' is' }} below minimum stock level
                </p>
                <p class="text-xs text-red-600/70 dark:text-red-400/70">Review and restock as needed</p>
            </div>
            <button @click="showLowOnly = !showLowOnly" class="text-xs underline text-red-700 dark:text-red-400 shrink-0">
                {{ showLowOnly ? 'Show all' : 'Show only low stock' }}
            </button>
        </div>

        <!-- Type filter tabs -->
        <div class="flex flex-wrap gap-1 rounded-xl border bg-card p-1.5 shadow-sm">
            <button
                @click="typeFilter = ''"
                :class="['rounded-lg px-3 py-1.5 text-sm font-medium transition', typeFilter === '' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground']"
            >All Items</button>
            <button
                v-for="t in ITEM_TYPES" :key="t.value"
                @click="typeFilter = t.value"
                :class="['rounded-lg px-3 py-1.5 text-sm font-medium transition', typeFilter === t.value ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground']"
            >{{ t.label }}s</button>
        </div>

        <!-- Controls -->
        <div class="flex items-center gap-3 flex-wrap">
            <input
                v-model="search"
                type="text"
                placeholder="Search inventory…"
                class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary flex-1 min-w-48"
            />
            <button
                @click="router.reload()"
                class="rounded-lg border bg-background px-3 py-2 text-sm hover:bg-muted flex items-center gap-1.5"
            >
                <RefreshCw class="h-3.5 w-3.5" /> Refresh
            </button>
            <button
                @click="openAddIngredient"
                class="flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90"
            >
                <Plus class="h-3.5 w-3.5" /> Add Item
            </button>
        </div>

        <!-- Inventory Cards -->
        <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
            <div class="divide-y sm:divide-none sm:p-3 sm:grid sm:grid-cols-2 lg:grid-cols-3 sm:gap-2">
                <div v-for="item in filtered" :key="item.id"
                    @click="openAdjust(item)"
                    :class="[
                        'flex items-center gap-3 px-4 py-3 sm:px-3 sm:py-3 sm:rounded-xl sm:border cursor-pointer transition-colors',
                        item.is_low_stock
                            ? 'bg-red-50/60 dark:bg-red-950/10 hover:bg-red-100/60 dark:hover:bg-red-950/20 sm:border-red-200 dark:sm:border-red-800/60'
                            : 'hover:bg-muted/30 sm:border-border',
                    ]">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-1.5">
                            <Package class="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                            <p class="font-semibold text-sm truncate">{{ item.name }}</p>
                        </div>
                        <div class="mt-1 flex items-center gap-1.5 flex-wrap">
                            <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', itemTypeColor(item.item_type)]">
                                {{ itemTypeLabel(item.item_type) }}
                            </span>
                            <span class="text-xs text-muted-foreground">{{ item.unit }}</span>
                            <span v-if="item.is_low_stock"
                                class="rounded-full px-2 py-0.5 text-xs font-semibold bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">
                                Low
                            </span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 shrink-0">
                        <div class="text-right">
                            <p class="font-bold text-lg tabular-nums leading-none"
                                :class="item.is_low_stock ? 'text-red-600' : ''">
                                {{ item.current_quantity.toFixed(2) }}
                            </p>
                            <p class="text-xs text-muted-foreground">{{ item.unit }}</p>
                        </div>
                        <button @click.stop="openEdit(item)"
                            class="rounded-full p-1.5 text-muted-foreground hover:bg-muted transition shrink-0"
                            title="Edit item">
                            <Pencil class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </div>
                <div v-if="filtered.length === 0"
                    class="px-4 py-10 text-center text-muted-foreground text-sm sm:col-span-3">
                    No items found.
                </div>
            </div>
        </div>

        <!-- Recent Transactions — card list -->
        <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
            <div class="p-4 border-b">
                <h2 class="font-semibold text-sm">Recent Transactions</h2>
            </div>
            <div class="divide-y">
                <div v-for="tx in recentTransactions" :key="tx.id" class="px-4 py-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-sm truncate">{{ tx.ingredient_name }}</p>
                            <div class="mt-1 flex items-center gap-2 flex-wrap">
                                <span :class="['text-xs font-semibold', typeColor[tx.type] ?? 'text-muted-foreground']">
                                    {{ typeLabel[tx.type] ?? tx.type }}
                                </span>
                                <span class="text-xs text-muted-foreground tabular-nums">
                                    {{ tx.old_quantity.toFixed(2) }} → <strong class="text-foreground">{{ tx.new_quantity.toFixed(2) }}</strong>
                                </span>
                            </div>
                            <div class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                                <span v-if="tx.user_name">{{ tx.user_name }}</span>
                                <span v-if="tx.notes" class="truncate max-w-[200px]">{{ tx.notes }}</span>
                            </div>
                            <a v-if="tx.order_id" :href="`/orders/${tx.order_id}`"
                                class="mt-1 inline-flex items-center gap-1 rounded-full bg-primary/10 text-primary px-2 py-0.5 text-xs font-semibold hover:bg-primary/20">
                                <ShoppingBag class="h-3 w-3" /> Order #{{ tx.order_id }}
                            </a>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="font-bold tabular-nums"
                                :class="['stock_in','purchase'].includes(tx.type) ? 'text-green-600' : 'text-red-600'">
                                {{ ['stock_in','purchase'].includes(tx.type) ? '+' : '-' }}{{ tx.quantity }}
                            </p>
                            <p class="text-xs text-muted-foreground whitespace-nowrap mt-0.5">
                                {{ tx.created_at ? new Date(tx.created_at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) : '—' }}
                            </p>
                        </div>
                    </div>
                </div>
                <div v-if="recentTransactions.length === 0"
                    class="px-4 py-8 text-center text-muted-foreground text-sm">
                    No recent transactions.
                </div>
            </div>
        </div>
    </div>

    <!-- Add Ingredient Modal -->
    <Teleport to="body">
        <Transition name="fade">
            <div
                v-if="showAddIngredient"
                class="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:bg-black/50 sm:p-4"
                @click.self="showAddIngredient = false"
            >
                <div class="w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl bg-background shadow-2xl max-h-[90vh] overflow-y-auto">
                    <div class="p-5 border-b flex items-center justify-between">
                        <h3 class="text-lg font-bold">Add Inventory Item</h3>
                        <button @click="showAddIngredient = false" class="rounded-full p-1 hover:bg-muted">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="p-5 space-y-4">
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1.5">Item Type *</label>
                            <div class="grid grid-cols-2 gap-2">
                                <label v-for="t in ITEM_TYPES" :key="t.value"
                                    :class="['flex items-center gap-2 rounded-lg border p-2.5 cursor-pointer transition', newIngredient.item_type === t.value ? 'border-primary bg-primary/5' : 'hover:bg-muted/40']">
                                    <input type="radio" v-model="newIngredient.item_type" :value="t.value" class="accent-primary" />
                                    <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', t.color]">{{ t.label }}</span>
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1.5">Name *</label>
                            <input v-model="newIngredient.name" type="text" placeholder="e.g. Pork Ribs"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1.5">Unit *</label>
                            <input v-model="newIngredient.unit" type="text" placeholder="e.g. kg, pcs, liters"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-medium text-muted-foreground block mb-1.5">Starting Stock</label>
                                <input v-model.number="newIngredient.current_quantity" type="number" min="0" step="0.01"
                                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="text-xs font-medium text-muted-foreground block mb-1.5">Minimum Stock</label>
                                <input v-model.number="newIngredient.min_quantity" type="number" min="0" step="0.01"
                                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1.5">Cost per Unit (₱)</label>
                            <input v-model.number="newIngredient.cost_per_unit" type="number" min="0" step="0.01"
                                placeholder="0.00"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                            <p class="text-xs text-muted-foreground mt-1">Used to calculate product cost and COGS for P&amp;L reports.</p>
                        </div>
                    </div>
                    <div class="p-5 border-t flex gap-3">
                        <button @click="showAddIngredient = false"
                            class="flex-1 rounded-lg border py-2 text-sm font-medium hover:bg-muted">
                            Cancel
                        </button>
                        <button @click="submitAddIngredient" :disabled="addingIngredient"
                            class="flex-1 rounded-lg bg-primary py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                            {{ addingIngredient ? 'Adding…' : 'Add Ingredient' }}
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
                class="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:bg-black/50 sm:p-4"
                @click.self="selectedItem = null"
            >
                <div class="w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl bg-background shadow-2xl max-h-[90vh] overflow-y-auto">
                    <div class="p-5 border-b flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-bold">Adjust Stock</h3>
                            <p class="text-sm text-muted-foreground">{{ selectedItem.name }}</p>
                        </div>
                        <button @click="selectedItem = null" class="rounded-full p-1 hover:bg-muted">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="p-5 space-y-4">
                        <div class="rounded-lg bg-muted/40 p-3 text-sm flex justify-between">
                            <span class="text-muted-foreground">Current Stock</span>
                            <span class="font-bold">{{ selectedItem.current_quantity.toFixed(2) }} {{ selectedItem.unit }}</span>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1.5">Adjustment Type</label>
                            <select v-model="adjustType" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                                <option value="stock_in">Stock In (Add)</option>
                                <option value="stock_out">Stock Out (Remove)</option>
                                <option value="adjustment">Manual Adjustment (Set to)</option>
                                <option value="waste">Waste (Deduct)</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1.5">
                                {{ adjustType === 'adjustment' ? 'New Quantity' : 'Quantity' }}
                                ({{ selectedItem.unit }})
                            </label>
                            <input
                                v-model.number="adjustQty"
                                type="number" min="0" step="0.01"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1.5">Notes (optional)</label>
                            <textarea
                                v-model="adjustNotes"
                                rows="2"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary resize-none"
                                placeholder="Reason for adjustment…"
                            />
                        </div>
                    </div>
                    <div class="p-5 border-t flex gap-3">
                        <button @click="selectedItem = null" class="flex-1 rounded-lg border py-2 text-sm font-medium hover:bg-muted">
                            Cancel
                        </button>
                        <button
                            @click="submitAdjustment"
                            :disabled="submitting"
                            class="flex-1 rounded-lg bg-primary py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            <RefreshCw v-if="submitting" class="inline h-3 w-3 animate-spin mr-1" />
                            {{ submitting ? 'Saving…' : 'Save Adjustment' }}
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
                class="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:bg-black/50 sm:p-4"
                @click.self="editingIngredient = null"
            >
                <div class="w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl bg-background shadow-2xl max-h-[90vh] overflow-y-auto">
                    <div class="p-5 border-b flex items-center justify-between">
                        <h3 class="text-lg font-bold">Edit Ingredient</h3>
                        <button @click="editingIngredient = null" class="rounded-full p-1 hover:bg-muted">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="p-5 space-y-4">
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1.5">Item Type *</label>
                            <div class="grid grid-cols-2 gap-2">
                                <label v-for="t in ITEM_TYPES" :key="t.value"
                                    :class="['flex items-center gap-2 rounded-lg border p-2.5 cursor-pointer transition', editForm.item_type === t.value ? 'border-primary bg-primary/5' : 'hover:bg-muted/40']">
                                    <input type="radio" v-model="editForm.item_type" :value="t.value" class="accent-primary" />
                                    <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', t.color]">{{ t.label }}</span>
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1.5">Name *</label>
                            <input v-model="editForm.name" type="text"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1.5">Unit *</label>
                            <input v-model="editForm.unit" type="text" placeholder="e.g. kg, pcs, liters"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1.5">Minimum Stock</label>
                            <input v-model.number="editForm.min_quantity" type="number" min="0" step="0.01"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1.5">Cost per Unit (₱)</label>
                            <input v-model.number="editForm.cost_per_unit" type="number" min="0" step="0.0001"
                                placeholder="0.00"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                            <p class="text-xs text-muted-foreground mt-1">
                                Cost per {{ editForm.unit || 'unit' }}. Used to calculate product COGS for P&amp;L reports.
                            </p>
                        </div>
                    </div>
                    <div class="p-5 border-t flex gap-3">
                        <button @click="editingIngredient = null"
                            class="flex-1 rounded-lg border py-2 text-sm font-medium hover:bg-muted">
                            Cancel
                        </button>
                        <button @click="submitEdit" :disabled="savingEdit"
                            class="flex-1 rounded-lg bg-primary py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                            {{ savingEdit ? 'Saving…' : 'Save Changes' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.15s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
