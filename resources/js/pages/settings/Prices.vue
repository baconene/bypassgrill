<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import { Save, ChevronDown, ChevronRight, RotateCcw } from 'lucide-vue-next'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/profile' },
            { title: 'Prices', href: '/settings/prices' },
        ],
    },
})

interface ProductRow {
    id: number
    name: string
    sku: string | null
    price: number
    cost: number
    is_active: boolean
}

interface CategoryGroup {
    id: number
    name: string
    products: ProductRow[]
}

const props = defineProps<{ categories: CategoryGroup[] }>()

// ── Local editable copy ────────────────────────────────────────────────────────
type EditMap = Record<number, { price: string; cost: string }>
const edits = ref<EditMap>({})

function initEdits() {
    const map: EditMap = {}
    for (const cat of props.categories) {
        for (const p of cat.products) {
            map[p.id] = { price: p.price.toFixed(2), cost: p.cost.toFixed(2) }
        }
    }
    edits.value = map
}
initEdits()

const dirty = computed(() => {
    for (const cat of props.categories) {
        for (const p of cat.products) {
            const e = edits.value[p.id]
            if (!e) continue
            if (parseFloat(e.price) !== p.price || parseFloat(e.cost) !== p.cost) return true
        }
    }
    return false
})

function resetRow(id: number, price: number, cost: number) {
    edits.value[id] = { price: price.toFixed(2), cost: cost.toFixed(2) }
}

// ── Collapsible categories ─────────────────────────────────────────────────────
const collapsed = ref<Record<number, boolean>>({})
const toggleCat = (id: number) => { collapsed.value[id] = !collapsed.value[id] }

// ── Save ───────────────────────────────────────────────────────────────────────
const saving = ref(false)

function save() {
    const prices = Object.entries(edits.value).map(([id, e]) => ({
        id: parseInt(id),
        price: parseFloat(e.price) || 0,
        cost:  parseFloat(e.cost)  || 0,
    }))

    saving.value = true
    router.post('/settings/prices', { prices, _method: 'patch' }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Prices saved.'),
        onError: (e) => toast.error(Object.values(e)[0] as string ?? 'Save failed'),
        onFinish: () => { saving.value = false },
    })
}

// ── Profit margin helper ───────────────────────────────────────────────────────
function margin(price: string, cost: string): string {
    const p = parseFloat(price) || 0
    const c = parseFloat(cost)  || 0
    if (p <= 0) return '—'
    return ((p - c) / p * 100).toFixed(1) + '%'
}

function marginColor(price: string, cost: string): string {
    const p = parseFloat(price) || 0
    const c = parseFloat(cost)  || 0
    if (p <= 0) return 'text-muted-foreground'
    const m = (p - c) / p * 100
    if (m >= 50) return 'text-green-600 dark:text-green-400'
    if (m >= 25) return 'text-yellow-600 dark:text-yellow-400'
    return 'text-red-600 dark:text-red-400'
}
</script>

<template>
    <Head title="Price Management" />

    <div class="space-y-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold">Price Management</h2>
                <p class="text-sm text-muted-foreground mt-0.5">
                    Edit selling price and cost for all products. Changes apply immediately after saving.
                </p>
            </div>
            <button
                @click="save"
                :disabled="!dirty || saving"
                class="flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-40 shrink-0"
            >
                <Save class="h-4 w-4" />
                {{ saving ? 'Saving…' : 'Save All' }}
            </button>
        </div>

        <!-- unsaved changes notice -->
        <div v-if="dirty" class="rounded-lg border border-yellow-300 bg-yellow-50 dark:bg-yellow-950/20 dark:border-yellow-800 px-4 py-3 text-sm text-yellow-800 dark:text-yellow-300 font-medium">
            You have unsaved price changes. Click <strong>Save All</strong> to apply them.
        </div>

        <!-- empty state -->
        <div v-if="!categories.length"
            class="rounded-xl border border-dashed border-border p-12 text-center text-muted-foreground">
            <p class="font-medium">No products found.</p>
            <p class="text-sm mt-1">Add products first in the Products section.</p>
        </div>

        <!-- category groups -->
        <div v-for="cat in categories" :key="cat.id" class="rounded-xl border bg-card shadow-sm overflow-hidden">
            <!-- category header -->
            <button
                @click="toggleCat(cat.id)"
                class="flex min-h-11 w-full items-center justify-between bg-muted/40 px-3 text-left transition hover:bg-muted/70 sm:px-4"
                :aria-expanded="!collapsed[cat.id]"
            >
                <div class="flex items-center gap-2">
                    <component :is="collapsed[cat.id] ? ChevronRight : ChevronDown" class="h-4 w-4 text-muted-foreground" />
                    <span class="font-semibold text-sm">{{ cat.name }}</span>
                    <span class="text-xs text-muted-foreground">({{ cat.products.length }} item{{ cat.products.length !== 1 ? 's' : '' }})</span>
                </div>
            </button>

            <!-- product rows -->
            <div v-if="!collapsed[cat.id]" class="divide-y divide-border">
                <!-- header row -->
                <div class="hidden @xl:grid grid-cols-[minmax(0,1fr)_7rem_7rem_4rem_2rem] gap-2 px-4 py-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                    <span>Product</span>
                    <span>Selling Price (₱)</span>
                    <span>Cost (₱)</span>
                    <span>Margin</span>
                    <span></span>
                </div>

                <div
                    v-for="product in cat.products" :key="product.id"
                    :class="['grid grid-cols-2 @xl:grid-cols-[minmax(0,1fr)_7rem_7rem_4rem_2rem] gap-2 items-center px-3 py-2 sm:px-4',
                        !product.is_active && 'opacity-50']"
                >
                    <!-- name + sku -->
                    <div class="col-span-2 min-w-0 @xl:col-span-1">
                        <p class="font-medium text-sm break-words">{{ product.name }}</p>
                        <p v-if="product.sku" class="text-xs text-muted-foreground">{{ product.sku }}</p>
                        <p v-if="!product.is_active" class="text-xs text-muted-foreground italic">Inactive</p>
                    </div>

                    <!-- price -->
                    <div>
                        <label :for="`price-${product.id}`" class="mb-0.5 block text-xs text-muted-foreground @xl:sr-only">Selling price <span class="sr-only">for {{ product.name }}</span></label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">₱</span>
                            <input
                                v-if="edits[product.id]"
                                v-model="edits[product.id].price"
                                :id="`price-${product.id}`"
                                type="number" min="0" step="0.01"
                                class="w-full rounded-lg border pl-7 pr-2 py-1.5 text-base @xl:text-sm bg-background focus:outline-none focus:ring-2 focus:ring-primary"
                            />
                        </div>
                    </div>

                    <!-- cost -->
                    <div>
                        <label :for="`cost-${product.id}`" class="mb-0.5 block text-xs text-muted-foreground @xl:sr-only">Cost <span class="sr-only">for {{ product.name }}</span></label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">₱</span>
                            <input
                                v-if="edits[product.id]"
                                v-model="edits[product.id].cost"
                                :id="`cost-${product.id}`"
                                type="number" min="0" step="0.01"
                                class="w-full rounded-lg border pl-7 pr-2 py-1.5 text-base @xl:text-sm bg-background focus:outline-none focus:ring-2 focus:ring-primary"
                            />
                        </div>
                    </div>

                    <!-- margin -->
                    <div class="flex flex-wrap items-center gap-x-1.5">
                        <span class="text-xs text-muted-foreground @xl:hidden">Margin</span>
                        <span v-if="edits[product.id]" :class="['text-sm font-semibold', marginColor(edits[product.id].price, edits[product.id].cost)]">
                            {{ margin(edits[product.id].price, edits[product.id].cost) }}
                        </span>
                    </div>

                    <!-- reset row -->
                    <div class="flex justify-end">
                        <button
                            @click="resetRow(product.id, product.price, product.cost)"
                            :title="'Reset ' + product.name"
                            :aria-label="'Reset prices for ' + product.name"
                            class="grid size-10 place-items-center rounded text-muted-foreground transition hover:bg-muted hover:text-foreground @xl:size-8"
                        >
                            <RotateCcw class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- save footer -->
        <div v-if="dirty" class="flex justify-end">
            <button
                @click="save"
                :disabled="saving"
                class="flex items-center gap-1.5 rounded-lg bg-primary px-6 py-2.5 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-40"
            >
                <Save class="h-4 w-4" />
                {{ saving ? 'Saving…' : 'Save All Prices' }}
            </button>
        </div>
    </div>
</template>
