<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { ShoppingBag, User, MapPin, Clock, CreditCard, Package, Receipt, Smartphone, QrCode, X } from 'lucide-vue-next'

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

const props = defineProps<{ order: Order; gcashQrUrl: string | null }>()

const qrModalOpen = ref(false)

const fmt = (v: number) => '₱' + v.toLocaleString('en-PH', { minimumFractionDigits: 2 })

const orderTypeLabel = (t: string) => ({ dine_in: 'Dine In', takeout: 'Takeout', delivery: 'Delivery' }[t] ?? t)

const statusTextColor = (s: string) => ({
    pending:   'text-yellow-600 dark:text-yellow-400',
    preparing: 'text-blue-600 dark:text-blue-400',
    ready:     'text-purple-600 dark:text-purple-400',
    completed: 'text-green-600 dark:text-green-400',
    cancelled: 'text-red-600 dark:text-red-400',
}[s] ?? 'text-foreground')

const statusBg = (s: string) => ({
    pending:   'bg-yellow-50 dark:bg-yellow-950/30 border-yellow-200 dark:border-yellow-800',
    preparing: 'bg-blue-50 dark:bg-blue-950/30 border-blue-200 dark:border-blue-800',
    ready:     'bg-purple-50 dark:bg-purple-950/30 border-purple-200 dark:border-purple-800',
    completed: 'bg-green-50 dark:bg-green-950/30 border-green-200 dark:border-green-800',
    cancelled: 'bg-red-50 dark:bg-red-950/30 border-red-200 dark:border-red-800',
}[s] ?? 'bg-muted border-border')
</script>

<template>
    <Head :title="`Order #${order.id} — ${brandName}`" />

    <div class="min-h-screen bg-background flex justify-center px-3 py-6 sm:py-10">
        <div class="w-full max-w-2xl space-y-4">

            <!-- Brand -->
            <div class="text-center mb-2">
                <h1 class="text-2xl font-black tracking-tight">{{ brandName.toUpperCase() }}</h1>
            </div>

            <!-- Order header + status hero -->
            <div class="rounded-xl border bg-card shadow-sm p-4">
                <div class="flex items-center justify-between gap-2 mb-4">
                    <h2 class="text-lg sm:text-xl font-black flex items-center gap-1.5">
                        <ShoppingBag class="h-5 w-5 text-primary shrink-0" />
                        Order #{{ order.id }}
                    </h2>
                    <span v-if="order.queue_number" class="rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold">
                        Q{{ order.queue_number }}
                    </span>
                </div>

                <!-- Centered status display -->
                <div :class="['rounded-xl border py-5 text-center mb-4', statusBg(order.status)]">
                    <p :class="['text-4xl font-black uppercase tracking-widest', statusTextColor(order.status)]">
                        {{ order.status }}
                    </p>
                    <p class="text-xs font-bold uppercase tracking-widest text-muted-foreground mt-1.5">
                        ORDER STATUS
                    </p>
                </div>

                <p class="text-xs text-muted-foreground text-center">
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

            <!-- Items -->
            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="p-4 border-b flex items-center gap-2">
                    <Package class="h-4 w-4 text-muted-foreground" />
                    <h2 class="font-bold text-sm">Items ({{ order.items.length }})</h2>
                </div>
                <div class="divide-y">
                    <div v-for="(item, idx) in order.items" :key="idx"
                        class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold truncate">{{ item.name }}</p>
                            <p class="text-xs text-muted-foreground mt-0.5">
                                × {{ item.quantity }} &nbsp;·&nbsp; {{ fmt(item.unit_price) }} each
                            </p>
                        </div>
                        <span class="font-bold text-sm shrink-0">{{ fmt(item.subtotal) }}</span>
                    </div>
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

            <!-- GCash QR section — visible whenever a QR is configured -->
            <div
                v-if="gcashQrUrl"
                class="rounded-xl border-2 border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/30 overflow-hidden"
            >
                <div class="px-4 pt-4 pb-3 border-b border-blue-200 dark:border-blue-800 flex items-center gap-2">
                    <Smartphone class="h-4 w-4 text-blue-600 dark:text-blue-400 shrink-0" />
                    <h3 class="font-bold text-sm text-blue-700 dark:text-blue-300">Pay with GCash</h3>
                    <span v-if="order.payment_status === 'pending'" class="ml-auto rounded-full bg-yellow-400 px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wide text-yellow-900">
                        Payment Pending
                    </span>
                </div>

                <div class="p-5 flex flex-col sm:flex-row items-center gap-6">
                    <!-- QR Code -->
                    <div class="flex flex-col items-center gap-2 shrink-0">
                        <button @click="qrModalOpen = true" class="rounded-xl border-2 border-blue-200 dark:border-blue-700 bg-white p-3 shadow-sm block hover:opacity-80 transition-opacity cursor-zoom-in">
                            <img
                                :src="gcashQrUrl"
                                alt="GCash QR Code"
                                class="h-48 w-48 object-contain"
                            />
                        </button>
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-blue-500 dark:text-blue-400 flex items-center gap-1">
                            <QrCode class="h-3 w-3" /> Scan to pay
                        </p>
                    </div>

                    <!-- Instructions -->
                    <div class="space-y-3 text-sm text-center sm:text-left">
                        <p class="font-black text-2xl text-blue-700 dark:text-blue-300">
                            {{ fmt(order.total_amount) }}
                        </p>
                        <p class="text-muted-foreground leading-relaxed">
                            Open your <strong class="text-foreground">GCash app</strong>, tap
                            <strong class="text-foreground">Pay QR</strong>, and point your camera
                            at the code to send the exact amount above.
                        </p>
                        <ol class="space-y-1.5 text-muted-foreground text-left">
                            <li class="flex items-start gap-2">
                                <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-blue-200 dark:bg-blue-800 text-[10px] font-black text-blue-700 dark:text-blue-300">1</span>
                                Open GCash and tap <strong class="text-foreground">Pay QR</strong>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-blue-200 dark:bg-blue-800 text-[10px] font-black text-blue-700 dark:text-blue-300">2</span>
                                Scan the QR code shown here
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-blue-200 dark:bg-blue-800 text-[10px] font-black text-blue-700 dark:text-blue-300">3</span>
                                Enter <strong class="text-foreground">{{ fmt(order.total_amount) }}</strong> and confirm
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-blue-200 dark:bg-blue-800 text-[10px] font-black text-blue-700 dark:text-blue-300">4</span>
                                Show the cashier your GCash confirmation
                            </li>
                        </ol>
                    </div>
                </div>
            </div>

            <p class="text-center text-xs text-muted-foreground pt-2 pb-4">
                Thank you for dining with us ♥ · {{ brandName }}
            </p>
        </div>
    </div>

    <!-- QR fullscreen modal -->
    <Teleport to="body">
        <Transition name="fade">
            <div
                v-if="qrModalOpen"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
                @click="qrModalOpen = false"
            >
                <button
                    class="absolute top-4 right-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20 transition-colors"
                    @click="qrModalOpen = false"
                >
                    <X class="h-5 w-5" />
                </button>
                <img
                    :src="gcashQrUrl!"
                    alt="GCash QR Code"
                    class="max-h-[90vmin] max-w-[90vmin] rounded-2xl bg-white p-4 shadow-2xl object-contain"
                    @click.stop
                />
            </div>
        </Transition>
    </Teleport>
</template>
