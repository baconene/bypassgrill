<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { ArrowLeft, ShoppingBag, User, MapPin, Clock, CreditCard, Package, Receipt, Printer, Pencil, X, Plus, Minus, Trash2, Check, Search, Eye, Copy, ChevronRight } from 'lucide-vue-next'
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

const statusMeta = computed(() => ({
    pending:   { label: 'Pending',   cls: 'status-pending',   dot: 'bg-yellow-400', ring: 'ring-yellow-400/20', pulse: true },
    preparing: { label: 'Preparing', cls: 'status-preparing', dot: 'bg-blue-400',   ring: 'ring-blue-400/20',   pulse: true },
    ready:     { label: 'Ready',     cls: 'status-ready',     dot: 'bg-purple-400', ring: 'ring-purple-400/20', pulse: false },
    completed: { label: 'Completed', cls: 'status-completed', dot: 'bg-green-400',  ring: 'ring-green-400/20',  pulse: false },
    cancelled: { label: 'Cancelled', cls: 'status-cancelled', dot: 'bg-red-400',    ring: 'ring-red-400/20',    pulse: false },
}[props.order.status] ?? { label: props.order.status, cls: '', dot: 'bg-muted-foreground', ring: '', pulse: false }))

const payMeta = computed(() => ({
    paid:     { label: 'Paid',     cls: 'pay-paid' },
    pending:  { label: 'Unpaid',   cls: 'pay-pending' },
    refunded: { label: 'Refunded', cls: 'pay-refunded' },
    voided:   { label: 'Voided',   cls: 'pay-voided' },
}[props.order.payment_status] ?? { label: props.order.payment_status, cls: '' }))

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

    <div class="max-w-3xl mx-auto space-y-4 pb-8">

        <!-- ── Top nav bar ────────────────────────────────── -->
        <div class="flex items-center gap-3">
            <button @click="goBack()"
                class="shrink-0 rounded-xl border bg-card p-2 hover:bg-muted text-muted-foreground transition-colors">
                <ArrowLeft class="h-4 w-4" />
            </button>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-xs text-muted-foreground font-medium">Orders</span>
                    <ChevronRight class="h-3 w-3 text-muted-foreground/50" />
                    <span class="text-xs font-semibold">#{{ order.id }}</span>
                </div>
            </div>
            <!-- Desktop actions -->
            <div class="hidden sm:flex items-center gap-2 shrink-0">
                <button @click="startEdit" v-if="!editing"
                    class="flex items-center gap-1.5 rounded-xl border bg-card px-3.5 py-2 text-sm font-semibold hover:bg-muted transition-colors">
                    <Pencil class="h-3.5 w-3.5" /> Edit
                </button>
                <button v-if="publicUrl" @click="showPublicUrl = true"
                    class="flex items-center gap-1.5 rounded-xl border bg-card px-3.5 py-2 text-sm font-semibold hover:bg-muted transition-colors">
                    <Eye class="h-3.5 w-3.5" /> Share
                </button>
                <button @click="reprintReceipt" :disabled="printing"
                    class="flex items-center gap-1.5 rounded-xl bg-primary px-3.5 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-colors">
                    <Printer class="h-3.5 w-3.5" />
                    {{ printing ? 'Printing…' : 'Reprint' }}
                </button>
            </div>
            <!-- Mobile icon actions -->
            <div class="sm:hidden flex items-center gap-1.5 shrink-0">
                <button @click="startEdit" v-if="!editing"
                    class="rounded-xl border bg-card p-2 hover:bg-muted transition-colors">
                    <Pencil class="h-4 w-4" />
                </button>
                <button v-if="publicUrl" @click="showPublicUrl = true"
                    class="rounded-xl border bg-card p-2 hover:bg-muted transition-colors">
                    <Eye class="h-4 w-4" />
                </button>
                <button @click="reprintReceipt" :disabled="printing"
                    class="rounded-xl bg-primary p-2 text-primary-foreground disabled:opacity-50 transition-colors">
                    <Printer class="h-4 w-4" />
                </button>
            </div>
        </div>

        <!-- ── Status Hero ─────────────────────────────────── -->
        <div :class="['hero-card rounded-2xl p-5 sm:p-6', statusMeta.cls]">
            <div class="flex items-start justify-between gap-4">
                <div class="space-y-1.5">
                    <div class="flex items-center gap-2">
                        <span class="relative flex h-2.5 w-2.5">
                            <span v-if="statusMeta.pulse"
                                :class="['absolute inline-flex h-full w-full animate-ping rounded-full opacity-75', statusMeta.dot]" />
                            <span :class="['relative inline-flex h-2.5 w-2.5 rounded-full', statusMeta.dot]" />
                        </span>
                        <span class="text-[11px] font-bold uppercase tracking-widest opacity-70">Status</span>
                    </div>
                    <h1 class="text-2xl sm:text-3xl font-black tracking-tight leading-none">
                        {{ statusMeta.label }}
                    </h1>
                    <p class="text-sm opacity-70 font-medium">
                        {{ order.order_type_label }}
                        <template v-if="order.table_number"> · Table {{ order.table_number }}</template>
                    </p>
                </div>

                <div class="text-right shrink-0 space-y-2">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-widest opacity-60">Order</p>
                        <p class="text-xl font-black">#{{ order.id }}</p>
                    </div>
                    <div v-if="order.queue_number"
                        class="inline-flex items-center gap-1 rounded-full bg-black/10 dark:bg-white/10 px-3 py-1">
                        <span class="text-xs font-black">Q{{ order.queue_number }}</span>
                    </div>
                </div>
            </div>

            <!-- Meta chips -->
            <div class="flex flex-wrap gap-2 mt-4">
                <span :class="['pay-badge rounded-full px-3 py-1 text-xs font-bold', payMeta.cls]">
                    {{ payMeta.label }}
                </span>
                <span class="rounded-full bg-black/10 dark:bg-white/10 px-3 py-1 text-xs font-semibold opacity-80">
                    {{ fmtDatetime(order.created_at) }}
                </span>
                <span v-if="order.created_by" class="rounded-full bg-black/10 dark:bg-white/10 px-3 py-1 text-xs font-semibold opacity-80">
                    by {{ order.created_by }}
                </span>
            </div>
        </div>

        <!-- ── Info grid ───────────────────────────────────── -->
        <div class="grid sm:grid-cols-2 gap-3">
            <!-- Timeline -->
            <div class="rounded-xl border bg-card shadow-sm p-4 space-y-3">
                <h3 class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">
                    <Clock class="h-3 w-3" /> Timeline
                </h3>
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <div class="w-1.5 h-1.5 rounded-full bg-primary shrink-0" />
                        <div>
                            <p class="text-[10px] text-muted-foreground uppercase tracking-wide font-medium">Placed</p>
                            <p class="text-sm font-semibold">{{ fmtDatetime(order.created_at) }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div :class="['w-1.5 h-1.5 rounded-full shrink-0', order.completed_at ? 'bg-green-500' : 'bg-muted']" />
                        <div>
                            <p class="text-[10px] text-muted-foreground uppercase tracking-wide font-medium">Completed</p>
                            <p class="text-sm font-semibold">{{ fmtDatetime(order.completed_at) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Customer / Order info -->
            <div v-if="order.customer_name || order.table_number" class="rounded-xl border bg-card shadow-sm p-4 space-y-3">
                <h3 class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">
                    <User class="h-3 w-3" /> Customer
                </h3>
                <div class="space-y-2 text-sm">
                    <div v-if="order.table_number" class="flex justify-between gap-2">
                        <span class="text-muted-foreground text-xs">Table</span>
                        <span class="font-semibold">{{ order.table_number }}</span>
                    </div>
                    <div v-if="order.customer_name" class="flex justify-between gap-2">
                        <span class="text-muted-foreground text-xs">Name</span>
                        <span class="font-semibold text-right break-all">{{ order.customer_name }}</span>
                    </div>
                    <div v-if="order.customer_contact" class="flex justify-between gap-2">
                        <span class="text-muted-foreground text-xs">Contact</span>
                        <span class="font-semibold">{{ order.customer_contact }}</span>
                    </div>
                    <div v-if="order.customer_address" class="flex items-start justify-between gap-2">
                        <span class="text-muted-foreground text-xs flex items-center gap-0.5 shrink-0">
                            <MapPin class="h-3 w-3" /> Address
                        </span>
                        <span class="font-semibold text-right">{{ order.customer_address }}</span>
                    </div>
                </div>
            </div>

            <div v-else class="rounded-xl border bg-card shadow-sm p-4 space-y-3">
                <h3 class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">
                    <ShoppingBag class="h-3 w-3" /> Order Info
                </h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between gap-2">
                        <span class="text-muted-foreground text-xs">Type</span>
                        <span class="font-semibold">{{ order.order_type_label }}</span>
                    </div>
                    <div v-if="order.table_number" class="flex justify-between gap-2">
                        <span class="text-muted-foreground text-xs">Table</span>
                        <span class="font-semibold">{{ order.table_number }}</span>
                    </div>
                    <div class="flex justify-between gap-2">
                        <span class="text-muted-foreground text-xs">Cashier</span>
                        <span class="font-semibold">{{ order.created_by ?? '—' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Edit panel ──────────────────────────────────── -->
        <div v-if="editing" class="rounded-2xl border-2 border-primary bg-card shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b bg-primary/5 flex items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <Pencil class="h-4 w-4 text-primary" />
                    <h2 class="font-bold text-sm">Edit Order #{{ order.id }}</h2>
                </div>
                <button @click="cancelEdit" class="rounded-lg p-1.5 hover:bg-muted text-muted-foreground transition-colors">
                    <X class="h-4 w-4" />
                </button>
            </div>

            <div class="p-4 space-y-4">
                <div>
                    <label class="text-[10px] font-bold uppercase tracking-widest text-muted-foreground block mb-1.5">Notes</label>
                    <textarea v-model="editNotes" rows="2"
                        class="w-full rounded-xl border bg-background px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-primary"
                        placeholder="Order notes…" />
                </div>

                <div>
                    <label class="text-[10px] font-bold uppercase tracking-widest text-muted-foreground block mb-1.5">Date &amp; Time</label>
                    <input v-model="editCreatedAt" type="datetime-local"
                        class="rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>

                <div>
                    <label class="text-[10px] font-bold uppercase tracking-widest text-muted-foreground block mb-1.5">Discount (₱)</label>
                    <input v-model.number="editDiscount" type="number" min="0" step="0.01"
                        class="w-40 rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>

                <div>
                    <label class="text-[10px] font-bold uppercase tracking-widest text-muted-foreground block mb-2">Items</label>
                    <div class="divide-y border rounded-xl overflow-hidden">
                        <div v-for="(item, idx) in editItems" :key="item.product_id"
                            class="flex items-center gap-3 px-3 py-2.5 bg-background">
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-sm truncate">{{ item.product_name }}</p>
                                <p class="text-xs text-muted-foreground">{{ fmt(item.unit_price) }} each</p>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <button @click="changeQty(idx, -1)"
                                    class="rounded-lg border p-1 hover:bg-muted transition-colors">
                                    <Minus class="h-3.5 w-3.5" />
                                </button>
                                <span class="w-8 text-center font-bold text-sm">{{ item.quantity }}</span>
                                <button @click="changeQty(idx, 1)"
                                    class="rounded-lg border p-1 hover:bg-muted transition-colors">
                                    <Plus class="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <span class="w-20 text-right font-bold text-sm shrink-0">{{ fmt(item.unit_price * item.quantity) }}</span>
                            <button @click="removeItem(idx)" class="text-red-500 hover:text-red-700 transition-colors p-1 shrink-0">
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                        <div v-if="editItems.length === 0" class="px-3 py-4 text-sm text-muted-foreground text-center">
                            No items. Add a product below.
                        </div>
                    </div>
                </div>

                <div class="relative">
                    <label class="text-[10px] font-bold uppercase tracking-widest text-muted-foreground block mb-1.5">Add Product</label>
                    <div class="flex items-center gap-2 rounded-xl border bg-background px-3 py-2">
                        <Search class="h-4 w-4 text-muted-foreground shrink-0" />
                        <input v-model="productSearch"
                            @focus="showDropdown = true"
                            @blur="setTimeout(() => showDropdown = false, 150)"
                            class="flex-1 bg-transparent text-sm focus:outline-none"
                            placeholder="Search product name…" />
                    </div>
                    <div v-if="showDropdown && filteredProducts.length"
                        class="absolute z-20 mt-1 w-full rounded-xl border bg-popover shadow-xl max-h-56 overflow-y-auto">
                        <button v-for="p in filteredProducts" :key="p.id"
                            @mousedown.prevent="addProduct(p)"
                            class="w-full flex items-center justify-between gap-2 px-3 py-2.5 hover:bg-muted text-sm text-left transition-colors">
                            <span class="font-medium truncate">{{ p.name }}</span>
                            <span class="text-muted-foreground shrink-0 text-xs">{{ fmt(p.price) }}</span>
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t pt-3">
                    <span class="text-sm text-muted-foreground">Estimated Total</span>
                    <span class="text-xl font-black text-primary">{{ fmt(editTotal) }}</span>
                </div>

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

        <!-- ── Items card ──────────────────────────────────── -->
        <div v-else class="rounded-2xl border bg-card shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b bg-muted/30 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <Package class="h-4 w-4 text-primary" />
                    <h2 class="font-bold text-sm">Items</h2>
                    <span class="rounded-full bg-primary/10 text-primary px-2 py-0.5 text-xs font-bold">{{ order.items.length }}</span>
                </div>
            </div>

            <!-- Mobile list -->
            <div class="sm:hidden divide-y">
                <div v-for="item in order.items" :key="item.id" class="px-4 py-3.5">
                    <div class="flex items-start justify-between gap-2 mb-1">
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-sm leading-snug">{{ item.product_name }}</p>
                            <p v-if="item.category_name" class="text-xs text-muted-foreground">{{ item.category_name }}</p>
                        </div>
                        <p class="font-bold text-sm shrink-0 text-primary">{{ fmt(item.subtotal) }}</p>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-muted-foreground flex-wrap">
                        <span class="rounded-full bg-muted px-2 py-0.5 font-medium">× {{ item.quantity }}</span>
                        <span>@ {{ fmt(item.unit_price) }}</span>
                        <span v-if="item.unit_cost > 0" class="opacity-60">cost {{ fmt(item.cost_subtotal) }}</span>
                    </div>
                    <div v-if="item.modifiers.length" class="flex flex-wrap gap-1 mt-1.5">
                        <span v-for="m in item.modifiers" :key="m.name"
                            class="rounded-full bg-primary/10 text-primary px-2 py-0.5 text-xs font-medium">
                            +{{ m.name }} {{ fmt(m.price) }}
                        </span>
                    </div>
                    <p v-if="item.special_instructions" class="text-xs italic text-muted-foreground mt-1">
                        "{{ item.special_instructions }}"
                    </p>
                </div>
            </div>

            <!-- Desktop table -->
            <div class="hidden sm:block overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-muted/30 text-muted-foreground text-[10px] uppercase tracking-widest">
                            <th class="px-4 py-3 text-left font-bold">Product</th>
                            <th class="px-4 py-3 text-center font-bold">Qty</th>
                            <th class="px-4 py-3 text-right font-bold">Unit Price</th>
                            <th class="px-4 py-3 text-right font-bold">Subtotal</th>
                            <th class="px-4 py-3 text-right font-bold">Cost</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="item in order.items" :key="item.id" class="hover:bg-muted/20 transition-colors">
                            <td class="px-4 py-3">
                                <p class="font-semibold">{{ item.product_name }}</p>
                                <p v-if="item.category_name" class="text-xs text-muted-foreground">{{ item.category_name }}</p>
                                <div v-if="item.modifiers.length" class="mt-1 flex flex-wrap gap-1">
                                    <span v-for="m in item.modifiers" :key="m.name"
                                        class="rounded-full bg-primary/10 text-primary px-2 py-0.5 text-xs font-medium">
                                        +{{ m.name }} ({{ fmt(m.price) }})
                                    </span>
                                </div>
                                <p v-if="item.special_instructions" class="text-xs italic text-muted-foreground mt-0.5">
                                    "{{ item.special_instructions }}"
                                </p>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded-full bg-muted px-3 py-0.5 text-xs font-bold">× {{ item.quantity }}</span>
                            </td>
                            <td class="px-4 py-3 text-right text-muted-foreground">{{ fmt(item.unit_price) }}</td>
                            <td class="px-4 py-3 text-right font-bold text-primary">{{ fmt(item.subtotal) }}</td>
                            <td class="px-4 py-3 text-right text-muted-foreground text-xs">
                                {{ item.unit_cost > 0 ? fmt(item.cost_subtotal) : '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Totals + Payments ────────────────────────────── -->
        <div class="grid sm:grid-cols-2 gap-3">
            <!-- Totals -->
            <div class="rounded-2xl border bg-card shadow-sm p-4 space-y-3">
                <h3 class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">
                    <Receipt class="h-3 w-3" /> Totals
                </h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Subtotal</span>
                        <span class="font-medium">{{ fmt(order.subtotal) }}</span>
                    </div>
                    <div v-if="order.discount_amount > 0" class="flex justify-between text-red-500">
                        <span>Discount</span>
                        <span class="font-medium">−{{ fmt(order.discount_amount) }}</span>
                    </div>
                    <div v-if="order.tax_amount > 0" class="flex justify-between">
                        <span class="text-muted-foreground">Tax</span>
                        <span class="font-medium">{{ fmt(order.tax_amount) }}</span>
                    </div>
                    <div class="flex justify-between items-center border-t pt-2.5 mt-1">
                        <span class="font-bold">Total</span>
                        <span class="text-xl font-black text-primary">{{ fmt(order.total_amount) }}</span>
                    </div>
                    <template v-if="totalCost > 0">
                        <div class="flex justify-between text-xs text-muted-foreground border-t pt-2">
                            <span>COGS</span>
                            <span>−{{ fmt(totalCost) }}</span>
                        </div>
                        <div class="flex justify-between text-xs font-bold"
                            :class="grossProfit >= 0 ? 'text-green-600' : 'text-red-600'">
                            <span>Gross Profit</span>
                            <span>{{ fmt(grossProfit) }}</span>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Payments -->
            <div class="rounded-2xl border bg-card shadow-sm p-4 space-y-3">
                <h3 class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">
                    <CreditCard class="h-3 w-3" /> Payments
                </h3>
                <div v-if="order.payments.length === 0" class="text-sm text-muted-foreground">No payments recorded.</div>
                <div v-else class="space-y-2">
                    <div v-for="p in order.payments" :key="p.id"
                        class="flex items-center justify-between rounded-xl bg-muted/40 px-3 py-2.5 gap-2">
                        <div class="min-w-0">
                            <p class="font-semibold text-sm truncate">{{ p.tender }}</p>
                            <p v-if="p.reference" class="text-xs text-muted-foreground truncate">Ref: {{ p.reference }}</p>
                            <p class="text-xs text-muted-foreground">{{ fmtDatetime(p.created_at) }}</p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="font-black text-green-600">{{ fmt(p.amount) }}</p>
                            <span class="text-xs text-muted-foreground capitalize">{{ p.status }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div v-if="order.notes" class="rounded-2xl border bg-card shadow-sm p-4">
            <p class="text-[10px] font-bold uppercase tracking-widest text-muted-foreground mb-2">Notes</p>
            <p class="text-sm">{{ order.notes }}</p>
        </div>

        <!-- Mobile reprint -->
        <div class="sm:hidden">
            <button @click="reprintReceipt" :disabled="printing"
                class="w-full flex items-center justify-center gap-2 rounded-2xl bg-primary px-6 py-3.5 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-colors">
                <Printer class="h-4 w-4" />
                {{ printing ? 'Printing…' : 'Reprint Receipt' }}
            </button>
        </div>

    </div>

    <!-- ── Public URL modal ────────────────────────────── -->
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="showPublicUrl"
                class="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:bg-black/50 sm:p-4"
                @click.self="showPublicUrl = false">
                <div class="w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl bg-background shadow-2xl overflow-hidden">
                    <div class="p-4 border-b flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <Eye class="h-4 w-4 text-primary" />
                            <h3 class="font-bold text-sm">Share Order Link</h3>
                        </div>
                        <button @click="showPublicUrl = false" class="rounded-full p-1 hover:bg-muted transition-colors">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="p-4 space-y-4">
                        <p class="text-xs text-muted-foreground">
                            Share this link with the customer so they can track their order status.
                        </p>
                        <div class="rounded-xl border bg-muted/40 px-3 py-2.5">
                            <span class="text-xs break-all font-mono text-foreground select-all">{{ publicUrl }}</span>
                        </div>
                        <div class="flex gap-2">
                            <button @click="copyPublicUrl"
                                :class="['flex-1 flex items-center justify-center gap-2 rounded-xl py-2.5 text-sm font-bold transition-colors',
                                    urlCopied ? 'bg-green-600 text-white' : 'bg-primary text-primary-foreground hover:bg-primary/90']">
                                <Check v-if="urlCopied" class="h-4 w-4" />
                                <Copy v-else class="h-4 w-4" />
                                {{ urlCopied ? 'Copied!' : 'Copy Link' }}
                            </button>
                            <a :href="publicUrl!" target="_blank"
                                class="flex items-center justify-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold hover:bg-muted transition-colors">
                                <Eye class="h-4 w-4" /> Open
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
/* ── Status hero gradients ──────────────────────────────────────────── */
.hero-card { color: var(--hero-fg); }

.status-pending   { --hero-fg: #78350f; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); }
.status-preparing { --hero-fg: #1e40af; background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); }
.status-ready     { --hero-fg: #4c1d95; background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%); }
.status-completed { --hero-fg: #14532d; background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); }
.status-cancelled { --hero-fg: #7f1d1d; background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); }

@media (prefers-color-scheme: dark) {
    .status-pending   { --hero-fg: #fef3c7; background: linear-gradient(135deg, #78350f80 0%, #92400e60 100%); }
    .status-preparing { --hero-fg: #dbeafe;  background: linear-gradient(135deg, #1e3a8a80 0%, #1e40af60 100%); }
    .status-ready     { --hero-fg: #ede9fe;  background: linear-gradient(135deg, #4c1d9580 0%, #5b21b660 100%); }
    .status-completed { --hero-fg: #dcfce7;  background: linear-gradient(135deg, #14532d80 0%, #16653060 100%); }
    .status-cancelled { --hero-fg: #fee2e2;  background: linear-gradient(135deg, #7f1d1d80 0%, #99181860 100%); }
}

/* Force dark mode via data-theme="dark" too */
:root[data-theme="dark"] .status-pending   { --hero-fg: #fef3c7; background: linear-gradient(135deg, #78350f80 0%, #92400e60 100%); }
:root[data-theme="dark"] .status-preparing { --hero-fg: #dbeafe;  background: linear-gradient(135deg, #1e3a8a80 0%, #1e40af60 100%); }
:root[data-theme="dark"] .status-ready     { --hero-fg: #ede9fe;  background: linear-gradient(135deg, #4c1d9580 0%, #5b21b660 100%); }
:root[data-theme="dark"] .status-completed { --hero-fg: #dcfce7;  background: linear-gradient(135deg, #14532d80 0%, #16653060 100%); }
:root[data-theme="dark"] .status-cancelled { --hero-fg: #fee2e2;  background: linear-gradient(135deg, #7f1d1d80 0%, #99181860 100%); }

/* ── Payment badges ─────────────────────────────────────────────────── */
.pay-badge { color: var(--pay-fg); background: var(--pay-bg); }
.pay-paid     { --pay-bg: #dcfce7; --pay-fg: #14532d; }
.pay-pending  { --pay-bg: #fef9c3; --pay-fg: #713f12; }
.pay-refunded { --pay-bg: #ede9fe; --pay-fg: #4c1d95; }
.pay-voided   { --pay-bg: #fee2e2; --pay-fg: #7f1d1d; }

@media (prefers-color-scheme: dark) {
    .pay-paid     { --pay-bg: #14532d60; --pay-fg: #bbf7d0; }
    .pay-pending  { --pay-bg: #71350f60; --pay-fg: #fef3c7; }
    .pay-refunded { --pay-bg: #4c1d9560; --pay-fg: #ddd6fe; }
    .pay-voided   { --pay-bg: #7f1d1d60; --pay-fg: #fecaca; }
}
:root[data-theme="dark"] .pay-paid     { --pay-bg: #14532d60; --pay-fg: #bbf7d0; }
:root[data-theme="dark"] .pay-pending  { --pay-bg: #71350f60; --pay-fg: #fef3c7; }
:root[data-theme="dark"] .pay-refunded { --pay-bg: #4c1d9560; --pay-fg: #ddd6fe; }
:root[data-theme="dark"] .pay-voided   { --pay-bg: #7f1d1d60; --pay-fg: #fecaca; }

/* ── Modal fade transition ──────────────────────────────────────────── */
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
