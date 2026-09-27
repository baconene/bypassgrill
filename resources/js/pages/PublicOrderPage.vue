<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { X, QrCode } from 'lucide-vue-next'

defineOptions({ layout: null })

const page = usePage()
const brandName = computed(() => (page.props as any).brandName || 'Bypass Grill')
const logoUrl   = computed(() => (page.props as any).logoUrl ?? null)

interface OrderItem    { name: string; quantity: number; unit_price: number; subtotal: number }
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

const orderTypeLabel = (t: string) =>
    ({ dine_in: 'Dine In', takeout: 'Takeout', delivery: 'Delivery' }[t] ?? t)

const statusConfig: Record<string, { label: string; bg: string; color: string; dot: string }> = {
    pending:   { label: 'Pending',   bg: '#fff7ed', color: '#c2410c', dot: '#ef5b2a' },
    preparing: { label: 'Preparing', bg: '#fef3c7', color: '#92400e', dot: '#f59e0b' },
    ready:     { label: 'Ready',     bg: '#f0fdf4', color: '#166534', dot: '#22c55e' },
    completed: { label: 'Completed', bg: '#f0fdf4', color: '#166534', dot: '#22c55e' },
    cancelled: { label: 'Cancelled', bg: '#fef2f2', color: '#991b1b', dot: '#ef4444' },
}
const sc = computed(() => statusConfig[props.order.status] ?? { label: props.order.status, bg: '#f6f2e9', color: '#24231e', dot: '#24231e' })
const isPulse = computed(() => ['pending', 'preparing'].includes(props.order.status))
</script>

<template>
    <Head :title="`Order #${order.id} · ${brandName}`" />

    <div class="page-root">

        <!-- ── Header ─────────────────────────────────────────────────── -->
        <header class="site-header">
            <div class="brand-wrap">
                <img v-if="logoUrl" :src="logoUrl" alt="logo" class="brand-logo" />
                <span class="brand-name">{{ brandName.toUpperCase() }}</span>
            </div>
            <span v-if="order.queue_number" class="queue-badge">Q{{ order.queue_number }}</span>
        </header>

        <!-- ── Status hero ────────────────────────────────────────────── -->
        <div class="status-hero" :style="{ background: sc.bg }">
            <div class="status-dot-row">
                <span class="status-dot" :class="{ pulse: isPulse }" :style="{ background: sc.dot }"></span>
                <span class="status-eyebrow">ORDER STATUS</span>
            </div>
            <p class="status-label" :style="{ color: sc.color }">{{ sc.label }}</p>
            <p class="status-sub">
                {{ orderTypeLabel(order.order_type) }}
                <template v-if="order.table_number"> · Table {{ order.table_number }}</template>
                <template v-if="order.cashier"> · {{ order.cashier }}</template>
            </p>
        </div>

        <!-- ── Body ──────────────────────────────────────────────────── -->
        <div class="body-wrap">

            <!-- Order meta -->
            <div class="card">
                <p class="card-label">ORDER DETAILS</p>
                <div class="meta-row">
                    <span class="meta-key">Order #</span>
                    <span class="meta-val">{{ order.id }}</span>
                </div>
                <div class="meta-row">
                    <span class="meta-key">Date</span>
                    <span class="meta-val">{{ order.created_at }}</span>
                </div>
                <div class="meta-row">
                    <span class="meta-key">Type</span>
                    <span class="meta-val">{{ orderTypeLabel(order.order_type) }}</span>
                </div>
                <div v-if="order.table_number" class="meta-row">
                    <span class="meta-key">Table</span>
                    <span class="meta-val">{{ order.table_number }}</span>
                </div>
            </div>

            <!-- Customer -->
            <div v-if="order.customer_name || order.customer_contact || order.customer_address" class="card">
                <p class="card-label">CUSTOMER</p>
                <div v-if="order.customer_name" class="meta-row">
                    <span class="meta-key">Name</span>
                    <span class="meta-val">{{ order.customer_name }}</span>
                </div>
                <div v-if="order.customer_contact" class="meta-row">
                    <span class="meta-key">Contact</span>
                    <span class="meta-val">{{ order.customer_contact }}</span>
                </div>
                <div v-if="order.customer_address" class="meta-row">
                    <span class="meta-key">Address</span>
                    <span class="meta-val">{{ order.customer_address }}</span>
                </div>
            </div>

            <!-- Items -->
            <div class="card">
                <p class="card-label">ITEMS ({{ order.items.length }})</p>
                <div v-for="(item, idx) in order.items" :key="idx" class="item-row">
                    <div class="item-left">
                        <span class="item-qty">×{{ item.quantity }}</span>
                        <span class="item-name">{{ item.name }}</span>
                    </div>
                    <span class="item-price">{{ fmt(item.subtotal) }}</span>
                </div>
                <div class="receipt-divider"></div>
                <div v-if="order.discount_amount > 0" class="meta-row discount-row">
                    <span class="meta-key">Discount</span>
                    <span class="meta-val discount-val">−{{ fmt(order.discount_amount) }}</span>
                </div>
                <div v-if="order.tax_amount > 0" class="meta-row">
                    <span class="meta-key">Tax</span>
                    <span class="meta-val">{{ fmt(order.tax_amount) }}</span>
                </div>
                <div class="total-row">
                    <span class="total-label">TOTAL</span>
                    <span class="total-amount">{{ fmt(order.total_amount) }}</span>
                </div>
            </div>

            <!-- Payment -->
            <div class="card">
                <p class="card-label">PAYMENT</p>
                <template v-if="order.payment">
                    <div class="meta-row">
                        <span class="meta-key">Method</span>
                        <span class="meta-val">{{ order.payment.method }}</span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-key">Amount Paid</span>
                        <span class="meta-val">{{ fmt(order.payment.amount) }}</span>
                    </div>
                    <div v-if="order.payment.change > 0" class="meta-row">
                        <span class="meta-key">Change</span>
                        <span class="meta-val change-val">{{ fmt(order.payment.change) }}</span>
                    </div>
                    <div class="paid-badge">✓ PAID</div>
                </template>
                <div v-else class="pending-payment">
                    <span class="pending-dot"></span> Payment Pending
                </div>
            </div>

            <!-- Notes -->
            <div v-if="order.notes" class="card">
                <p class="card-label">NOTES</p>
                <p class="notes-text">{{ order.notes }}</p>
            </div>

            <!-- GCash -->
            <div v-if="gcashQrUrl" class="gcash-card">
                <div class="gcash-header">
                    <QrCode class="gcash-icon" />
                    <span class="gcash-title">Pay with GCash</span>
                    <span v-if="order.payment_status === 'pending'" class="pending-badge">PENDING</span>
                </div>
                <div class="gcash-body">
                    <button class="qr-wrap" @click="qrModalOpen = true" aria-label="Tap to enlarge QR">
                        <img :src="gcashQrUrl" alt="GCash QR Code" class="qr-img" />
                        <span class="qr-hint">Tap to enlarge</span>
                    </button>
                    <div class="gcash-instructions">
                        <p class="gcash-amount">{{ fmt(order.total_amount) }}</p>
                        <ol class="steps">
                            <li><span class="step-num">1</span> Open GCash → <strong>Pay QR</strong></li>
                            <li><span class="step-num">2</span> Scan the QR code</li>
                            <li><span class="step-num">3</span> Enter <strong>{{ fmt(order.total_amount) }}</strong> and confirm</li>
                            <li><span class="step-num">4</span> Show cashier your confirmation</li>
                        </ol>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Footer ─────────────────────────────────────────────────── -->
        <footer class="site-footer">
            <p class="footer-brand">{{ brandName.toUpperCase() }}</p>
            <p class="footer-tagline">GOOD FOOD. GOOD MOOD.</p>
            <p class="footer-thanks">Thank you for dining with us ♥</p>
        </footer>
    </div>

    <!-- QR fullscreen modal -->
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="qrModalOpen" class="qr-modal" @click="qrModalOpen = false">
                <button class="qr-close" @click="qrModalOpen = false" aria-label="Close">
                    <X class="h-5 w-5" />
                </button>
                <img :src="gcashQrUrl!" alt="GCash QR Code" class="qr-modal-img" @click.stop />
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
/* ── Tokens ─────────────────────────────────────────────────────────── */
:root {
    --ink:    #24231e;
    --cream:  #f6f2e9;
    --orange: #ef5b2a;
    --border: #24231e22;
    --card-bg: #ffffff;
    --label:  #8a8072;
}

/* ── Layout ──────────────────────────────────────────────────────────── */
.page-root {
    min-height: 100svh;
    background: var(--cream);
    color: var(--ink);
    font-family: Arial, Helvetica, sans-serif;
    display: flex;
    flex-direction: column;
}

/* ── Header ──────────────────────────────────────────────────────────── */
.site-header {
    background: var(--ink);
    color: var(--cream);
    padding: 18px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.brand-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
}
.brand-logo {
    width: 36px;
    height: 36px;
    object-fit: contain;
    border-radius: 6px;
}
.brand-name {
    font-family: Impact, 'Arial Narrow', sans-serif;
    font-size: 20px;
    letter-spacing: 1px;
    color: var(--cream);
}
.queue-badge {
    background: var(--orange);
    color: #fff;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 1px;
    padding: 4px 10px;
    border-radius: 999px;
}

/* ── Status hero ─────────────────────────────────────────────────────── */
.status-hero {
    padding: 28px 20px 24px;
    text-align: center;
    border-bottom: 1px solid var(--border);
}
.status-dot-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    margin-bottom: 10px;
}
.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}
.status-dot.pulse {
    animation: pulse 1.6s ease-in-out infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: 0.45; transform: scale(1.35); }
}
.status-eyebrow {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 2px;
    color: var(--label);
}
.status-label {
    font-family: Impact, 'Arial Narrow', sans-serif;
    font-size: 42px;
    letter-spacing: 2px;
    text-transform: uppercase;
    margin: 0 0 6px;
    line-height: 1;
}
.status-sub {
    font-size: 12px;
    color: var(--label);
    letter-spacing: 0.5px;
}

/* ── Body ────────────────────────────────────────────────────────────── */
.body-wrap {
    flex: 1;
    padding: 16px;
    max-width: 540px;
    width: 100%;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

/* ── Card ────────────────────────────────────────────────────────────── */
.card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 16px;
}
.card-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 2px;
    color: var(--label);
    margin: 0 0 12px;
}

/* ── Meta rows ───────────────────────────────────────────────────────── */
.meta-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    padding: 5px 0;
    font-size: 14px;
    border-bottom: 1px solid #f0ece4;
}
.meta-row:last-child { border-bottom: none; }
.meta-key { color: var(--label); flex-shrink: 0; }
.meta-val  { font-weight: 600; text-align: right; }

/* ── Item rows ───────────────────────────────────────────────────────── */
.item-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 7px 0;
    border-bottom: 1px dashed #e8e3d8;
    font-size: 14px;
}
.item-left {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}
.item-qty {
    font-weight: 900;
    color: var(--orange);
    font-size: 13px;
    flex-shrink: 0;
}
.item-name  { font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.item-price { font-weight: 700; flex-shrink: 0; }

.receipt-divider {
    border: none;
    border-top: 2px dashed #d6d0c4;
    margin: 10px 0 8px;
}
.discount-row .meta-key { color: #b91c1c; }
.discount-val            { color: #b91c1c; }

.total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 8px;
    padding-top: 10px;
    border-top: 2px solid var(--ink);
}
.total-label  { font-size: 11px; font-weight: 900; letter-spacing: 2px; }
.total-amount { font-family: Impact, 'Arial Narrow', sans-serif; font-size: 26px; letter-spacing: 1px; }

/* ── Payment ─────────────────────────────────────────────────────────── */
.change-val { color: #166534; font-weight: 700; }
.paid-badge {
    margin-top: 12px;
    display: inline-block;
    background: #dcfce7;
    color: #166534;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 2px;
    padding: 5px 14px;
    border-radius: 999px;
    border: 1px solid #bbf7d0;
}
.pending-payment {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    color: #c2410c;
    font-size: 14px;
}
.pending-dot {
    width: 8px; height: 8px;
    background: var(--orange);
    border-radius: 50%;
    display: inline-block;
    animation: pulse 1.4s ease-in-out infinite;
}

/* ── Notes ───────────────────────────────────────────────────────────── */
.notes-text { font-size: 14px; line-height: 1.6; color: var(--ink); margin: 0; }

/* ── GCash ───────────────────────────────────────────────────────────── */
.gcash-card {
    background: var(--card-bg);
    border: 2px solid var(--orange);
    border-radius: 14px;
    overflow: hidden;
}
.gcash-header {
    background: var(--orange);
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #fff;
}
.gcash-icon  { width: 18px; height: 18px; flex-shrink: 0; }
.gcash-title { font-weight: 900; font-size: 14px; letter-spacing: 0.5px; flex: 1; }
.pending-badge {
    background: #fff;
    color: var(--orange);
    font-size: 9px;
    font-weight: 900;
    letter-spacing: 1.5px;
    padding: 3px 8px;
    border-radius: 999px;
}
.gcash-body {
    padding: 20px 16px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
}
@media (min-width: 480px) {
    .gcash-body { flex-direction: row; align-items: flex-start; }
}
.qr-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    background: none;
    border: none;
    cursor: zoom-in;
    padding: 0;
    flex-shrink: 0;
}
.qr-img  { width: 160px; height: 160px; object-fit: contain; border-radius: 10px; border: 1px solid #f0ece4; background: #fff; padding: 6px; }
.qr-hint { font-size: 10px; font-weight: 600; letter-spacing: 1px; color: var(--label); text-transform: uppercase; }
.gcash-instructions { flex: 1; }
.gcash-amount {
    font-family: Impact, 'Arial Narrow', sans-serif;
    font-size: 32px;
    color: var(--orange);
    letter-spacing: 1px;
    margin: 0 0 14px;
}
.steps {
    list-style: none;
    padding: 0; margin: 0;
    display: flex; flex-direction: column; gap: 10px;
}
.steps li {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--ink);
    line-height: 1.4;
}
.step-num {
    min-width: 20px; height: 20px;
    background: var(--orange);
    color: #fff;
    border-radius: 50%;
    font-size: 10px;
    font-weight: 900;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

/* ── Footer ──────────────────────────────────────────────────────────── */
.site-footer {
    background: var(--ink);
    color: var(--cream);
    text-align: center;
    padding: 28px 20px;
    margin-top: 8px;
}
.footer-brand    { font-family: Impact, 'Arial Narrow', sans-serif; font-size: 22px; letter-spacing: 2px; margin: 0 0 4px; }
.footer-tagline  { font-size: 9px; font-weight: 700; letter-spacing: 3px; color: #8a8072; margin: 0 0 12px; }
.footer-thanks   { font-size: 12px; color: #8a8072; margin: 0; }

/* ── QR Modal ────────────────────────────────────────────────────────── */
.qr-modal {
    position: fixed; inset: 0; z-index: 50;
    background: rgba(0,0,0,.85);
    display: flex; align-items: center; justify-content: center;
    padding: 16px;
}
.qr-close {
    position: absolute; top: 16px; right: 16px;
    background: rgba(255,255,255,.15);
    border: none; border-radius: 999px;
    padding: 8px; color: #fff; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
}
.qr-modal-img {
    max-width: min(90vw, 380px);
    max-height: 90vh;
    border-radius: 16px;
    background: #fff;
    padding: 16px;
    object-fit: contain;
}

/* ── Transition ──────────────────────────────────────────────────────── */
.fade-enter-active, .fade-leave-active { transition: opacity 0.15s; }
.fade-enter-from,  .fade-leave-to      { opacity: 0; }
</style>
