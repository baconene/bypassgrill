<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { ArrowLeft, ShoppingBag, User, MapPin, Clock, CreditCard, Package, Receipt, Printer, Pencil, X, Plus, Minus, Trash2, Check, Search, Eye, Copy } from 'lucide-vue-next'
import { toast } from 'vue-sonner'
import api from '@/utils/api'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Order Detail', href: '#' },
        ],
    },
})

interface Modifier  { name: string; price: number }
interface OrderItem {
    id: number; product_id: number; product_name: string; category_name: string | null
    quantity: number; unit_price: number; unit_cost: number
    subtotal: number; cost_subtotal: number
    special_instructions: string | null; modifiers: Modifier[]
}
interface Payment { id: number; amount: number; tender: string; status: string; reference: string | null; created_at: string }
interface Order {
    id: number; queue_number: number | null; order_type: string; order_type_label: string
    status: string; payment_status: string; table_number: string | null
    customer_name: string | null; customer_contact: string | null; customer_address: string | null
    notes: string | null; subtotal: number; discount_amount: number; tax_amount: number; total_amount: number
    created_at: string; completed_at: string | null; created_by: string | null
    public_token: string | null
    items: OrderItem[]; payments: Payment[]
}
interface Product { id: number; name: string; price: number; category?: { name: string } | null }

interface EditItem { product_id: number; product_name: string; unit_price: number; quantity: number }

const props = defineProps<{ order: Order }>()

const printing    = ref(false)
const editing     = ref(false)
const saving      = ref(false)
const showPublicUrl = ref(false)
const urlCopied     = ref(false)

const publicUrl = computed(() =>
    props.order.public_token
        ? `${window.location.origin}/public/orders/${props.order.public_token}`
        : null
)

const copyPublicUrl = async () => {
    if (!publicUrl.value) return
    await navigator.clipboard.writeText(publicUrl.value)
    urlCopied.value = true
    setTimeout(() => { urlCopied.value = false }, 2000)
}

const products     = ref<Product[]>([])
const productSearch = ref('')
const showDropdown  = ref(false)

const editNotes      = ref('')
const editDiscount   = ref(0)
const editCreatedAt  = ref('')
const editItems      = ref<EditItem[]>([])

const backUrl = new URLSearchParams(window.location.search).get('back')
const goBack = () => backUrl ? router.visit(backUrl) : window.history.back()

const fmt = (v: number) => '₱' + v.toLocaleString('en-PH', { minimumFractionDigits: 2 })

const fmtDatetime = (s: string | null) => {
    if (!s) return '—'
    return new Date(s.replace(' ', 'T')).toLocaleString('en-PH', {
        month: 'short', day: 'numeric', year: 'numeric',
        hour: '2-digit', minute: '2-digit', hour12: true,
    })
}

const statusColor = (s: string) => ({
    pending:   'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
    preparing: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    ready:     'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
    completed: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    cancelled: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
}[s] ?? 'bg-muted text-muted-foreground')

const payColor = (s: string) => ({
    paid:     'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    pending:  'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
    refunded: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
    voided:   'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
}[s] ?? 'bg-muted text-muted-foreground')

const totalCost   = computed(() => props.order.items.reduce((s, i) => s + i.cost_subtotal, 0))
const grossProfit = computed(() => props.order.total_amount - totalCost.value)

const editTotal = computed(() =>
    Math.max(0, editItems.value.reduce((s, i) => s + i.unit_price * i.quantity, 0) - (editDiscount.value || 0))
)

const filteredProducts = computed(() => {
    const q = productSearch.value.toLowerCase().trim()
    if (!q) return products.value.slice(0, 20)
    return products.value.filter(p => p.name.toLowerCase().includes(q)).slice(0, 20)
})

const startEdit = async () => {
    if (!products.value.length) {
        try {
            const res = await api.get('/api/v1/products')
            products.value = res.data
        } catch {
            toast.error('Could not load products')
            return
        }
    }
    editNotes.value     = props.order.notes ?? ''
    editDiscount.value  = props.order.discount_amount ?? 0
    editCreatedAt.value = props.order.created_at ? props.order.created_at.replace(' ', 'T').substring(0, 16) : ''
    editItems.value     = props.order.items.map(i => ({
        product_id:   i.product_id,
        product_name: i.product_name,
        unit_price:   i.unit_price,
        quantity:     i.quantity,
    }))
    productSearch.value = ''
    showDropdown.value  = false
    editing.value = true
}

const cancelEdit = () => { editing.value = false }

const addProduct = (p: Product) => {
    const existing = editItems.value.find(i => i.product_id === p.id)
    if (existing) {
        existing.quantity++
    } else {
        editItems.value.push({ product_id: p.id, product_name: p.name, unit_price: p.price, quantity: 1 })
    }
    productSearch.value = ''
    showDropdown.value  = false
}

const changeQty = (index: number, delta: number) => {
    const item = editItems.value[index]
    item.quantity = Math.max(1, item.quantity + delta)
}

const removeItem = (index: number) => {
    editItems.value.splice(index, 1)
}

const saveEdit = async () => {
    if (editItems.value.length === 0) {
        toast.error('Order must have at least one item')
        return
    }
    saving.value = true
    try {
        await api.put('/api/v1/orders/' + props.order.id, {
            notes:           editNotes.value || null,
            discount_amount: editDiscount.value || 0,
            created_at:      editCreatedAt.value || null,
            items:           editItems.value.map(i => ({ product_id: i.product_id, quantity: i.quantity })),
        })
        toast.success('Order updated')
        editing.value = false
        router.reload()
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? err?.message ?? 'Save failed')
    } finally {
        saving.value = false
    }
}

const reprintReceipt = async () => {
    printing.value = true
    try {
        await api.post('/api/v1/print-jobs', { order_id: props.order.id })
        toast.success('Receipt sent to printer')
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? err?.message ?? 'Print failed')
    } finally {
        printing.value = false
    }
}
</script>

<template>
    <Head :title="`Order #${order.id}`" />

    <div class="max-w-3xl mx-auto space-y-4">

        <!-- Back + Header -->
        <div class="flex items-start gap-2 sm:gap-3">
            <button @click="goBack()"
                class="rounded-lg border p-2 hover:bg-muted text-muted-foreground shrink-0 mt-0.5">
                <ArrowLeft class="h-4 w-4" />
            </button>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-1.5 flex-wrap">
                    <h1 class="text-lg sm:text-xl font-black flex items-center gap-1.5">
                        <ShoppingBag class="h-4 w-4 sm:h-5 sm:w-5 text-primary shrink-0" />
                        Order #{{ order.id }}
                    </h1>
                    <span v-if="order.queue_number" class="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold">
                        Q{{ order.queue_number }}
                    </span>
                    <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold capitalize', statusColor(order.status)]">
                        {{ order.status }}
                    </span>
                    <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold capitalize', payColor(order.payment_status)]">
                        {{ order.payment_status }}
                    </span>
                </div>
                <p class="text-xs text-muted-foreground mt-0.5">
                    {{ order.order_type_label }}
                    <template v-if="order.table_number"> · Table {{ order.table_number }}</template>
                    <template v-if="order.created_by"> · by {{ order.created_by }}</template>
                </p>
            </div>
            <div class="hidden sm:flex items-center gap-2 shrink-0">
                <button @click="startEdit" v-if="!editing"
                    class="flex items-center gap-2 rounded-lg border bg-card px-4 py-2 text-sm font-semibold hover:bg-muted transition-colors">
                    <Pencil class="h-4 w-4" /> Edit
                </button>
                <button v-if="publicUrl" @click="showPublicUrl = true"
                    class="flex items-center gap-2 rounded-lg border bg-card px-4 py-2 text-sm font-semibold hover:bg-muted transition-colors">
                    <Eye class="h-4 w-4" /> Public URL
                </button>
                <button @click="reprintReceipt" :disabled="printing"
                    class="flex items-center gap-2 rounded-lg border bg-card px-4 py-2 text-sm font-semibold hover:bg-muted disabled:opacity-50 transition-colors">
                    <Printer class="h-4 w-4" />
                    {{ printing ? 'Printing…' : 'Reprint Receipt' }}
                </button>
            </div>
            <div class="sm:hidden flex items-center gap-1.5 shrink-0 mt-0.5">
                <button @click="startEdit" v-if="!editing"
                    class="rounded-lg border bg-card p-2 hover:bg-muted transition-colors" title="Edit Order">
                    <Pencil class="h-4 w-4" />
                </button>
                <button v-if="publicUrl" @click="showPublicUrl = true"
                    class="rounded-lg border bg-card p-2 hover:bg-muted transition-colors" title="Public URL">
                    <Eye class="h-4 w-4" />
                </button>
                <button @click="reprintReceipt" :disabled="printing"
                    class="rounded-lg border bg-card p-2 hover:bg-muted disabled:opacity-50 transition-colors"
                    title="Reprint Receipt">
                    <Printer class="h-4 w-4" />
                </button>
            </div>
        </div>

        <div class="grid sm:grid-cols-2 gap-3 sm:gap-4">
            <div class="rounded-xl border bg-card shadow-sm p-4 space-y-3">
                <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                    <Clock class="h-3.5 w-3.5" /> Timeline
                </h3>
                <div class="space-y-2 text-sm">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-muted-foreground text-xs">Placed</span>
                        <span class="font-medium">{{ fmtDatetime(order.created_at) }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-muted-foreground text-xs">Completed</span>
                        <span class="font-medium">{{ fmtDatetime(order.completed_at) }}</span>
                    </div>
                </div>
            </div>

            <div v-if="order.customer_name || order.table_number" class="rounded-xl border bg-card shadow-sm p-4 space-y-3">
                <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                    <User class="h-3.5 w-3.5" /> Customer
                </h3>
                <div class="space-y-1.5 text-sm">
                    <div v-if="order.table_number" class="flex justify-between gap-3">
                        <span class="text-muted-foreground shrink-0">Table</span>
                        <span class="font-medium text-right">{{ order.table_number }}</span>
                    </div>
                    <div v-if="order.customer_name" class="flex justify-between gap-3">
                        <span class="text-muted-foreground shrink-0">Name</span>
                        <span class="font-medium text-right break-all">{{ order.customer_name }}</span>
                    </div>
                    <div v-if="order.customer_contact" class="flex justify-between gap-3">
                        <span class="text-muted-foreground shrink-0">Contact</span>
                        <span class="font-medium text-right">{{ order.customer_contact }}</span>
                    </div>
                    <div v-if="order.customer_address" class="flex items-start justify-between gap-3">
                        <span class="text-muted-foreground flex items-center gap-1 shrink-0">
                            <MapPin class="h-3 w-3" /> Address
                        </span>
                        <span class="font-medium text-right">{{ order.customer_address }}</span>
                    </div>
                </div>
            </div>

            <div v-else class="rounded-xl border bg-card shadow-sm p-4 space-y-3">
                <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                    <ShoppingBag class="h-3.5 w-3.5" /> Order Info
                </h3>
                <div class="space-y-1.5 text-sm">
                    <div class="flex justify-between gap-3">
                        <span class="text-muted-foreground shrink-0">Type</span>
                        <span class="font-medium text-right">{{ order.order_type_label }}</span>
                    </div>
                    <div v-if="order.table_number" class="flex justify-between gap-3">
                        <span class="text-muted-foreground shrink-0">Table</span>
                        <span class="font-medium text-right">{{ order.table_number }}</span>
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-muted-foreground shrink-0">Cashier</span>
                        <span class="font-medium text-right">{{ order.created_by ?? '—' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit panel -->
        <div v-if="editing" class="rounded-xl border-2 border-primary bg-card shadow-sm overflow-hidden">
            <div class="p-4 border-b flex items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <Pencil class="h-4 w-4 text-primary" />
                    <h2 class="font-bold text-sm">Edit Order #{{ order.id }}</h2>
                </div>
                <button @click="cancelEdit" class="rounded-lg p-1.5 hover:bg-muted text-muted-foreground transition-colors" title="Cancel">
                    <X class="h-4 w-4" />
                </button>
            </div>

            <div class="p-4 space-y-4">
                <!-- Notes -->
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wider text-muted-foreground block mb-1">Notes</label>
                    <textarea v-model="editNotes" rows="2"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-primary"
                        placeholder="Order notes…" />
                </div>

                <!-- Date & Time -->
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wider text-muted-foreground block mb-1">Date &amp; Time</label>
                    <input v-model="editCreatedAt" type="datetime-local"
                        class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>

                <!-- Discount -->
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wider text-muted-foreground block mb-1">Discount (₱)</label>
                    <input v-model.number="editDiscount" type="number" min="0" step="0.01"
                        class="w-40 rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>

                <!-- Items -->
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wider text-muted-foreground block mb-2">Items</label>
                    <div class="divide-y border rounded-lg overflow-hidden">
                        <div v-for="(item, idx) in editItems" :key="item.product_id"
                            class="flex items-center gap-3 px-3 py-2.5 bg-background">
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-sm truncate">{{ item.product_name }}</p>
                                <p class="text-xs text-muted-foreground">{{ fmt(item.unit_price) }} each</p>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <button @click="changeQty(idx, -1)"
                                    class="rounded-md border p-1 hover:bg-muted transition-colors">
                                    <Minus class="h-3.5 w-3.5" />
                                </button>
                                <span class="w-8 text-center font-bold text-sm">{{ item.quantity }}</span>
                                <button @click="changeQty(idx, 1)"
                                    class="rounded-md border p-1 hover:bg-muted transition-colors">
                                    <Plus class="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <span class="w-20 text-right font-bold text-sm shrink-0">{{ fmt(item.unit_price * item.quantity) }}</span>
                            <button @click="removeItem(idx)"
                                class="text-red-500 hover:text-red-700 transition-colors p-1 shrink-0">
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                        <div v-if="editItems.length === 0" class="px-3 py-4 text-sm text-muted-foreground text-center">
                            No items. Add a product below.
                        </div>
                    </div>
                </div>

                <!-- Add product search -->
                <div class="relative">
                    <label class="text-xs font-semibold uppercase tracking-wider text-muted-foreground block mb-1">Add Product</label>
                    <div class="flex items-center gap-2 rounded-lg border bg-background px-3 py-2">
                        <Search class="h-4 w-4 text-muted-foreground shrink-0" />
                        <input v-model="productSearch"
                            @focus="showDropdown = true"
                            @blur="setTimeout(() => showDropdown = false, 150)"
                            class="flex-1 bg-transparent text-sm focus:outline-none"
                            placeholder="Search product name…" />
                    </div>
                    <div v-if="showDropdown && filteredProducts.length"
                        class="absolute z-20 mt-1 w-full rounded-lg border bg-popover shadow-lg max-h-56 overflow-y-auto">
                        <button v-for="p in filteredProducts" :key="p.id"
                            @mousedown.prevent="addProduct(p)"
                            class="w-full flex items-center justify-between gap-2 px-3 py-2 hover:bg-muted text-sm text-left transition-colors">
                            <span class="font-medium truncate">{{ p.name }}</span>
                            <span class="text-muted-foreground shrink-0">{{ fmt(p.price) }}</span>
                        </button>
                    </div>
                </div>

                <!-- Edit total -->
                <div class="flex items-center justify-between border-t pt-3">
                    <span class="text-sm font-semibold text-muted-foreground">Estimated Total</span>
                    <span class="text-lg font-black text-primary">{{ fmt(editTotal) }}</span>
                </div>

                <!-- Save / Cancel -->
                <div class="flex items-center gap-3 pt-1">
                    <button @click="saveEdit" :disabled="saving || editItems.length === 0"
                        class="flex items-center gap-2 rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-colors">
                        <Check class="h-4 w-4" />
                        {{ saving ? 'Saving…' : 'Save Changes' }}
                    </button>
                    <button @click="cancelEdit"
                        class="rounded-xl border px-5 py-2.5 text-sm font-semibold hover:bg-muted transition-colors">
                        Cancel
                    </button>
                </div>
            </div>
        </div>

        <!-- Read-only items card -->
        <div v-else class="rounded-xl border bg-card shadow-sm overflow-hidden">
            <div class="p-4 border-b flex items-center gap-2">
                <Package class="h-4 w-4 text-muted-foreground" />
                <h2 class="font-bold text-sm">Items ({{ order.items.length }})</h2>
            </div>

            <!-- Mobile: card list -->
            <div class="sm:hidden divide-y">
                <div v-for="item in order.items" :key="item.id" class="px-4 py-3 space-y-1">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-sm leading-snug">{{ item.product_name }}</p>
                            <p v-if="item.category_name" class="text-xs text-muted-foreground">{{ item.category_name }}</p>
                        </div>
                        <p class="font-bold text-sm shrink-0">{{ fmt(item.subtotal) }}</p>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-muted-foreground flex-wrap">
                        <span>× {{ item.quantity }} @ {{ fmt(item.unit_price) }}</span>
                        <span v-if="item.unit_cost > 0" class="text-muted-foreground/60">cost {{ fmt(item.cost_subtotal) }}</span>
                    </div>
                    <div v-if="item.modifiers.length" class="flex flex-wrap gap-1 mt-1">
                        <span v-for="m in item.modifiers" :key="m.name"
                            class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                            +{{ m.name }} ({{ fmt(m.price) }})
                        </span>
                    </div>
                    <p v-if="item.special_instructions" class="text-xs italic text-muted-foreground">
                        "{{ item.special_instructions }}"
                    </p>
                </div>
            </div>

            <!-- Desktop: table -->
            <div class="hidden sm:block overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-muted-foreground text-xs uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3 text-left">Product</th>
                            <th class="px-4 py-3 text-center">Qty</th>
                            <th class="px-4 py-3 text-right">Unit Price</th>
                            <th class="px-4 py-3 text-right">Subtotal</th>
                            <th class="px-4 py-3 text-right">Cost</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="item in order.items" :key="item.id" class="hover:bg-muted/20">
                            <td class="px-4 py-3">
                                <p class="font-semibold">{{ item.product_name }}</p>
                                <p v-if="item.category_name" class="text-xs text-muted-foreground">{{ item.category_name }}</p>
                                <div v-if="item.modifiers.length" class="mt-1 flex flex-wrap gap-1">
                                    <span v-for="m in item.modifiers" :key="m.name"
                                        class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                        +{{ m.name }} ({{ fmt(m.price) }})
                                    </span>
                                </div>
                                <p v-if="item.special_instructions" class="text-xs italic text-muted-foreground mt-0.5">
                                    "{{ item.special_instructions }}"
                                </p>
                            </td>
                            <td class="px-4 py-3 text-center font-bold">× {{ item.quantity }}</td>
                            <td class="px-4 py-3 text-right">{{ fmt(item.unit_price) }}</td>
                            <td class="px-4 py-3 text-right font-bold">{{ fmt(item.subtotal) }}</td>
                            <td class="px-4 py-3 text-right text-muted-foreground text-xs">
                                {{ item.unit_cost > 0 ? fmt(item.cost_subtotal) : '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid sm:grid-cols-2 gap-3 sm:gap-4">
            <div class="rounded-xl border bg-card shadow-sm p-4 space-y-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                    <Receipt class="h-3.5 w-3.5" /> Totals
                </h3>
                <div class="space-y-1.5 text-sm">
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Subtotal</span>
                        <span>{{ fmt(order.subtotal) }}</span>
                    </div>
                    <div v-if="order.discount_amount > 0" class="flex justify-between text-red-500">
                        <span>Discount</span>
                        <span>−{{ fmt(order.discount_amount) }}</span>
                    </div>
                    <div v-if="order.tax_amount > 0" class="flex justify-between">
                        <span class="text-muted-foreground">Tax</span>
                        <span>{{ fmt(order.tax_amount) }}</span>
                    </div>
                    <div class="flex justify-between font-black text-base border-t pt-2">
                        <span>Total</span>
                        <span>{{ fmt(order.total_amount) }}</span>
                    </div>
                    <template v-if="totalCost > 0">
                        <div class="flex justify-between text-xs text-muted-foreground border-t pt-2">
                            <span>COGS</span>
                            <span>−{{ fmt(totalCost) }}</span>
                        </div>
                        <div class="flex justify-between text-xs font-semibold"
                            :class="grossProfit >= 0 ? 'text-green-600' : 'text-red-600'">
                            <span>Gross Profit</span>
                            <span>{{ fmt(grossProfit) }}</span>
                        </div>
                    </template>
                </div>
            </div>

            <div class="rounded-xl border bg-card shadow-sm p-4 space-y-3">
                <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                    <CreditCard class="h-3.5 w-3.5" /> Payments
                </h3>
                <div v-if="order.payments.length === 0" class="text-sm text-muted-foreground">No payments recorded.</div>
                <div v-else class="space-y-2">
                    <div v-for="p in order.payments" :key="p.id"
                        class="flex items-center justify-between rounded-lg bg-muted/40 px-3 py-2 text-sm gap-2">
                        <div class="min-w-0">
                            <p class="font-semibold truncate">{{ p.tender }}</p>
                            <p v-if="p.reference" class="text-xs text-muted-foreground truncate">Ref: {{ p.reference }}</p>
                            <p class="text-xs text-muted-foreground">{{ fmtDatetime(p.created_at) }}</p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="font-bold text-green-600">{{ fmt(p.amount) }}</p>
                            <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold capitalize', payColor(p.status)]">
                                {{ p.status }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="order.notes" class="rounded-xl border bg-card shadow-sm p-4">
            <p class="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2">Notes</p>
            <p class="text-sm">{{ order.notes }}</p>
        </div>

        <!-- Reprint footer button (mobile prominent, desktop redundant) -->
        <div class="flex justify-center pb-4">
            <button @click="reprintReceipt" :disabled="printing"
                class="flex items-center gap-2 rounded-xl bg-primary px-6 py-3 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-colors w-full sm:w-auto justify-center">
                <Printer class="h-4 w-4" />
                {{ printing ? 'Printing…' : 'Reprint Receipt' }}
            </button>
        </div>
    </div>

    <!-- Public URL Modal -->
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="showPublicUrl"
                class="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:bg-black/50 sm:p-4"
                @click.self="showPublicUrl = false">
                <div class="w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl bg-background shadow-2xl overflow-hidden">
                    <div class="p-4 border-b flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <Eye class="h-4 w-4 text-primary" />
                            <h3 class="font-bold text-sm">Public Order URL</h3>
                        </div>
                        <button @click="showPublicUrl = false" class="rounded-full p-1 hover:bg-muted">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="p-4 space-y-4">
                        <p class="text-xs text-muted-foreground">
                            Share this link with the customer so they can track their order status.
                        </p>
                        <div class="flex items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2.5">
                            <span class="flex-1 text-xs break-all font-mono text-foreground select-all">{{ publicUrl }}</span>
                        </div>
                        <div class="flex gap-2">
                            <button @click="copyPublicUrl"
                                :class="['flex-1 flex items-center justify-center gap-2 rounded-lg py-2.5 text-sm font-bold transition-colors',
                                    urlCopied
                                        ? 'bg-green-600 text-white'
                                        : 'bg-primary text-primary-foreground hover:bg-primary/90']">
                                <Check v-if="urlCopied" class="h-4 w-4" />
                                <Copy v-else class="h-4 w-4" />
                                {{ urlCopied ? 'Copied!' : 'Copy Link' }}
                            </button>
                            <a :href="publicUrl!" target="_blank"
                                class="flex items-center justify-center gap-2 rounded-lg border px-4 py-2.5 text-sm font-semibold hover:bg-muted transition-colors">
                                <Eye class="h-4 w-4" /> Open
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
