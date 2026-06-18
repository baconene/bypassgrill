<script setup lang="ts">
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { ArrowLeft, ShoppingBag, User, MapPin, Clock, CreditCard, Package, Receipt, Printer } from 'lucide-vue-next'
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
    id: number; product_name: string; category_name: string | null
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
    items: OrderItem[]; payments: Payment[]
}

const props = defineProps<{ order: Order }>()

const printing = ref(false)

const backUrl = new URLSearchParams(window.location.search).get('back')
const goBack = () => backUrl ? router.visit(backUrl) : window.history.back()

const fmt = (v: number) => '₱' + v.toLocaleString('en-PH', { minimumFractionDigits: 2 })

const fmtDatetime = (s: string | null) => {
    if (!s) return '—'
    return new Date(s).toLocaleString('en-PH', {
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

const totalCost   = props.order.items.reduce((s, i) => s + i.cost_subtotal, 0)
const grossProfit = props.order.total_amount - totalCost

// ── Reprint ──────────────────────────────────────────────────────────────────
const reprintReceipt = async () => {
    printing.value = true
    try {
        // Send to Android printing service via Pusher Channels
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
            <button @click="reprintReceipt" :disabled="printing"
                class="hidden sm:flex items-center gap-2 rounded-lg border bg-card px-4 py-2 text-sm font-semibold hover:bg-muted disabled:opacity-50 shrink-0 transition-colors">
                <Printer class="h-4 w-4" />
                {{ printing ? 'Printing…' : 'Reprint Receipt' }}
            </button>
            <button @click="reprintReceipt" :disabled="printing"
                class="sm:hidden rounded-lg border bg-card p-2 hover:bg-muted disabled:opacity-50 shrink-0 transition-colors mt-0.5"
                title="Reprint Receipt">
                <Printer class="h-4 w-4" />
            </button>
        </div>

        <!-- Timeline + Customer -->
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

            <!-- Fill second column if no customer card -->
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

        <!-- Order Items -->
        <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
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

        <!-- Totals + Payments -->
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

        <!-- Notes -->
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
</template>
