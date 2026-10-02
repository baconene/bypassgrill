<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { ArrowLeft, ShoppingBag, User, MapPin, Clock, CreditCard, Package, Receipt, Printer, Pencil, X, Plus, Minus, Trash2, Check, Search, Eye, Copy, ArrowUpRight, Flame } from 'lucide-vue-next'
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

const statusLabel: Record<string, string> = {
    pending: 'Pending', preparing: 'Preparing', ready: 'Ready',
    completed: 'Completed', cancelled: 'Cancelled',
}
const payLabel: Record<string, string> = {
    paid: 'Paid', pending: 'Unpaid', refunded: 'Refunded', voided: 'Voided',
}

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
        product_id: i.product_id, product_name: i.product_name,
        unit_price: i.unit_price, quantity: i.quantity,
    }))
    productSearch.value = ''
    showDropdown.value  = false
    editing.value = true
}

const cancelEdit = () => { editing.value = false }

const addProduct = (p: Product) => {
    const existing = editItems.value.find(i => i.product_id === p.id)
    if (existing) { existing.quantity++ }
    else { editItems.value.push({ product_id: p.id, product_name: p.name, unit_price: p.price, quantity: 1 }) }
    productSearch.value = ''
    showDropdown.value  = false
}

const changeQty = (index: number, delta: number) => {
    editItems.value[index].quantity = Math.max(1, editItems.value[index].quantity + delta)
}

const removeItem = (index: number) => { editItems.value.splice(index, 1) }

const saveEdit = async () => {
    if (editItems.value.length === 0) { toast.error('Order must have at least one item'); return }
    saving.value = true
    try {
        await api.put('/api/v1/orders/' + props.order.id, {
            notes: editNotes.value || null,
            discount_amount: editDiscount.value || 0,
            created_at: editCreatedAt.value || null,
            items: editItems.value.map(i => ({ product_id: i.product_id, quantity: i.quantity })),
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

    <div class="order-page">

        <!-- ── Page heading ── -->
        <header class="order-heading">
            <div class="heading-left">
                <button class="back-btn" @click="goBack()" title="Back">
                    <ArrowLeft :size="15" />
                </button>
                <div>
                    <p class="eyebrow"><Flame :size="13" aria-hidden="true" /> BYPASS GRILL / ORDER DETAIL</p>
                    <h1>Order <em>#{{ order.id }}</em></h1>
                    <p class="heading-sub">
                        {{ order.order_type_label }}
                        <template v-if="order.table_number"> · Table {{ order.table_number }}</template>
                        <template v-if="order.created_by"> · by {{ order.created_by }}</template>
                    </p>
                </div>
            </div>
            <div class="heading-actions">
                <button v-if="!editing" class="action-ghost" @click="startEdit">
                    <Pencil :size="14" /> Edit
                </button>
                <button v-if="publicUrl" class="action-ghost" @click="showPublicUrl = true">
                    <Eye :size="14" /> Share
                </button>
                <button class="action-primary" :disabled="printing" @click="reprintReceipt">
                    <Printer :size="14" /> {{ printing ? 'Printing…' : 'Reprint' }}
                </button>
            </div>
        </header>

        <!-- ── Status bar ── -->
        <section class="status-bar">
            <div class="status-bar-left">
                <span class="status-indicator" :class="`status-${order.status}`">
                    <span class="status-dot" :class="['pending','preparing'].includes(order.status) ? 'dot-pulse' : ''" />
                    {{ statusLabel[order.status] ?? order.status }}
                </span>
                <span class="pay-indicator" :class="`pay-${order.payment_status}`">
                    {{ payLabel[order.payment_status] ?? order.payment_status }}
                </span>
                <span v-if="order.queue_number" class="queue-badge">Queue {{ order.queue_number }}</span>
            </div>
            <div class="status-bar-right">
                <span class="placed-time">Placed {{ fmtDatetime(order.created_at) }}</span>
            </div>
        </section>

        <!-- ── Metric cards ── -->
        <section class="metric-row" aria-label="Order summary">
            <article class="metric metric-featured">
                <p>Order total</p>
                <strong>{{ fmt(order.total_amount) }}</strong>
                <span>
                    <template v-if="order.discount_amount > 0">After −{{ fmt(order.discount_amount) }} discount</template>
                    <template v-else>Including all items</template>
                </span>
            </article>
            <article class="metric">
                <p>Items</p>
                <strong>{{ order.items.length }}</strong>
                <span>{{ order.items.reduce((s,i) => s + i.quantity, 0) }} total quantity</span>
            </article>
            <article class="metric">
                <p>Completed</p>
                <strong>{{ order.completed_at ? fmtDatetime(order.completed_at).split(',')[0] : '—' }}</strong>
                <span>{{ order.completed_at ? fmtDatetime(order.completed_at) : 'Not yet completed' }}</span>
            </article>
            <article class="metric" v-if="totalCost > 0">
                <p>Gross profit</p>
                <strong :class="grossProfit >= 0 ? 'profit-pos' : 'profit-neg'">{{ fmt(grossProfit) }}</strong>
                <span>COGS {{ fmt(totalCost) }}</span>
            </article>
            <article class="metric" v-else>
                <p>Payment method</p>
                <strong>{{ order.payments.length ? order.payments[0].tender : '—' }}</strong>
                <span>{{ order.payments.length }} payment{{ order.payments.length !== 1 ? 's' : '' }} recorded</span>
            </article>
        </section>

        <div class="columns">
            <div class="main-col">

                <!-- ── Edit panel ── -->
                <section v-if="editing" class="panel edit-panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow"><Pencil :size="12" /> EDITING ORDER</p>
                            <h2>Edit Order #{{ order.id }}</h2>
                        </div>
                        <button class="icon-btn" @click="cancelEdit" title="Cancel edit"><X :size="16" /></button>
                    </div>

                    <div class="edit-fields">
                        <div class="field">
                            <label>Notes</label>
                            <textarea v-model="editNotes" rows="2" placeholder="Order notes…" />
                        </div>
                        <div class="field">
                            <label>Date &amp; Time</label>
                            <input v-model="editCreatedAt" type="datetime-local" />
                        </div>
                        <div class="field">
                            <label>Discount (₱)</label>
                            <input v-model.number="editDiscount" type="number" min="0" step="0.01" class="w-discount" />
                        </div>
                    </div>

                    <p class="section-label">Items</p>
                    <div class="edit-items">
                        <div v-for="(item, idx) in editItems" :key="item.product_id" class="edit-item-row">
                            <div class="edit-item-info">
                                <span class="edit-item-name">{{ item.product_name }}</span>
                                <span class="edit-item-price">{{ fmt(item.unit_price) }} each</span>
                            </div>
                            <div class="qty-ctrl">
                                <button @click="changeQty(idx, -1)"><Minus :size="12" /></button>
                                <span>{{ item.quantity }}</span>
                                <button @click="changeQty(idx, 1)"><Plus :size="12" /></button>
                            </div>
                            <span class="edit-item-total">{{ fmt(item.unit_price * item.quantity) }}</span>
                            <button class="remove-btn" @click="removeItem(idx)" title="Remove"><Trash2 :size="14" /></button>
                        </div>
                        <div v-if="editItems.length === 0" class="edit-empty">No items. Add a product below.</div>
                    </div>

                    <p class="section-label" style="margin-top: 18px;">Add Product</p>
                    <div class="search-wrap">
                        <Search :size="14" class="search-icon" />
                        <input
                            v-model="productSearch"
                            @focus="showDropdown = true"
                            @blur="setTimeout(() => showDropdown = false, 150)"
                            placeholder="Search product name…"
                            class="search-input"
                        />
                        <div v-if="showDropdown && filteredProducts.length" class="search-dropdown">
                            <button v-for="p in filteredProducts" :key="p.id"
                                @mousedown.prevent="addProduct(p)" class="search-row">
                                <span>{{ p.name }}</span>
                                <span>{{ fmt(p.price) }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="edit-total-row">
                        <span>Estimated Total</span>
                        <strong>{{ fmt(editTotal) }}</strong>
                    </div>

                    <div class="edit-actions">
                        <button class="action-primary" :disabled="saving || editItems.length === 0" @click="saveEdit">
                            <Check :size="14" /> {{ saving ? 'Saving…' : 'Save Changes' }}
                        </button>
                        <button class="action-ghost" @click="cancelEdit">Cancel</button>
                    </div>
                </section>

                <!-- ── Items panel ── -->
                <section v-else class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow"><Package :size="12" /> ORDER ITEMS</p>
                            <h2>Items <span class="count-badge">{{ order.items.reduce((s,i) => s+i.quantity, 0) }} qty</span></h2>
                        </div>
                    </div>

                    <!-- Mobile list -->
                    <div class="items-mobile">
                        <div v-for="item in order.items" :key="item.id" class="item-mobile-row">
                            <div class="item-mobile-top">
                                <div>
                                    <strong>{{ item.product_name }}</strong>
                                    <small v-if="item.category_name">{{ item.category_name }}</small>
                                </div>
                                <strong class="item-subtotal">{{ fmt(item.subtotal) }}</strong>
                            </div>
                            <div class="item-mobile-meta">
                                <span class="qty-pill">× {{ item.quantity }}</span>
                                <span>@ {{ fmt(item.unit_price) }}</span>
                                <span v-if="item.unit_cost > 0" class="cost-note">cost {{ fmt(item.cost_subtotal) }}</span>
                            </div>
                            <div v-if="item.modifiers.length" class="mod-tags">
                                <span v-for="m in item.modifiers" :key="m.name" class="mod-tag">+{{ m.name }} ({{ fmt(m.price) }})</span>
                            </div>
                            <p v-if="item.special_instructions" class="item-note">"{{ item.special_instructions }}"</p>
                        </div>
                    </div>

                    <!-- Desktop table -->
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="col-center">Qty</th>
                                    <th class="col-right">Unit Price</th>
                                    <th class="col-right">Subtotal</th>
                                    <th class="col-right">Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in order.items" :key="item.id">
                                    <td>
                                        <strong>{{ item.product_name }}</strong>
                                        <small v-if="item.category_name">{{ item.category_name }}</small>
                                        <div v-if="item.modifiers.length" class="mod-tags" style="margin-top:5px;">
                                            <span v-for="m in item.modifiers" :key="m.name" class="mod-tag">+{{ m.name }} ({{ fmt(m.price) }})</span>
                                        </div>
                                        <p v-if="item.special_instructions" class="item-note">"{{ item.special_instructions }}"</p>
                                    </td>
                                    <td class="col-center"><span class="qty-pill">× {{ item.quantity }}</span></td>
                                    <td class="col-right muted">{{ fmt(item.unit_price) }}</td>
                                    <td class="col-right accent-bold">{{ fmt(item.subtotal) }}</td>
                                    <td class="col-right muted">{{ item.unit_cost > 0 ? fmt(item.cost_subtotal) : '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- ── Notes ── -->
                <section v-if="order.notes" class="panel">
                    <p class="eyebrow" style="margin-bottom:12px;"><Package :size="12" /> ORDER NOTES</p>
                    <p class="notes-text">{{ order.notes }}</p>
                </section>

            </div>

            <aside class="side-col">

                <!-- ── Customer ── -->
                <section v-if="order.customer_name || order.table_number || order.customer_contact || order.customer_address" class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow"><User :size="12" /> CUSTOMER</p>
                            <h2>{{ order.customer_name ?? 'Walk-in' }}</h2>
                        </div>
                    </div>
                    <dl class="summary-list">
                        <div v-if="order.table_number">
                            <dt>Table</dt>
                            <dd>{{ order.table_number }}</dd>
                        </div>
                        <div v-if="order.customer_contact">
                            <dt>Contact</dt>
                            <dd>{{ order.customer_contact }}</dd>
                        </div>
                        <div v-if="order.customer_address">
                            <dt><MapPin :size="11" /> Address</dt>
                            <dd>{{ order.customer_address }}</dd>
                        </div>
                    </dl>
                </section>

                <!-- ── Timeline ── -->
                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow"><Clock :size="12" /> TIMELINE</p>
                            <h2>Key times</h2>
                        </div>
                    </div>
                    <dl class="summary-list">
                        <div>
                            <dt>Placed</dt>
                            <dd>{{ fmtDatetime(order.created_at) }}</dd>
                        </div>
                        <div>
                            <dt>Completed</dt>
                            <dd>{{ fmtDatetime(order.completed_at) }}</dd>
                        </div>
                        <div v-if="order.created_by">
                            <dt>Cashier</dt>
                            <dd>{{ order.created_by }}</dd>
                        </div>
                    </dl>
                </section>

                <!-- ── Totals ── -->
                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow"><Receipt :size="12" /> TOTALS</p>
                            <h2>Bill breakdown</h2>
                        </div>
                    </div>
                    <dl class="summary-list">
                        <div>
                            <dt>Subtotal</dt>
                            <dd>{{ fmt(order.subtotal) }}</dd>
                        </div>
                        <div v-if="order.discount_amount > 0" class="row-discount">
                            <dt>Discount</dt>
                            <dd>−{{ fmt(order.discount_amount) }}</dd>
                        </div>
                        <div v-if="order.tax_amount > 0">
                            <dt>Tax</dt>
                            <dd>{{ fmt(order.tax_amount) }}</dd>
                        </div>
                        <div class="summary-total">
                            <dt>Total</dt>
                            <dd>{{ fmt(order.total_amount) }}</dd>
                        </div>
                        <template v-if="totalCost > 0">
                            <div>
                                <dt>COGS</dt>
                                <dd class="muted">−{{ fmt(totalCost) }}</dd>
                            </div>
                            <div class="summary-total">
                                <dt>Gross Profit</dt>
                                <dd :class="grossProfit >= 0 ? 'profit-pos' : 'profit-neg'">{{ fmt(grossProfit) }}</dd>
                            </div>
                        </template>
                    </dl>
                </section>

                <!-- ── Payments ── -->
                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow"><CreditCard :size="12" /> PAYMENTS</p>
                            <h2>Tender records</h2>
                        </div>
                    </div>
                    <div v-if="order.payments.length === 0" class="empty-note">No payments recorded.</div>
                    <dl v-else class="summary-list">
                        <div v-for="p in order.payments" :key="p.id" class="payment-row">
                            <dt>
                                <strong>{{ p.tender }}</strong>
                                <small v-if="p.reference">Ref: {{ p.reference }}</small>
                                <small>{{ fmtDatetime(p.created_at) }}</small>
                            </dt>
                            <dd>
                                <span class="pay-amount">{{ fmt(p.amount) }}</span>
                                <small :class="`pay-${p.status}`">{{ p.status }}</small>
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- ── Share link ── -->
                <section v-if="publicUrl" class="panel share-panel">
                    <p class="eyebrow"><Eye :size="12" /> PUBLIC LINK</p>
                    <h2>Customer tracking</h2>
                    <p class="panel-copy">Share this URL so the customer can track their order status in real time.</p>
                    <button class="text-link" @click="showPublicUrl = true">
                        Open share dialog <ArrowUpRight :size="14" />
                    </button>
                </section>

            </aside>
        </div>

        <footer class="order-footer">
            <span>BYPASS GRILL · ORDER #{{ order.id }}</span>
            <span>{{ order.order_type_label }} · {{ statusLabel[order.status] ?? order.status }}</span>
        </footer>

    </div>

    <!-- ── Public URL modal ── -->
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="showPublicUrl" class="modal-backdrop order-page" @click.self="showPublicUrl = false">
                <div class="modal-panel">
                    <header class="modal-header">
                        <div>
                            <p class="eyebrow"><Eye :size="12" /> PUBLIC ORDER URL</p>
                            <h2>Share with customer</h2>
                        </div>
                        <button class="icon-btn" @click="showPublicUrl = false"><X :size="16" /></button>
                    </header>
                    <p class="panel-copy">The customer can track their order status using this link.</p>
                    <div class="url-box">{{ publicUrl }}</div>
                    <div class="modal-actions">
                        <button @click="copyPublicUrl"
                            :class="['action-primary', urlCopied ? 'action-success' : '']">
                            <Check v-if="urlCopied" :size="14" /><Copy v-else :size="14" />
                            {{ urlCopied ? 'Copied!' : 'Copy link' }}
                        </button>
                        <a :href="publicUrl!" target="_blank" class="action-ghost">
                            <Eye :size="14" /> Open
                        </a>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
/* ── Base ── */
.order-page {
    background: #f6f2e9;
    color: #24231e;
    min-height: 100%;
    padding: 30px clamp(16px, 3vw, 40px);
    font-family: Arial, Helvetica, sans-serif;
    color-scheme: light;
    max-width: 1100px;
}

/* ── Header ── */
.order-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.heading-left {
    display: flex;
    align-items: flex-start;
    gap: 14px;
}
.back-btn {
    display: grid;
    place-items: center;
    width: 34px;
    height: 34px;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    background: #fffcf6;
    color: #24231e;
    flex-shrink: 0;
    margin-top: 2px;
    cursor: pointer;
}
.back-btn:hover { background: #efeadf; }
.eyebrow {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1.4px;
    color: #ad3b19;
    text-transform: uppercase;
}
.order-heading h1 {
    font-size: clamp(26px, 3vw, 40px);
    font-weight: 850;
    letter-spacing: -1.5px;
    line-height: 1.1;
    margin: 10px 0 6px;
}
.order-heading h1 em {
    font-family: Georgia, serif;
    font-weight: 400;
    color: #ad3b19;
    font-style: normal;
}
.heading-sub {
    font-size: 12px;
    color: #68665f;
    line-height: 1.5;
}
.heading-actions {
    display: flex;
    gap: 9px;
    align-items: center;
    flex-wrap: wrap;
    flex-shrink: 0;
    padding-top: 4px;
}
.action-primary, .action-ghost {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 800;
    padding: 10px 14px;
    cursor: pointer;
    white-space: nowrap;
}
.action-primary {
    background: #c3441c;
    color: #fff;
    border: none;
}
.action-primary:hover:not(:disabled) { background: #a73513; }
.action-primary:disabled { opacity: 0.55; cursor: not-allowed; }
.action-success { background: #376229 !important; }
.action-ghost {
    border: 1px solid #d4cdbf;
    background: #fffcf6;
    color: #24231e;
    text-decoration: none;
}
.action-ghost:hover { background: #efeadf; }
.icon-btn {
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    border: none;
    background: transparent;
    color: #68665f;
    cursor: pointer;
}
.icon-btn:hover { background: #efeadf; color: #24231e; }

/* ── Status bar ── */
.status-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    background: #24231e;
    color: #f6f2e9;
    border-radius: 6px;
    padding: 16px 20px;
    margin-bottom: 20px;
}
.status-bar-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.status-bar-right { font-size: 11px; color: #c3bfb3; white-space: nowrap; }
.placed-time { font-size: 11px; color: #c3bfb3; }

.status-indicator {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 11px;
    font-weight: 800;
    border-radius: 4px;
    padding: 6px 10px;
}
.status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
}
.dot-pulse { animation: pulse 1.4s ease-in-out infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }

.status-pending   { background: #7b581520; color: #f5c842; }
.status-preparing { background: #9d401d20; color: #f99b6e; }
.status-ready     { background: #37622920; color: #7bc87c; }
.status-completed { background: #37622920; color: #7bc87c; }
.status-cancelled { background: #9c302820; color: #f07070; }

.pay-indicator {
    font-size: 10px;
    font-weight: 700;
    border-radius: 3px;
    padding: 4px 9px;
}
.pay-paid     { background: #37622920; color: #7bc87c; }
.pay-pending  { background: #7b581520; color: #f5c842; }
.pay-refunded { background: #4a327020; color: #c4a0f8; }
.pay-voided   { background: #9c302820; color: #f07070; }

.queue-badge {
    font-size: 10px;
    font-weight: 800;
    border: 1px solid #ffffff30;
    border-radius: 20px;
    padding: 3px 10px;
    color: #f6f2e9;
}

/* ── Metric cards ── */
.metric-row {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 13px;
    margin-bottom: 22px;
}
.metric {
    background: #fffcf6;
    border: 1px solid #ded7cb;
    padding: 19px;
    border-radius: 5px;
}
.metric-featured {
    background: #f2e5d8;
    border-color: #e4c4ab;
}
.metric p {
    font-size: 10px;
    font-weight: 700;
    color: #68665f;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.metric strong {
    display: block;
    font-size: clamp(20px, 2.2vw, 30px);
    letter-spacing: -0.8px;
    line-height: 1.25;
    margin: 10px 0 6px;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}
.metric-featured strong { color: #ad3b19; }
.metric > span {
    font-size: 10px;
    color: #777268;
    line-height: 1.5;
}
.profit-pos { color: #376229; }
.profit-neg { color: #a03015; }

/* ── Layout columns ── */
.columns {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 280px;
    gap: 20px;
    align-items: start;
}
.main-col, .side-col { display: flex; flex-direction: column; gap: 18px; min-width: 0; }

/* ── Panel ── */
.panel {
    background: #fffcf6;
    border: 1px solid #ded7cb;
    border-radius: 6px;
    padding: 21px;
    min-width: 0;
}
.panel-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 18px;
}
.panel-heading .eyebrow { margin-bottom: 6px; }
.panel h2 {
    font-size: 17px;
    font-weight: 800;
    letter-spacing: -0.4px;
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
.text-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 700;
    color: #ad3b19;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
}
.text-link:hover { text-decoration: underline; text-underline-offset: 3px; }
.empty-note { font-size: 12px; color: #777268; padding: 8px 0; }
.panel-copy { font-size: 11px; line-height: 1.8; color: #68665f; margin: 8px 0 16px; }
.notes-text { font-size: 13px; line-height: 1.8; color: #24231e; }

/* ── Items: mobile ── */
.items-mobile { display: block; }
.table-scroll { display: none; }
.item-mobile-row {
    padding: 14px 0;
    border-top: 1px solid #ece5da;
}
.item-mobile-top { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; }
.item-mobile-top strong { font-size: 13px; display: block; line-height: 1.4; }
.item-mobile-top small { display: block; font-size: 10px; color: #777268; margin-top: 2px; }
.item-subtotal { font-size: 13px; font-weight: 800; color: #ad3b19; white-space: nowrap; }
.item-mobile-meta { display: flex; flex-wrap: wrap; gap: 8px; font-size: 11px; color: #68665f; margin-top: 5px; }
.qty-pill { background: #efeadf; padding: 2px 8px; border-radius: 3px; font-weight: 700; font-size: 11px; }
.cost-note { color: #999; }
.mod-tags { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }
.mod-tag { background: #f2e5d8; color: #ad3b19; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 3px; }
.item-note { font-size: 11px; color: #777268; font-style: italic; margin-top: 4px; }

/* ── Summary list ── */
.summary-list { font-size: 12px; }
.summary-list > div {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid #ece5da;
}
.summary-list dt { color: #68665f; display: flex; align-items: center; gap: 4px; }
.summary-list dd { font-weight: 700; text-align: right; font-variant-numeric: tabular-nums; }
.summary-total dt, .summary-total dd { font-weight: 800; color: #24231e; font-size: 13px; }
.row-discount dt, .row-discount dd { color: #a03015; }
.muted { color: #777268; font-weight: 400; }
.accent-bold { font-weight: 800; color: #ad3b19; }

/* ── Payment rows ── */
.payment-row dt strong { display: block; font-weight: 700; font-size: 13px; }
.payment-row dt small { display: block; color: #777268; font-size: 10px; margin-top: 2px; }
.pay-amount { font-size: 14px; font-weight: 800; color: #376229; display: block; text-align: right; }

/* ── Edit panel ── */
.edit-panel { border: 2px solid #c3441c; }
.section-label {
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: #68665f;
    margin-bottom: 10px;
}
.edit-fields { display: flex; flex-direction: column; gap: 14px; margin-bottom: 20px; }
.field label { display: block; font-size: 9px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: #68665f; margin-bottom: 6px; }
.field textarea, .field input {
    width: 100%;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    padding: 9px 11px;
    font-size: 13px;
    background: #fff;
    color: #24231e;
    font-family: inherit;
    resize: none;
}
.field textarea:focus, .field input:focus { outline: 2px solid #ad3b19; outline-offset: 1px; }
.w-discount { width: 140px !important; }
.edit-items { border: 1px solid #ded7cb; border-radius: 4px; overflow: hidden; margin-bottom: 4px; }
.edit-item-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    border-top: 1px solid #ece5da;
    background: #fffcf6;
    font-size: 12px;
}
.edit-item-row:first-child { border-top: none; }
.edit-item-info { flex: 1; min-width: 0; }
.edit-item-name { font-weight: 700; display: block; }
.edit-item-price { color: #777268; font-size: 10px; }
.qty-ctrl { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
.qty-ctrl button {
    display: grid; place-items: center;
    width: 26px; height: 26px;
    border: 1px solid #d4cdbf;
    border-radius: 3px;
    background: #fffcf6;
    cursor: pointer;
}
.qty-ctrl button:hover { background: #efeadf; }
.qty-ctrl span { width: 30px; text-align: center; font-weight: 800; font-size: 13px; }
.edit-item-total { width: 70px; text-align: right; font-weight: 800; font-size: 12px; flex-shrink: 0; }
.remove-btn { color: #a03015; background: none; border: none; cursor: pointer; padding: 3px; flex-shrink: 0; }
.remove-btn:hover { color: #7f1d1d; }
.edit-empty { padding: 16px; text-align: center; font-size: 12px; color: #777268; }
.search-wrap { position: relative; }
.search-input {
    width: 100%;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    padding: 9px 11px 9px 34px;
    font-size: 13px;
    background: #fff;
    color: #24231e;
    font-family: inherit;
}
.search-input:focus { outline: 2px solid #ad3b19; outline-offset: 1px; }
.search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #777268; pointer-events: none; }
.search-dropdown {
    position: absolute;
    z-index: 20;
    width: 100%;
    margin-top: 3px;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    background: #fffcf6;
    box-shadow: 0 8px 24px -4px #24231e26;
    max-height: 220px;
    overflow-y: auto;
}
.search-row {
    width: 100%;
    display: flex;
    justify-content: space-between;
    padding: 9px 14px;
    font-size: 12px;
    text-align: left;
    border-top: 1px solid #ece5da;
    background: none;
    cursor: pointer;
    color: #24231e;
}
.search-row:first-child { border-top: none; }
.search-row:hover { background: #efeadf; }
.edit-total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top: 1px solid #ece5da;
    padding-top: 14px;
    margin-top: 14px;
    font-size: 12px;
    color: #68665f;
}
.edit-total-row strong { font-size: 20px; font-weight: 800; color: #ad3b19; letter-spacing: -0.5px; }
.edit-actions { display: flex; gap: 10px; margin-top: 18px; }

/* ── Desktop table ── */
table { border-collapse: collapse; width: 100%; white-space: nowrap; font-size: 12px; }
th {
    text-align: left;
    font-size: 9px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #777268;
    font-weight: 700;
    padding: 0 14px 12px 0;
}
td {
    padding: 13px 14px 13px 0;
    border-top: 1px solid #ece5da;
    vertical-align: top;
}
td strong { font-size: 13px; }
td small { display: block; color: #777268; font-size: 10px; margin-top: 3px; }
.col-center { text-align: center; }
.col-right { text-align: right; }

/* ── Share panel ── */
.share-panel { background: #ebe8d3; border-color: #d0c9af; }
.share-panel h2 { margin: 8px 0; }

/* ── Footer ── */
.order-footer {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 28px;
    padding-top: 16px;
    border-top: 1px solid #ded7cb;
    font-size: 9px;
    color: #777268;
    letter-spacing: 0.5px;
}
.order-footer > span:first-child { font-weight: 700; letter-spacing: 1px; }

/* ── Modal ── */
.modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    background: #24231e8c;
    padding: 16px;
}
@media (min-width: 560px) {
    .modal-backdrop { align-items: center; }
}
.modal-panel {
    width: min(500px, 100%);
    border: 1px solid #ded7cb;
    border-radius: 6px;
    background: #fffcf6;
    padding: 22px;
    box-shadow: 0 30px 60px -20px #24231ecc;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 14px;
}
.modal-header .eyebrow { margin-bottom: 6px; }
.modal-header h2 { font-size: 17px; font-weight: 800; }
.url-box {
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    background: #f6f2e9;
    padding: 10px 13px;
    font-size: 11px;
    font-family: monospace;
    word-break: break-all;
    color: #24231e;
    margin-bottom: 14px;
    user-select: all;
}
.modal-actions { display: flex; gap: 9px; }

/* ── Focus & interaction ── */
.order-page :focus-visible { outline: 2px solid #ad3b19; outline-offset: 3px; }
.order-page button { cursor: pointer; }

/* ── Fade transition ── */
.fade-enter-active, .fade-leave-active { transition: opacity 0.18s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

/* ── Responsive ── */
@media (max-width: 960px) {
    .columns { grid-template-columns: 1fr; }
    .side-col { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; }
    .metric-row { grid-template-columns: repeat(2, 1fr); }
}
@media (min-width: 640px) {
    .items-mobile { display: none; }
    .table-scroll { display: block; overflow-x: auto; }
}
@media (max-width: 640px) {
    .order-heading { gap: 12px; }
    .heading-actions { gap: 7px; }
    .metric-row { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .metric { padding: 14px; }
    .metric strong { font-size: 22px; }
    .status-bar { padding: 13px 16px; }
    .status-bar-right { display: none; }
    .side-col { display: flex; }
    .order-footer { flex-direction: column; }
}
@media (max-width: 400px) {
    .heading-actions .action-ghost { display: none; }
}
@media (prefers-reduced-motion: reduce) {
    .dot-pulse { animation: none; }
}
</style>
