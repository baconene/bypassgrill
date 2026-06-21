<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { ShoppingBag, User, MapPin, Clock, CreditCard, Package, Receipt } from 'lucide-vue-next'

defineOptions({ layout: null })

const page = usePage()
const brandName = computed(() => (page.props as any).brandName || (page.props as any).name || 'Restaurant')

interface OrderItem { name: string; quantity: number; unit_price: number; subtotal: number }
interface OrderPayment { method: string; amount: number; change: number; status: string }
interface Order {
    id: number; queue_number: number | null; order_type: string; status: string
    payment_status: string; table_number: string | null
    customer_name: string | null; customer_contact: string | null; customer_address: string | null
    notes: string | null; cashier: string | null; created_at: string
    subtotal: number; discount_amount: number; tax_amount: number; total_amount: number
    items: OrderItem[]; payment: OrderPayment | null
}

const props = defineProps<{ order: Order }>()

const fmt = (v: number) => '₱' + v.toLocaleString('en-PH', { minimumFractionDigits: 2 })

const orderTypeLabel = (t: string) => ({ dine_in: 'Dine In', takeout: 'Takeout', delivery: 'Delivery' }[t] ?? t)

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
</script>

<template>
    <Head :title="`Order #${order.id} — ${brandName}`" />

    <div class="min-h-screen bg-background flex justify-center px-3 py-6 sm:py-10">
        <div class="w-full max-w-2xl space-y-4">

            <div class="text-center mb-2">
                <h1 class="text-2xl font-black tracking-tight">{{ brandName.toUpperCase() }}</h1>
            </div>

            <div class="rounded-xl border bg-card shadow-sm p-4">
                <div class="flex items-center gap-2 flex-wrap">
                    <h2 class="text-lg sm:text-xl font-black flex items-center gap-1.5">
                        <ShoppingBag class="h-5 w-5 text-primary shrink-0" />
                        Order #{{ order.id }}
                    </h2>
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
                <p class="text-xs text-muted-foreground mt-1">
                    {{ orderTypeLabel(order.order_type) }}
                    <template v-if="order.table_number"> · Table {{ order.table_number }}</template>
                </p>
            </div>

            <div class="grid sm:grid-cols-2 gap-3 sm:gap-4">
                <div class="rounded-xl border bg-card shadow-sm p-4 space-y-3">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                        <Clock class="h-3.5 w-3.5" /> Order Info
                    </h3>
                    <div class="space-y-1.5 text-sm">
                        <div class="flex justify-between gap-4">
                            <span class="text-muted-foreground shrink-0">Date</span>
                            <span class="font-medium text-right">{{ order.created_at }}</span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-muted-foreground shrink-0">Type</span>
                            <span class="font-medium text-right">{{ orderTypeLabel(order.order_type) }}</span>
                        </div>
                        <div v-if="order.table_number" class="flex justify-between gap-4">
                            <span class="text-muted-foreground shrink-0">Table</span>
                            <span class="font-medium text-right">{{ order.table_number }}</span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-muted-foreground shrink-0">Cashier</span>
                            <span class="font-medium text-right">{{ order.cashier ?? '—' }}</span>
                        </div>
                    </div>
                </div>

                <div v-if="order.customer_name || order.customer_contact || order.customer_address"
                    class="rounded-xl border bg-card shadow-sm p-4 space-y-3">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                        <User class="h-3.5 w-3.5" /> Customer
                    </h3>
                    <div class="space-y-1.5 text-sm">
                        <div v-if="order.customer_name" class="flex justify-between">
                            <span class="text-muted-foreground">Name</span>
                            <span class="font-medium">{{ order.customer_name }}</span>
                        </div>
                        <div v-if="order.customer_contact" class="flex justify-between">
                            <span class="text-muted-foreground">Contact</span>
                            <span class="font-medium">{{ order.customer_contact }}</span>
                        </div>
                        <div v-if="order.customer_address" class="flex items-start justify-between gap-4">
                            <span class="text-muted-foreground flex items-center gap-1 shrink-0">
                                <MapPin class="h-3 w-3" /> Address
                            </span>
                            <span class="font-medium text-right">{{ order.customer_address }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="p-4 border-b flex items-center gap-2">
                    <Package class="h-4 w-4 text-muted-foreground" />
                    <h2 class="font-bold text-sm">Items ({{ order.items.length }})</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm min-w-[400px]">
                        <thead class="bg-muted/50 text-muted-foreground text-xs uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left">Product</th>
                                <th class="px-4 py-3 text-center">Qty</th>
                                <th class="px-4 py-3 text-right">Unit Price</th>
                                <th class="px-4 py-3 text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="(item, idx) in order.items" :key="idx" class="hover:bg-muted/20">
                                <td class="px-4 py-3 font-semibold">{{ item.name }}</td>
                                <td class="px-4 py-3 text-center font-bold">× {{ item.quantity }}</td>
                                <td class="px-4 py-3 text-right">{{ fmt(item.unit_price) }}</td>
                                <td class="px-4 py-3 text-right font-bold">{{ fmt(item.subtotal) }}</td>
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
                    </div>
                </div>

                <div class="rounded-xl border bg-card shadow-sm p-4 space-y-3">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                        <CreditCard class="h-3.5 w-3.5" /> Payment
                    </h3>
                    <div v-if="order.payment" class="space-y-1.5 text-sm">
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">Method</span>
                            <span class="font-medium">{{ order.payment.method }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">Amount Paid</span>
                            <span class="font-medium">{{ fmt(order.payment.amount) }}</span>
                        </div>
                        <div v-if="order.payment.change > 0" class="flex justify-between font-bold text-green-600">
                            <span>Change</span>
                            <span>{{ fmt(order.payment.change) }}</span>
                        </div>
                    </div>
                    <div v-else class="text-sm font-semibold text-yellow-600">Payment Pending</div>
                </div>
            </div>

            <div v-if="order.notes" class="rounded-xl border bg-card shadow-sm p-4">
                <p class="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2">Notes</p>
                <p class="text-sm">{{ order.notes }}</p>
            </div>

            <p class="text-center text-xs text-muted-foreground pt-2 pb-4">
                Thank you for dining with us ♥ · {{ brandName }}
            </p>
        </div>
    </div>
</template>
