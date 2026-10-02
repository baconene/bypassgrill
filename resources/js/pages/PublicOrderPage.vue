<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { X, QrCode, Flame } from 'lucide-vue-next'

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

const statusMeta: Record<string, { label: string; chipCls: string; pulse: boolean }> = {
    pending:   { label: 'Pending',   chipCls: 'chip-pending',   pulse: true  },
    preparing: { label: 'Preparing', chipCls: 'chip-preparing', pulse: true  },
    ready:     { label: 'Ready',     chipCls: 'chip-ready',     pulse: false },
    completed: { label: 'Completed', chipCls: 'chip-completed', pulse: false },
    cancelled: { label: 'Cancelled', chipCls: 'chip-cancelled', pulse: false },
}
const sm = computed(() => statusMeta[props.order.status] ?? { label: props.order.status, chipCls: '', pulse: false })
</script>

<template>
    <Head :title="`Order #${order.id} · ${brandName}`" />

    <div class="pub-page">

        <!-- ── Heading ── -->
        <header class="pub-heading">
            <div class="brand-row">
                <img v-if="logoUrl" :src="logoUrl" alt="logo" class="brand-logo" />
                <div>
                    <p class="eyebrow"><Flame :size="12" /> {{ brandName.toUpperCase() }}</p>
                    <h1>Your order <em>status.</em></h1>
                </div>
            </div>
            <div class="heading-meta">
                <span>Order #{{ order.id }}</span>
                <span v-if="order.queue_number" class="queue-tag">Queue {{ order.queue_number }}</span>
            </div>
        </header>

        <!-- ── Status work-bar ── -->
        <section class="status-bar">
            <div class="status-bar-left">
                <span class="status-chip" :class="sm.chipCls">
                    <span class="chip-dot" :class="{ 'dot-pulse': sm.pulse }" />
                    {{ sm.label }}
                </span>
                <span class="pay-chip" :class="order.payment_status === 'paid' ? 'pay-paid' : 'pay-unpaid'">
                    {{ order.payment_status === 'paid' ? 'Paid' : 'Payment Pending' }}
                </span>
            </div>
            <div class="status-bar-right">
                {{ orderTypeLabel(order.order_type) }}
                <template v-if="order.table_number"> · Table {{ order.table_number }}</template>
            </div>
        </section>

        <!-- ── Metric strip ── -->
        <section class="metric-strip">
            <article class="metric metric-featured">
                <p>Order Total</p>
                <strong>{{ fmt(order.total_amount) }}</strong>
                <span>{{ order.items.length }} item{{ order.items.length !== 1 ? 's' : '' }}</span>
            </article>
            <article class="metric">
                <p>Status</p>
                <strong>{{ sm.label }}</strong>
                <span>{{ orderTypeLabel(order.order_type) }}</span>
            </article>
            <article class="metric">
                <p>Placed</p>
                <strong>{{ order.created_at }}</strong>
                <span>{{ order.cashier ? 'by ' + order.cashier : 'Walk-in' }}</span>
            </article>
        </section>

        <!-- ── Body panels ── -->
        <div class="body-col">

            <!-- Items -->
            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">ORDER ITEMS</p>
                        <h2>Items <span class="count-badge">{{ order.items.reduce((s,i)=>s+i.quantity,0) }} qty</span></h2>
                    </div>
                </div>

                <div v-for="(item, idx) in order.items" :key="idx" class="item-row">
                    <div class="item-left">
                        <span class="item-qty">×{{ item.quantity }}</span>
                        <span class="item-name">{{ item.name }}</span>
                    </div>
                    <span class="item-price">{{ fmt(item.subtotal) }}</span>
                </div>

                <div class="receipt-divider" />

                <dl class="totals-list">
                    <div v-if="order.discount_amount > 0" class="totals-row row-discount">
                        <dt>Discount</dt>
                        <dd>−{{ fmt(order.discount_amount) }}</dd>
                    </div>
                    <div v-if="order.tax_amount > 0" class="totals-row">
                        <dt>Tax</dt>
                        <dd>{{ fmt(order.tax_amount) }}</dd>
                    </div>
                    <div class="totals-row totals-grand">
                        <dt>TOTAL</dt>
                        <dd>{{ fmt(order.total_amount) }}</dd>
                    </div>
                </dl>
            </section>

            <!-- Customer -->
            <section v-if="order.customer_name || order.customer_contact || order.customer_address || order.table_number" class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">CUSTOMER</p>
                        <h2>{{ order.customer_name ?? 'Walk-in' }}</h2>
                    </div>
                </div>
                <dl class="meta-list">
                    <div v-if="order.table_number">
                        <dt>Table</dt><dd>{{ order.table_number }}</dd>
                    </div>
                    <div v-if="order.customer_contact">
                        <dt>Contact</dt><dd>{{ order.customer_contact }}</dd>
                    </div>
                    <div v-if="order.customer_address">
                        <dt>Address</dt><dd>{{ order.customer_address }}</dd>
                    </div>
                </dl>
            </section>

            <!-- Payment -->
            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">PAYMENT</p>
                        <h2>Tender</h2>
                    </div>
                </div>
                <template v-if="order.payment">
                    <dl class="meta-list">
                        <div>
                            <dt>Method</dt><dd>{{ order.payment.method }}</dd>
                        </div>
                        <div>
                            <dt>Amount Paid</dt><dd>{{ fmt(order.payment.amount) }}</dd>
                        </div>
                        <div v-if="order.payment.change > 0">
                            <dt>Change</dt><dd class="change-green">{{ fmt(order.payment.change) }}</dd>
                        </div>
                    </dl>
                    <div class="paid-badge">✓ PAID</div>
                </template>
                <div v-else class="pending-payment">
                    <span class="pending-dot" />
                    Payment Pending
                </div>
            </section>

            <!-- Notes -->
            <section v-if="order.notes" class="panel">
                <p class="eyebrow" style="margin-bottom:10px;">ORDER NOTES</p>
                <p class="notes-text">{{ order.notes }}</p>
            </section>

            <!-- GCash -->
            <section v-if="gcashQrUrl" class="gcash-panel">
                <div class="gcash-header">
                    <QrCode :size="17" />
                    <span class="gcash-title">Pay with GCash</span>
                    <span v-if="order.payment_status !== 'paid'" class="gcash-pending-tag">PENDING</span>
                </div>
                <div class="gcash-body">
                    <button class="qr-wrap" @click="qrModalOpen = true" aria-label="Tap to enlarge QR">
                        <img :src="gcashQrUrl" alt="GCash QR Code" class="qr-img" />
                        <span class="qr-hint">TAP TO ENLARGE</span>
                    </button>
                    <div class="gcash-instructions">
                        <p class="gcash-amount">{{ fmt(order.total_amount) }}</p>
                        <ol class="steps">
                            <li><span class="step-num">1</span> Open GCash → <strong>Pay QR</strong></li>
                            <li><span class="step-num">2</span> Scan the QR code</li>
                            <li><span class="step-num">3</span> Enter <strong>{{ fmt(order.total_amount) }}</strong></li>
                            <li><span class="step-num">4</span> Show cashier your confirmation</li>
                        </ol>
                    </div>
                </div>
            </section>

        </div>

        <!-- ── Footer ── -->
        <footer class="pub-footer">
            <span>{{ brandName.toUpperCase() }} · ORDER #{{ order.id }}</span>
            <span>GOOD FOOD. GOOD MOOD.</span>
        </footer>

    </div>

    <!-- QR modal -->
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="qrModalOpen" class="qr-modal" @click="qrModalOpen = false">
                <button class="qr-close" @click="qrModalOpen = false" aria-label="Close">
                    <X :size="18" />
                </button>
                <img :src="gcashQrUrl!" alt="GCash QR" class="qr-modal-img" @click.stop />
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
/* ── Base ── */
.pub-page {
    background: #f6f2e9;
    color: #24231e;
    min-height: 100svh;
    font-family: Arial, Helvetica, sans-serif;
    display: flex;
    flex-direction: column;
}

/* ── Heading ── */
.pub-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    padding: 28px clamp(16px, 4vw, 40px) 22px;
    flex-wrap: wrap;
    border-bottom: 1px solid #ded7cb;
    background: #f6f2e9;
}
.brand-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}
.brand-logo {
    width: 40px;
    height: 40px;
    object-fit: contain;
    border-radius: 5px;
    border: 1px solid #ded7cb;
    background: #fffcf6;
    flex-shrink: 0;
    margin-top: 3px;
}
.eyebrow {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1.4px;
    color: #ad3b19;
    text-transform: uppercase;
    margin: 0 0 8px;
}
.pub-heading h1 {
    font-size: clamp(24px, 5vw, 38px);
    font-weight: 850;
    letter-spacing: -1.4px;
    line-height: 1.1;
    margin: 0;
}
.pub-heading h1 em {
    font-family: Georgia, serif;
    font-weight: 400;
    color: #ad3b19;
    font-style: normal;
}
.heading-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
    font-size: 11px;
    color: #68665f;
    flex-shrink: 0;
    padding-top: 2px;
}
.queue-tag {
    background: #f2e5d8;
    color: #ad3b19;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.5px;
    padding: 3px 10px;
    border-radius: 20px;
    border: 1px solid #e4c4ab;
}

/* ── Status work-bar ── */
.status-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    background: #24231e;
    color: #f6f2e9;
    padding: 16px clamp(16px, 4vw, 40px);
}
.status-bar-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.status-bar-right { font-size: 11px; color: #c3bfb3; }

.status-chip {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 11px;
    font-weight: 800;
    border-radius: 4px;
    padding: 6px 11px;
}
.chip-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
}
.dot-pulse { animation: pulse 1.4s ease-in-out infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

.chip-pending   { background: #7b581520; color: #f5c842; }
.chip-preparing { background: #9d401d20; color: #f99b6e; }
.chip-ready     { background: #37622920; color: #7bc87c; }
.chip-completed { background: #37622920; color: #7bc87c; }
.chip-cancelled { background: #9c302820; color: #f07070; }

.pay-chip {
    font-size: 10px;
    font-weight: 700;
    border-radius: 3px;
    padding: 4px 9px;
}
.pay-paid   { background: #37622920; color: #7bc87c; }
.pay-unpaid { background: #7b581520; color: #f5c842; }

/* ── Metric strip ── */
.metric-strip {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1px;
    background: #ded7cb;
    border-bottom: 1px solid #ded7cb;
}
.metric {
    background: #fffcf6;
    padding: 18px clamp(14px, 3vw, 24px);
}
.metric-featured {
    background: #f2e5d8;
}
.metric p {
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #68665f;
    margin: 0 0 8px;
}
.metric strong {
    display: block;
    font-size: clamp(15px, 3vw, 22px);
    font-weight: 800;
    letter-spacing: -0.5px;
    line-height: 1.2;
    overflow-wrap: anywhere;
    font-variant-numeric: tabular-nums;
}
.metric-featured strong { color: #ad3b19; }
.metric > span {
    display: block;
    font-size: 10px;
    color: #777268;
    margin-top: 4px;
    line-height: 1.4;
}

/* ── Body ── */
.body-col {
    flex: 1;
    padding: clamp(14px, 3vw, 28px) clamp(14px, 4vw, 40px);
    max-width: 600px;
    width: 100%;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: 16px;
    box-sizing: border-box;
}

/* ── Panel ── */
.panel {
    background: #fffcf6;
    border: 1px solid #ded7cb;
    border-radius: 6px;
    padding: 20px;
}
.panel-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 16px;
}
.panel-heading .eyebrow { margin-bottom: 6px; }
.panel h2 {
    font-size: 17px;
    font-weight: 800;
    letter-spacing: -0.4px;
    margin: 0;
}
.count-badge {
    display: inline-block;
    margin-left: 8px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0;
    background: #f2e5d8;
    color: #ad3b19;
    padding: 3px 8px;
    border-radius: 20px;
    vertical-align: middle;
}

/* ── Items ── */
.item-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-top: 1px solid #ece5da;
    font-size: 13px;
}
.item-left {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}
.item-qty {
    font-weight: 800;
    color: #ad3b19;
    font-size: 12px;
    flex-shrink: 0;
    background: #f2e5d8;
    border-radius: 3px;
    padding: 2px 7px;
    font-variant-numeric: tabular-nums;
}
.item-name {
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.item-price { font-weight: 800; flex-shrink: 0; color: #ad3b19; }

.receipt-divider {
    border: none;
    border-top: 2px dashed #d6d0c4;
    margin: 12px 0 10px;
}

/* ── Totals ── */
.totals-list { margin: 0; padding: 0; }
.totals-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 7px 0;
    border-bottom: 1px solid #ece5da;
    font-size: 13px;
}
.totals-row:last-child { border-bottom: none; }
.totals-row dt { color: #68665f; }
.totals-row dd { font-weight: 700; font-variant-numeric: tabular-nums; }
.row-discount dt, .row-discount dd { color: #a03015; }
.totals-grand { padding-top: 10px; margin-top: 2px; border-top: 2px solid #24231e !important; border-bottom: none !important; }
.totals-grand dt { font-size: 11px; font-weight: 900; letter-spacing: 1.5px; text-transform: uppercase; color: #24231e; }
.totals-grand dd { font-size: 20px; font-weight: 800; color: #ad3b19; letter-spacing: -0.5px; }

/* ── Meta list ── */
.meta-list { margin: 0; padding: 0; }
.meta-list > div {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid #ece5da;
    font-size: 13px;
}
.meta-list > div:last-child { border-bottom: none; }
.meta-list dt { color: #68665f; flex-shrink: 0; }
.meta-list dd { font-weight: 600; text-align: right; }

/* ── Payment ── */
.paid-badge {
    display: inline-block;
    margin-top: 12px;
    background: #e4edde;
    color: #376229;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: 2px;
    padding: 5px 14px;
    border-radius: 3px;
    border: 1px solid #c6dfc0;
}
.change-green { color: #376229; }
.pending-payment {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    color: #8b481e;
    font-size: 13px;
    padding: 4px 0;
}
.pending-dot {
    width: 7px; height: 7px;
    background: #c3441c;
    border-radius: 50%;
    flex-shrink: 0;
    animation: pulse 1.4s ease-in-out infinite;
}
.notes-text { font-size: 13px; line-height: 1.7; color: #24231e; margin: 0; }

/* ── GCash panel ── */
.gcash-panel {
    background: #fffcf6;
    border: 1px solid #e4c4ab;
    border-radius: 6px;
    overflow: hidden;
}
.gcash-header {
    background: #f2e5d8;
    border-bottom: 1px solid #e4c4ab;
    padding: 13px 20px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #ad3b19;
}
.gcash-title { font-weight: 900; font-size: 13px; letter-spacing: 0.5px; flex: 1; }
.gcash-pending-tag {
    background: #ad3b19;
    color: #f6f2e9;
    font-size: 9px;
    font-weight: 900;
    letter-spacing: 1.5px;
    padding: 3px 8px;
    border-radius: 3px;
}
.gcash-body {
    padding: 20px;
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
    gap: 7px;
    background: none;
    border: none;
    cursor: zoom-in;
    padding: 0;
    flex-shrink: 0;
}
.qr-img {
    width: 150px;
    height: 150px;
    object-fit: contain;
    border-radius: 5px;
    border: 1px solid #ded7cb;
    background: #fff;
    padding: 8px;
}
.qr-hint {
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1.5px;
    color: #777268;
    text-transform: uppercase;
}
.gcash-instructions { flex: 1; min-width: 0; }
.gcash-amount {
    font-size: 26px;
    font-weight: 800;
    color: #ad3b19;
    letter-spacing: -0.5px;
    margin: 0 0 14px;
    font-variant-numeric: tabular-nums;
}
.steps { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 9px; }
.steps li {
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: 12px;
    color: #24231e;
    line-height: 1.4;
}
.step-num {
    min-width: 20px; height: 20px;
    background: #24231e;
    color: #f6f2e9;
    border-radius: 50%;
    font-size: 10px;
    font-weight: 900;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

/* ── Footer ── */
.pub-footer {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: auto;
    padding: 16px clamp(16px, 4vw, 40px);
    border-top: 1px solid #ded7cb;
    font-size: 9px;
    color: #777268;
    letter-spacing: 0.5px;
}
.pub-footer > span:first-child { font-weight: 800; letter-spacing: 1px; }

/* ── QR modal ── */
.qr-modal {
    position: fixed;
    inset: 0;
    z-index: 50;
    background: #24231ecc;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.qr-close {
    position: absolute;
    top: 16px;
    right: 16px;
    background: rgba(246, 242, 233, 0.15);
    border: 1px solid rgba(246, 242, 233, 0.25);
    border-radius: 50%;
    padding: 8px;
    color: #f6f2e9;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}
.qr-close:hover { background: rgba(246, 242, 233, 0.25); }
.qr-modal-img {
    max-width: min(90vw, 360px);
    max-height: 90vh;
    border-radius: 6px;
    background: #fff;
    padding: 16px;
    object-fit: contain;
    box-shadow: 0 30px 60px -20px #24231e;
}

/* ── Responsive ── */
@media (max-width: 480px) {
    .metric-strip { grid-template-columns: 1fr 1fr; }
    .metric:last-child { grid-column: span 2; border-top: 1px solid #ded7cb; }
    .pub-footer { flex-direction: column; gap: 4px; }
}
@media (max-width: 360px) {
    .metric-strip { grid-template-columns: 1fr; }
    .metric:last-child { grid-column: auto; }
    .metric { border-top: 1px solid #ded7cb; }
}

/* ── Transition ── */
.fade-enter-active, .fade-leave-active { transition: opacity 0.15s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
