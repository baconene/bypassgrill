<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { Head, Link as InertiaLink, usePage } from '@inertiajs/vue3'
import { useCartStore } from '@/stores/cartStore'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import { ShoppingCart, X, Plus, Minus, Search, CreditCard, Banknote, CheckCircle2, Printer, ClipboardList, ChevronDown, Copy, Check, Flame, Wallet, ArrowUpRight } from 'lucide-vue-next'
import { printReceipt as doPrint } from '@/utils/printReceipt'
import { queueOrder, queuePayment } from '@/utils/offlineQueue'
import { refreshCount } from '@/utils/offlineSync'
import OfflineBanner from '@/components/OfflineBanner.vue'
import { FocusScope } from 'reka-ui'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Point of Sale', href: '/pos' },
        ],
    },
})

interface Modifier { id: number; name: string; price: number }
interface Product {
    id: number; name: string; description: string; price: number; image: string | null
    category_id: number; category: { id: number; name: string } | null; modifiers: Modifier[]
}
interface Category { id: number; name: string }
interface Tender { id: number; name: string; is_active: boolean; display_order: number }
interface PendingOrderState {
    id: number
    total_amount: number
    queue_number?: number | string | null
    order_type?: string
    table_number?: string | null
    customer_name?: string | null
    customer_contact?: string | null
    customer_address?: string | null
    notes?: string | null
    subtotal?: number
    discount_amount?: number
    _localId?: string
    _offlineQueue?: string
    _existingItems?: { name: string; quantity: number; unit_price: number }[]
    _isExistingOrder?: boolean
    public_token?: string | null
}

interface UnpaidOrder {
    id: number
    queue_number: number | string | null
    order_type: string
    table_number: string | null
    customer_name: string | null
    customer_contact: string | null
    customer_address: string | null
    notes: string | null
    subtotal: string
    discount_amount: string
    total_amount: string
    payment_status: string
    public_token: string | null
    items: { id: number; product: { id: number; name: string }; quantity: number; unit_price: string }[]
    created_at: string
}

interface CompletedOrder {
    orderId: number; queueNumber: number | string | null; orderType: string
    tableNumber: string | null; customerName: string | null
    customerContact: string | null; customerAddress: string | null; notes: string | null
    items: { name: string; quantity: number; unit_price: number }[]
    subtotal: number; discount: number; total: number
    tenderName: string; amountTendered: number; change: number; paid: boolean
    publicToken: string | null
}

const props = defineProps<{ categories: Category[]; products: Product[] }>()

const cartStore = useCartStore()
const page = usePage()
const user = computed(() => page.props.auth?.user)

const selectedCategoryId = ref<number | null>(null)
const searchQuery = ref('')
const selectedProduct = ref<Product | null>(null)
const productQty = ref(1)
const selectedModifiers = ref<number[]>([])
const submitting = ref(false)
const cartOpen = ref(false)
const mobileViewport = ref(false)
let originalOverflow: string | null = null
const updateViewport = () => {
    mobileViewport.value = window.innerWidth < 1024
    if (!mobileViewport.value) cartOpen.value = false
}
const searchInput = ref<HTMLInputElement | null>(null)
const cartItemCount = computed(() => cartStore.items.reduce((sum, item) => sum + item.quantity, 0))
const orderHint = computed(() => !cartStore.items.length ? 'Choose products to start an order.' : !cartStore.orderType ? 'Select an order type to continue.' : !cartStore.customerName.trim() ? 'Add a customer name to continue.' : 'Ready to review payment.')
const clearCart = () => {
    if (cartStore.items.length && !confirm('Clear this cart? Any already-saved order stays in Pending Payments.')) return
    cartStore.clear()
    orderTypeOpen.value = true
}

// Payment modal state
const paymentOpen = ref(false)
const pendingOrder = ref<PendingOrderState | null>(null)
const isOfflineOrder = () => !!pendingOrder.value?._localId
const tenders = ref<Tender[]>([])
const selectedTenderId = ref<number | null>(null)
const amountTendered = ref('')
const reference = ref('')
const paymentSubmitting = ref(false)
const paymentDone = ref(false)
const completedOrder = ref<CompletedOrder | null>(null)

// Copy link state
const linkCopied = ref(false)
const copyPublicLink = async () => {
    if (!completedOrder.value?.publicToken) return
    const url = `${window.location.origin}/public/orders/${completedOrder.value.publicToken}`
    try {
        await navigator.clipboard.writeText(url)
        linkCopied.value = true
        setTimeout(() => { linkCopied.value = false }, 2000)
    } catch {
        toast.error('Could not copy link')
    }
}

// Cart order-type section collapse
const orderTypeOpen = ref(true)
const orderTypeSummary = computed(() => {
    const type = cartStore.orderType
    if (!type) return ''
    const label = type === 'dine_in' ? 'Dine In' : type === 'takeout' ? 'Takeout' : 'Delivery'
    const parts = [label]
    if (cartStore.tableNumber)  parts.push(cartStore.tableNumber)
    if (cartStore.customerName) parts.push(cartStore.customerName)
    return parts.join(' · ')
})

// Unpaid orders panel
const unpaidOrdersOpen = ref(false)
const unpaidOrders = ref<UnpaidOrder[]>([])
const loadingUnpaid = ref(false)
const unpaidSearch = ref('')
const filteredUnpaidOrders = computed(() => {
    const query = unpaidSearch.value.trim().toLowerCase()
    return unpaidOrders.value.filter(order => !query || `${order.id} ${order.queue_number ?? ''} ${order.customer_name ?? ''} ${order.table_number ?? ''}`.toLowerCase().includes(query))
})

const filteredProducts = computed(() => {
    let list = props.products
    if (selectedCategoryId.value !== null) {
        list = list.filter((p) => p.category_id === selectedCategoryId.value)
    }
    if (searchQuery.value.trim()) {
        const q = searchQuery.value.trim().toLowerCase()
        list = list.filter((p) => p.name.toLowerCase().includes(q))
    }
    return list
})

const modifierTotal = computed(() =>
    selectedModifiers.value.reduce((sum, id) => {
        const m = selectedProduct.value?.modifiers.find((x) => x.id === id)
        return sum + (m?.price ?? 0)
    }, 0),
)

const change = computed(() => {
    const tendered = parseFloat(amountTendered.value) || 0
    const total = pendingOrder.value?.total_amount ?? 0
    return tendered - total
})
const paymentReady = computed(() => {
    const amount = Number(amountTendered.value)
    return !!pendingOrder.value && selectedTenderId.value !== null && amountTendered.value !== '' && Number.isFinite(amount) && amount >= pendingOrder.value.total_amount && Math.abs(amount * 100 - Math.round(amount * 100)) < 0.00001 && !paymentSubmitting.value && !cancellingOrder.value
})
const cashAmounts = computed(() => {
    if (!pendingOrder.value || !/^cash$/i.test(tenders.value.find(t => t.id === selectedTenderId.value)?.name.trim() ?? '')) return []
    return [100, 200, 500, 1000].filter(amount => amount > pendingOrder.value!.total_amount)
})
const onPosKeydown = (event: KeyboardEvent) => {
    if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey || paymentOpen.value || selectedProduct.value || unpaidOrdersOpen.value || cartOpen.value) return
    const target = event.target as HTMLElement
    if (target.closest('input, textarea, select, [contenteditable="true"]')) return
    event.preventDefault()
    searchInput.value?.focus()
}

const openProduct = (product: Product) => {
    selectedProduct.value = product
    productQty.value = 1
    selectedModifiers.value = []
}

const addToCart = () => {
    if (!selectedProduct.value) return
    cartStore.addItem(selectedProduct.value, productQty.value, selectedModifiers.value)
    toast.success(`${selectedProduct.value.name} added to cart`)
    selectedProduct.value = null
}

const cartQtyFor = (productId: number): number =>
    cartStore.items.filter(i => i.product_id === productId).reduce((sum, i) => sum + i.quantity, 0)

const quickAdd = (product: Product) => {
    if (product.modifiers?.length) {
        openProduct(product)
        return
    }
    cartStore.addItem(product, 1, [])
}

const quickRemove = (product: Product) => {
    const item = cartStore.items.find(i => i.product_id === product.id && i.modifiers.length === 0)
    if (!item) return
    if (item.quantity <= 1) {
        cartStore.removeItem(item.id)
    } else {
        cartStore.updateQuantity(item.id, item.quantity - 1)
    }
}

const loadTenders = async () => {
    if (tenders.value.length > 0) return
    try {
        const res = await api.get('/api/v1/payment-tenders')
        tenders.value = res.data
    } catch {
        // non-fatal
    }
}

const queueOrderOffline = async (payload: Record<string, unknown>): Promise<boolean> => {
    try {
        const queued = await queueOrder(payload)
        pendingOrder.value = {
            id: 0,
            total_amount: Number(cartStore.total),
            _localId: queued.localId,
            _offlineQueue: queued.offlineQueueNumber,
        }
        await refreshCount()
        toast.warning(`Offline — order ${queued.offlineQueueNumber} queued for sync.`)
        return true
    } catch (err) {
        console.error('[Offline queue error]', err)
        toast.error(`Offline queue failed: ${err instanceof Error ? err.message : String(err)}`)
        return false
    }
}

const submitOrder = async () => {
    if (submitting.value) return
    if (cartStore.items.length === 0) return
    if (!cartStore.orderType) {
        orderTypeOpen.value = true
        toast.error('Please select an order type.')
        return
    }
    if (!cartStore.customerName.trim()) {
        orderTypeOpen.value = true
        toast.error('Customer name is required.')
        return
    }
    submitting.value = true
    try {
        const itemsPayload = cartStore.items.map((item) => ({
            product_id: item.product_id,
            quantity: item.quantity,
            modifiers: item.modifiers ?? [],
        }))

        // ── Modify flow: update an existing pending order instead of creating a new one ──
        if (cartStore.editingOrderId) {
            try {
                const res = await api.put(`/api/v1/orders/${cartStore.editingOrderId}`, {
                    notes: '',
                    discount_amount: cartStore.discount,
                    items: itemsPayload,
                })
                const raw = res.data.data ?? res.data
                pendingOrder.value = { ...raw, total_amount: parseFloat(raw.total_amount ?? 0) }
            } catch (err: any) {
                toast.error(err.response?.data?.message ?? 'Failed to update order')
                return
            }
            cartOpen.value = false
            await loadTenders()
            selectedTenderId.value = null
            amountTendered.value = (pendingOrder.value?.total_amount ?? 0).toFixed(2)
            reference.value = ''
            paymentOpen.value = true
            return
        }

        const payload = {
            order_type: cartStore.orderType,
            table_number: cartStore.tableNumber,
            customer_name: cartStore.customerName || null,
            customer_contact: cartStore.customerContact || null,
            customer_address: cartStore.customerAddress || null,
            discount_amount: cartStore.discount,
            notes: '',
            items: itemsPayload,
        }

        // If already offline before the call — skip network entirely
        if (!navigator.onLine) {
            const ok = await queueOrderOffline(payload as Record<string, unknown>)
            if (!ok) return
        } else {
            try {
                const res = await api.post('/api/v1/orders', payload)
                const raw = res.data.data ?? res.data
                pendingOrder.value = { ...raw, total_amount: parseFloat(raw.total_amount ?? 0) }
            } catch (err: any) {
                // Connection dropped mid-request — no server response
                if (!err.response) {
                    const ok = await queueOrderOffline(payload as Record<string, unknown>)
                    if (!ok) return
                } else {
                    // Server returned a real error (validation, auth, etc.)
                    toast.error(err.response.data?.message ?? 'Failed to submit order')
                    return
                }
            }
        }

        cartOpen.value = false
        await loadTenders()
        selectedTenderId.value = null
        amountTendered.value = (pendingOrder.value?.total_amount ?? 0).toFixed(2)
        reference.value = ''
        paymentOpen.value = true
    } finally {
        submitting.value = false
    }
}

const captureOrder = (paid: boolean): CompletedOrder => {
    const o = pendingOrder.value!
    const tendered = parseFloat(amountTendered.value) || o.total_amount
    const items = o._existingItems ?? cartStore.items.map(i => ({ name: i.name, quantity: i.quantity, unit_price: i.unit_price }))
    const subtotal = o.subtotal ?? cartStore.subtotal
    const discount = o.discount_amount ?? cartStore.discount
    return {
        orderId: o.id,
        queueNumber: o._offlineQueue ?? o.queue_number ?? null,
        orderType: o.order_type ?? 'dine_in',
        tableNumber: o.table_number ?? null,
        customerName: o.customer_name ?? null,
        customerContact: o.customer_contact ?? null,
        customerAddress: o.customer_address ?? null,
        notes: o.notes ?? null,
        items,
        subtotal,
        discount,
        total: o.total_amount,
        tenderName: tenders.value.find(t => t.id === selectedTenderId.value)?.name ?? '',
        amountTendered: tendered,
        change: paid ? Math.max(0, tendered - o.total_amount) : 0,
        paid,
        publicToken: o.public_token ?? null,
    }
}

const submitPayment = async () => {
    if (!pendingOrder.value || !paymentReady.value) return
    paymentSubmitting.value = true
    try {
        const paymentPayload = {
            order_id: pendingOrder.value.id,
            payment_tender_id: selectedTenderId.value,
            amount: pendingOrder.value.total_amount,
            reference: reference.value || null,
        }

        if (isOfflineOrder()) {
            // Order is queued offline — queue the payment linked by localId
            await queuePayment(pendingOrder.value._localId!, paymentPayload as Record<string, unknown>)
            await refreshCount()
            toast.warning('Payment queued — will sync when connection is restored.')
        } else {
            try {
                await api.post('/api/v1/payments', paymentPayload)
            } catch (err: any) {
                // Connection dropped between order and payment — queue the payment
                if (!navigator.onLine || !err.response) {
                    await queuePayment(pendingOrder.value._localId ?? String(pendingOrder.value.id), paymentPayload as Record<string, unknown>)
                    await refreshCount()
                    toast.warning('Payment queued — will sync when connection is restored.')
                } else {
                    throw err
                }
            }
        }

        completedOrder.value = captureOrder(true)
        paymentDone.value = true
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Payment failed')
    } finally {
        paymentSubmitting.value = false
    }
}

const skipPayment = () => {
    if (!pendingOrder.value || paymentSubmitting.value || cancellingOrder.value) return
    completedOrder.value = captureOrder(false)
    paymentDone.value = true
}

// Go back to the cart so the cashier can add more items before taking payment.
// Switches the cart into modify/update mode so submitting will PUT to the existing order.
const holdOrder = () => {
    if (paymentSubmitting.value || cancellingOrder.value) return
    const o = pendingOrder.value
    if (!o) return

    // A pending order opened from the list isn't in the cart. Load its own items
    // first, or "Update order" would replace them with whatever the cart holds.
    if (o._isExistingOrder && cartStore.editingOrderId !== o.id) {
        const saved = unpaidOrders.value.find(order => order.id === o.id)
        if (saved && cartStore.items.length && !confirm('Replace the current cart with this pending order? Unsaved cart changes will be discarded.')) return
        paymentOpen.value = false
        pendingOrder.value = null
        if (!saved) {
            toast.info(`Order #${o.queue_number ?? o.id} stays in Pending Payments.`)
            return
        }
        loadOrderIntoCart(saved)
        toast.info(`Add items to the cart, then press "Update order" to continue with #${saved.queue_number ?? saved.id}.`)
        return
    }

    // Enter modify mode against the already-saved order
    cartStore.editingOrderId = o.id || cartStore.editingOrderId
    if (o.order_type) cartStore.orderType = o.order_type
    if (o.table_number !== undefined) cartStore.tableNumber = o.table_number ?? null
    if (o.customer_name !== undefined) cartStore.customerName = o.customer_name ?? ''
    if (o.customer_contact !== undefined) cartStore.customerContact = o.customer_contact ?? ''
    if (o.customer_address !== undefined) cartStore.customerAddress = o.customer_address ?? ''

    paymentOpen.value = false
    pendingOrder.value = null
    paymentDone.value = false
    completedOrder.value = null
    cartOpen.value = mobileViewport.value

    const queueOrId = o._offlineQueue ?? o.queue_number ?? o.id
    toast.info(`Add items to the cart, then press "Update Order" to continue with #${queueOrId}.`)
}

// Load a pending order back into the cart so the cashier can add/remove items
// before the customer pays. Submitting updates the existing order (Modify flow).
const modifyUnpaidOrder = (order: UnpaidOrder) => {
    if (cartStore.items.length && !confirm('Replace the current cart with this pending order? Unsaved cart changes will be discarded.')) return
    loadOrderIntoCart(order)
    toast.info(`Modifying order #${order.queue_number ?? order.id}`)
}

const loadOrderIntoCart = (order: UnpaidOrder) => {
    cartStore.clear()
    cartStore.editingOrderId = order.id
    cartStore.orderType = order.order_type
    cartStore.tableNumber = order.table_number
    cartStore.customerName = order.customer_name ?? ''
    cartStore.customerContact = order.customer_contact ?? ''
    cartStore.customerAddress = order.customer_address ?? ''
    cartStore.discount = parseFloat(order.discount_amount) || 0
    order.items.forEach((it) => {
        cartStore.items.push({
            id: it.product.id,
            product_id: it.product.id,
            name: it.product.name,
            unit_price: parseFloat(it.unit_price),
            quantity: it.quantity,
            modifiers: [],
        })
    })
    unpaidOrdersOpen.value = false
    orderTypeOpen.value = false
    cartOpen.value = mobileViewport.value
}

// Cancel (void) the just-placed order entirely.
const cancellingOrder = ref(false)
const cancelPendingOrder = async () => {
    if (paymentSubmitting.value || cancellingOrder.value) return
    const o = pendingOrder.value
    if (!o) return
    if (!confirm('Cancel this order? This cannot be undone.')) return

    cancellingOrder.value = true
    try {
        // Existing/online orders have a real server id — void them on the server.
        if (!isOfflineOrder() && o.id) {
            await api.post(`/api/v1/orders/${o.id}/cancel`, { reason: 'Cancelled at POS' })
        }
        toast.success(`Order #${o._offlineQueue ?? o.queue_number ?? o.id} cancelled.`)
        if (!(o._isExistingOrder ?? false)) cartStore.clear()
        paymentOpen.value = false
        pendingOrder.value = null
        paymentDone.value = false
        completedOrder.value = null
        loadUnpaidOrders()
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to cancel order')
    } finally {
        cancellingOrder.value = false
    }
}

const closeAndClear = () => {
    const o = completedOrder.value
    const isExisting = pendingOrder.value?._isExistingOrder ?? false
    const queueOrId = o?.queueNumber ?? o?.orderId
    // Shown at the top so it doesn't cover the cart's Place Order button.
    if (o?.paid) toast.success(`Order #${queueOrId} paid! Thank you.`, { position: 'top-center' })
    else toast.success(`Order #${queueOrId} placed. Payment pending.`, { position: 'top-center' })
    if (!isExisting) cartStore.clear()
    paymentOpen.value = false
    pendingOrder.value = null
    paymentDone.value = false
    completedOrder.value = null
    loadUnpaidOrders()
}

const printReceipt = async () => {
    if (!completedOrder.value) return
    // Primary: send to Android printing service via Pusher Channels
    try {
        await api.post('/api/v1/print-jobs', { order_id: completedOrder.value.orderId })
        toast.success('Receipt sent to printer')
        return
    } catch {
        // Fall through to browser print if Pusher service is not configured
    }
    // Fallback: browser print dialog
    try {
        await doPrint(completedOrder.value)
    } catch (err: any) {
        toast.error(err?.message ?? 'Print failed')
    }
}

const loadUnpaidOrders = async () => {
    loadingUnpaid.value = true
    try {
        const res = await api.get('/api/v1/orders', { params: { payment_status: 'pending', per_page: 50, exclude_cancelled: 1 } })
        // Handle both paginated ({ data: [...] }) and plain array responses
        const raw = res.data.data ?? res.data
        unpaidOrders.value = Array.isArray(raw) ? raw : []
    } catch (err: any) {
        console.error('[PendingOrders] fetch error', err)
        toast.error(err.response?.data?.message ?? 'Failed to load pending orders')
    } finally {
        loadingUnpaid.value = false
    }
}

const selectUnpaidOrder = async (order: UnpaidOrder) => {
    unpaidOrdersOpen.value = false
    await loadTenders()
    pendingOrder.value = {
        id: order.id,
        total_amount: parseFloat(order.total_amount),
        queue_number: order.queue_number,
        order_type: order.order_type,
        table_number: order.table_number,
        customer_name: order.customer_name,
        customer_contact: order.customer_contact,
        customer_address: order.customer_address,
        notes: order.notes,
        subtotal: parseFloat(order.subtotal),
        discount_amount: parseFloat(order.discount_amount),
        public_token: order.public_token,
        _existingItems: order.items.map(i => ({
            name: i.product.name,
            quantity: i.quantity,
            unit_price: parseFloat(i.unit_price),
        })),
        _isExistingOrder: true,
    }
    selectedTenderId.value = null
    amountTendered.value = parseFloat(order.total_amount).toFixed(2)
    reference.value = ''
    paymentOpen.value = true
}

const cancelUnpaidOrder = async (order: UnpaidOrder) => {
    if (!confirm(`Cancel order #${order.id}? This cannot be undone.`)) return
    try {
        await api.post(`/api/v1/orders/${order.id}/cancel`, { reason: 'Cancelled from pending list' })
        toast.success(`Order #${order.id} cancelled.`)
        await loadUnpaidOrders()
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to cancel order')
    }
}

const formatPrice = (val: number) => '₱' + val.toFixed(2)

watch(() => !!(cartOpen.value || paymentOpen.value || unpaidOrdersOpen.value || selectedProduct.value), open => {
    if (typeof document === 'undefined') return
    if (open) {
        originalOverflow = document.body.style.overflow
        document.body.style.overflow = 'hidden'
    } else if (originalOverflow !== null) {
        document.body.style.overflow = originalOverflow
        originalOverflow = null
    }
})
onMounted(() => { updateViewport(); loadTenders(); loadUnpaidOrders(); window.addEventListener('keydown', onPosKeydown); window.addEventListener('resize', updateViewport) })
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onPosKeydown)
    window.removeEventListener('resize', updateViewport)
    if (originalOverflow !== null) document.body.style.overflow = originalOverflow
})
</script>

<template>
    <Head title="Point of Sale" />
    <div class="pos-theme pos-page">
    <header class="pos-heading">
        <div><p class="pos-eyebrow"><Flame :size="14" aria-hidden="true" /> BYPASS GRILL / POINT OF SALE</p><h1>Let's get <em>grilling.</em></h1><p>Choose items, review the order, then collect payment.</p></div>
        <div class="pos-header-actions"><button @click="unpaidOrdersOpen = true; loadUnpaidOrders()"><ClipboardList :size="17" aria-hidden="true" />Pending payments <span>{{ unpaidOrders.length }}</span></button><InertiaLink href="/deposit-control"><Wallet :size="17" aria-hidden="true" />Deposit control<ArrowUpRight :size="14" aria-hidden="true" /></InertiaLink></div>
    </header>
    <OfflineBanner />

    <div class="pos-workspace grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
        <!-- LEFT: Product Browser -->
        <div class="pos-catalog lg:col-span-2 space-y-4 pb-24 lg:pb-0 lg:pr-1">
            <div class="pos-catalog-tools">
            <!-- Search -->
            <div class="relative">
                <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                <input
                    ref="searchInput"
                    v-model="searchQuery"
                    aria-label="Search products"
                    type="text"
                    placeholder="Search products…"
                    class="pos-search w-full rounded-lg border bg-background px-4 py-2 pl-9 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                />
                <button v-if="searchQuery" class="pos-search-clear" aria-label="Clear search" @click="searchQuery = ''; searchInput?.focus()"><X :size="16" /></button>
                <kbd v-else class="pos-search-shortcut" aria-hidden="true">/</kbd>
            </div>

            <!-- Category Tabs -->
            <div class="pos-categories flex gap-2 overflow-x-auto pb-1" aria-label="Product categories">
                <button
                    @click="selectedCategoryId = null"
                    :aria-pressed="selectedCategoryId === null"
                    :class="[
                        'shrink-0 rounded-full px-4 py-1.5 text-sm font-medium transition',
                        selectedCategoryId === null
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted text-muted-foreground hover:bg-muted/80',
                    ]"
                >
                    All
                </button>
                <button
                    v-for="cat in categories"
                    :key="cat.id"
                    @click="selectedCategoryId = cat.id"
                    :aria-pressed="selectedCategoryId === cat.id"
                    :class="[
                        'shrink-0 rounded-full px-4 py-1.5 text-sm font-medium transition',
                        selectedCategoryId === cat.id
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted text-muted-foreground hover:bg-muted/80',
                    ]"
                >
                    {{ cat.name }}
                </button>
            </div>
            <div class="pos-catalog-caption"><span>{{ filteredProducts.length }} products</span><span>Tap to add · customize items with add-ons</span></div>
            </div>

            <!-- Products Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3">
                <article
                    v-for="product in filteredProducts"
                    :key="product.id"
                    class="pos-product-card flex flex-col items-start rounded-xl border bg-card text-left transition select-none"
                    :class="{ 'is-selected': cartQtyFor(product.id) > 0 }"
                >
                    <button class="pos-product-pick" :aria-label="`${product.modifiers?.length ? 'Customize' : 'Add'} ${product.name}, ${formatPrice(product.price)}`" @click="quickAdd(product)">
                    <div class="relative mb-2 h-20 w-full rounded-lg bg-muted flex items-center justify-center overflow-hidden">
                        <img v-if="product.image" :src="product.image" alt="" loading="lazy" class="h-full w-full object-cover" />
                        <Flame v-else class="h-8 w-8 text-muted-foreground/40" aria-hidden="true" />
                        <span
                            v-if="cartQtyFor(product.id) > 0"
                            class="absolute top-1 right-1 min-w-[1.25rem] h-5 rounded-full bg-primary text-primary-foreground text-[11px] font-bold flex items-center justify-center px-1 leading-none shadow"
                        >
                            {{ cartQtyFor(product.id) }}
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground mb-0.5">{{ product.category?.name }}</p>
                    <h3 class="text-sm font-semibold leading-tight line-clamp-2">{{ product.name }}</h3>
                    <p class="mt-1 text-base font-bold text-primary">{{ formatPrice(product.price) }}</p>
                    <span class="pos-add-label"><Plus :size="13" aria-hidden="true" />{{ product.modifiers?.length ? 'Customize' : 'Add to order' }}</span>
                    </button>

                    <!-- Inline qty controls — only for products without modifiers -->
                    <div
                        v-if="cartQtyFor(product.id) > 0 && !product.modifiers?.length"
                        class="pos-product-quantity w-full flex items-center gap-1"
                        @click.stop
                    >
                        <button
                            :aria-label="`Remove one ${product.name}`"
                            class="flex-1 rounded bg-muted py-0.5 hover:bg-muted/80 flex items-center justify-center"
                            @click.stop="quickRemove(product)"
                        >
                            <Minus class="h-3.5 w-3.5" />
                        </button>
                        <span class="text-sm font-bold w-6 text-center tabular-nums">{{ cartQtyFor(product.id) }}</span>
                        <button
                            :aria-label="`Add one ${product.name}`"
                            class="flex-1 rounded bg-primary/10 py-0.5 hover:bg-primary/20 flex items-center justify-center"
                            @click.stop="quickAdd(product)"
                        >
                            <Plus class="h-3.5 w-3.5 text-primary" />
                        </button>
                    </div>
                </article>
            </div>

            <div v-if="filteredProducts.length === 0" class="pos-empty"><Search :size="28" aria-hidden="true" /><h2>No matching products</h2><p>Try another name or browse all categories.</p><button @click="selectedCategoryId = null; searchQuery = ''">Reset filters</button></div>
        </div>

        <!-- RIGHT: Cart (desktop sidebar only) -->
        <div class="pos-cart hidden lg:flex flex-col rounded-xl border bg-card overflow-hidden sticky top-4 h-[calc(100dvh-6rem)]">
            <div class="p-4 border-b flex items-center gap-2">
                <ShoppingCart class="h-5 w-5" />
                <h2 class="font-bold text-base">{{ cartStore.editingOrderId ? `Editing #${cartStore.editingOrderId}` : 'Current order' }}</h2>
            </div>

            <!-- Order type collapsible -->
            <div class="border-b">
                <button type="button" :aria-expanded="orderTypeOpen" @click="orderTypeOpen = !orderTypeOpen"
                    class="w-full flex items-center justify-between px-4 py-2.5 hover:bg-muted/30 transition-colors text-left">
                    <div class="min-w-0">
                        <span class="text-xs font-semibold text-foreground">Order Details</span>
                        <span v-if="!orderTypeOpen && orderTypeSummary"
                            class="ml-2 text-xs text-muted-foreground truncate">{{ orderTypeSummary }}</span>
                    </div>
                    <ChevronDown class="h-3.5 w-3.5 text-muted-foreground flex-shrink-0 transition-transform duration-200"
                        :class="orderTypeOpen ? 'rotate-180' : ''" />
                </button>
                <div v-show="orderTypeOpen" class="px-4 pb-4 space-y-3">
                    <div>
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Order Type</label>
                        <select
                            :value="cartStore.orderType"
                            @change="(e) => cartStore.orderType = (e.target as HTMLSelectElement).value"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            :class="{ 'text-muted-foreground': !cartStore.orderType }"
                        >
                            <option value="" disabled>Select order type…</option>
                            <option value="dine_in">Dine In</option>
                            <option value="takeout">Takeout</option>
                            <option value="delivery">Delivery</option>
                        </select>
                    </div>
                    <div v-if="cartStore.orderType === 'dine_in'">
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Table Number</label>
                        <input
                            :value="cartStore.tableNumber"
                            @input="(e) => cartStore.tableNumber = (e.target as HTMLInputElement).value"
                            type="text"
                            placeholder="e.g. Table 5"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                        />
                    </div>
                    <div v-if="cartStore.orderType === 'dine_in'">
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Customer Name</label>
                        <input
                            v-model="cartStore.customerName"
                            type="text"
                            placeholder="e.g. Juan"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                        />
                    </div>
                    <div v-if="cartStore.orderType === 'takeout'">
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Customer Name / Alias</label>
                        <input
                            v-model="cartStore.customerName"
                            type="text"
                            placeholder="e.g. Juan, Table 2"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                        />
                    </div>
                    <template v-if="cartStore.orderType === 'delivery'">
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1">Customer Name / Alias</label>
                            <input
                                v-model="cartStore.customerName"
                                type="text"
                                placeholder="Full name or alias"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1">Contact Number</label>
                            <input
                                v-model="cartStore.customerContact"
                                type="text"
                                placeholder="e.g. 09XX XXX XXXX"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1">Delivery Address</label>
                            <textarea
                                v-model="cartStore.customerAddress"
                                rows="2"
                                placeholder="Street, barangay, city…"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary resize-none"
                            />
                        </div>
                    </template>
                </div>
            </div>

            <!-- Cart Items -->
            <div class="flex-1 overflow-y-auto p-4 space-y-3 min-h-0">
                <div v-if="cartStore.items.length === 0" class="text-center text-muted-foreground text-sm py-10">
                    Your order starts here. Tap a product to add it.
                </div>
                <div v-for="item in cartStore.items" :key="item.id" class="pos-cart-line flex gap-2 items-start rounded-lg border bg-background p-3">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold truncate">{{ item.name }}</p>
                        <p class="text-xs text-muted-foreground">{{ formatPrice(item.unit_price) }} each</p>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                        <button :aria-label="`Decrease ${item.name} quantity`" :disabled="item.quantity <= 1" @click="cartStore.updateQuantity(item.id, item.quantity - 1)" class="rounded bg-muted p-0.5 hover:bg-muted/80">
                            <Minus class="h-3 w-3" />
                        </button>
                        <span class="w-6 text-center text-sm font-bold">{{ item.quantity }}</span>
                        <button :aria-label="`Increase ${item.name} quantity`" @click="cartStore.updateQuantity(item.id, item.quantity + 1)" class="rounded bg-muted p-0.5 hover:bg-muted/80">
                            <Plus class="h-3 w-3" />
                        </button>
                        <button :aria-label="`Remove ${item.name} from order`" @click="cartStore.removeItem(item.id)" class="ml-1 rounded bg-destructive/10 p-0.5 text-destructive hover:bg-destructive/20">
                            <X class="h-3 w-3" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Totals + Discount -->
            <div class="p-4 border-t space-y-2 bg-muted/30">
                <div class="flex items-center gap-2">
                    <label class="text-xs text-muted-foreground w-24 shrink-0">Discount (₱)</label>
                    <input
                        :value="cartStore.discount"
                        @input="cartStore.setDiscount(parseFloat(($event.target as HTMLInputElement).value) || 0)"
                        type="number" min="0" step="0.01"
                        class="flex-1 rounded-lg border bg-background px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                    />
                </div>
                <div class="space-y-1 text-sm pt-1">
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Subtotal</span>
                        <span>{{ formatPrice(cartStore.subtotal) }}</span>
                    </div>
                    <div class="flex justify-between text-red-600">
                        <span>Discount</span>
                        <span>-{{ formatPrice(cartStore.discount) }}</span>
                    </div>
                    <div class="flex justify-between font-bold text-base border-t pt-1 mt-1">
                        <span>TOTAL</span>
                        <span class="text-primary">{{ formatPrice(cartStore.total) }}</span>
                    </div>
                </div>
            </div>

            <div class="p-4 space-y-2">
                <p class="pos-order-hint" role="status">{{ orderHint }}</p>
                <button
                    @click="submitOrder"
                    :disabled="cartStore.items.length === 0 || submitting"
                    class="w-full rounded-lg bg-primary py-3 text-sm font-bold text-primary-foreground transition hover:bg-primary/90 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <template v-if="submitting">{{ cartStore.editingOrderId ? 'Updating Order…' : 'Placing Order…' }}</template>
                    <template v-else>{{ cartStore.editingOrderId ? 'Update Order' : 'Place Order' }} — {{ formatPrice(cartStore.total) }}</template>
                </button>
                <button
                    @click="clearCart"
                    class="w-full rounded-lg border bg-background py-2 text-sm font-medium transition hover:bg-muted"
                >
                    Clear Cart
                </button>
            </div>
        </div>
    </div>
    </div>

    <!-- Mobile: Floating Cart Button + Drawer -->
    <Teleport to="body">
        <!-- FAB -->
        <button
            @click="cartOpen = true"
            class="pos-theme pos-mobile-cart-button fixed bottom-6 right-6 z-30 lg:hidden flex items-center gap-2 rounded-full bg-primary px-4 py-3 text-primary-foreground shadow-lg hover:bg-primary/90 transition-all"
        >
            <ShoppingCart class="h-5 w-5" />
            <span class="text-sm font-bold">Review order</span>
            <span v-if="cartStore.items.length > 0" class="text-sm font-bold">{{ cartItemCount }}</span>
            <span v-if="cartStore.items.length > 0" class="pos-fab-total">{{ formatPrice(cartStore.total) }}</span>
        </button>

        <!-- Backdrop -->
        <Transition name="fade">
            <div v-if="cartOpen" class="pos-theme fixed inset-0 z-40 bg-black/50 lg:hidden" @click="cartOpen = false" />
        </Transition>

        <!-- Drawer -->
        <Transition name="drawer">
            <FocusScope as="div" loop trapped role="dialog" aria-modal="true" aria-label="Current order" @keydown.esc.stop.prevent="cartOpen = false" v-if="cartOpen" class="pos-theme pos-mobile-drawer fixed inset-y-0 right-0 z-50 w-80 flex flex-col bg-card shadow-2xl lg:hidden">
                <div class="p-4 border-b flex items-center gap-2">
                    <ShoppingCart class="h-5 w-5" />
                    <h2 class="font-bold text-base flex-1">{{ cartStore.editingOrderId ? `Editing #${cartStore.editingOrderId}` : 'Current order' }}</h2>
                    <button
                        @click="cartOpen = false; unpaidOrdersOpen = true; loadUnpaidOrders()"
                        class="pos-pending-pill"
                        :aria-label="`Pending payments: ${unpaidOrders.length}`"
                    >
                        <ClipboardList class="h-3 w-3" />
                        Pending
                        <span>{{ unpaidOrders.length }}</span>
                    </button>
                    <button aria-label="Close current order" @click="cartOpen = false" class="ml-1 rounded-full p-1 hover:bg-muted">
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <!-- Order type collapsible (mobile) -->
                <div class="border-b">
                    <button type="button" :aria-expanded="orderTypeOpen" @click="orderTypeOpen = !orderTypeOpen"
                        class="w-full flex items-center justify-between px-4 py-2.5 hover:bg-muted/30 transition-colors text-left">
                        <div class="min-w-0">
                            <span class="text-xs font-semibold text-foreground">Order Details</span>
                            <span v-if="!orderTypeOpen && orderTypeSummary"
                                class="ml-2 text-xs text-muted-foreground truncate">{{ orderTypeSummary }}</span>
                        </div>
                        <ChevronDown class="h-3.5 w-3.5 text-muted-foreground flex-shrink-0 transition-transform duration-200"
                            :class="orderTypeOpen ? 'rotate-180' : ''" />
                    </button>
                    <div v-show="orderTypeOpen" class="px-4 pb-4 space-y-3">
                    <div>
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Order Type</label>
                        <select
                            :value="cartStore.orderType"
                            @change="(e) => cartStore.orderType = (e.target as HTMLSelectElement).value"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            :class="{ 'text-muted-foreground': !cartStore.orderType }"
                        >
                            <option value="" disabled>Select order type…</option>
                            <option value="dine_in">Dine In</option>
                            <option value="takeout">Takeout</option>
                            <option value="delivery">Delivery</option>
                        </select>
                    </div>
                    <div v-if="cartStore.orderType === 'dine_in'">
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Table Number</label>
                        <input
                            :value="cartStore.tableNumber"
                            @input="(e) => cartStore.tableNumber = (e.target as HTMLInputElement).value"
                            type="text" placeholder="e.g. Table 5"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                        />
                    </div>
                    <div v-if="cartStore.orderType === 'dine_in'">
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Customer Name</label>
                        <input
                            v-model="cartStore.customerName"
                            type="text"
                            placeholder="e.g. Juan"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                        />
                    </div>
                    <div v-if="cartStore.orderType === 'takeout'">
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Customer Name / Alias</label>
                        <input
                            v-model="cartStore.customerName"
                            type="text"
                            placeholder="e.g. Juan, Table 2"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                        />
                    </div>
                    <template v-if="cartStore.orderType === 'delivery'">
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1">Customer Name / Alias</label>
                            <input
                                v-model="cartStore.customerName"
                                type="text"
                                placeholder="Full name or alias"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1">Contact Number</label>
                            <input
                                v-model="cartStore.customerContact"
                                type="text"
                                placeholder="e.g. 09XX XXX XXXX"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1">Delivery Address</label>
                            <textarea
                                v-model="cartStore.customerAddress"
                                rows="2"
                                placeholder="Street, barangay, city…"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary resize-none"
                            />
                        </div>
                    </template>
                    </div><!-- /v-show orderTypeOpen -->
                </div><!-- /collapsible border-b -->

                <div class="flex-1 overflow-y-auto p-4 space-y-3 min-h-0">
                    <div v-if="cartStore.items.length === 0" class="text-center text-muted-foreground text-sm py-10">
                        Your order starts here. Tap a product to add it.
                    </div>
                    <div v-for="item in cartStore.items" :key="item.id" class="pos-cart-line flex gap-2 items-start rounded-lg border bg-background p-3">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold truncate">{{ item.name }}</p>
                            <p class="text-xs text-muted-foreground">{{ formatPrice(item.unit_price) }} each</p>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            <button :aria-label="`Decrease ${item.name} quantity`" :disabled="item.quantity <= 1" @click="cartStore.updateQuantity(item.id, item.quantity - 1)" class="rounded bg-muted p-0.5 hover:bg-muted/80">
                                <Minus class="h-3 w-3" />
                            </button>
                            <span class="w-6 text-center text-sm font-bold">{{ item.quantity }}</span>
                            <button :aria-label="`Increase ${item.name} quantity`" @click="cartStore.updateQuantity(item.id, item.quantity + 1)" class="rounded bg-muted p-0.5 hover:bg-muted/80">
                                <Plus class="h-3 w-3" />
                            </button>
                            <button :aria-label="`Remove ${item.name} from order`" @click="cartStore.removeItem(item.id)" class="ml-1 rounded bg-destructive/10 p-0.5 text-destructive hover:bg-destructive/20">
                                <X class="h-3 w-3" />
                            </button>
                        </div>
                    </div>
                </div>

                <div class="p-4 border-t space-y-2 bg-muted/30">
                    <div class="flex items-center gap-2">
                        <label class="text-xs text-muted-foreground w-24 shrink-0">Discount (₱)</label>
                        <input
                            :value="cartStore.discount"
                            @input="cartStore.setDiscount(parseFloat(($event.target as HTMLInputElement).value) || 0)"
                            type="number" min="0" step="0.01"
                            class="flex-1 rounded-lg border bg-background px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                        />
                    </div>
                    <div class="space-y-1 text-sm pt-1">
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">Subtotal</span>
                            <span>{{ formatPrice(cartStore.subtotal) }}</span>
                        </div>
                        <div class="flex justify-between text-red-600">
                            <span>Discount</span>
                            <span>-{{ formatPrice(cartStore.discount) }}</span>
                        </div>
                        <div class="flex justify-between font-bold text-base border-t pt-1 mt-1">
                            <span>TOTAL</span>
                            <span class="text-primary">{{ formatPrice(cartStore.total) }}</span>
                        </div>
                    </div>
                </div>

                <div class="p-4 space-y-2">
                    <p class="pos-order-hint" role="status">{{ orderHint }}</p>
                    <button
                        @click="submitOrder"
                        :disabled="cartStore.items.length === 0 || submitting"
                        class="w-full rounded-lg bg-primary py-3 text-sm font-bold text-primary-foreground transition hover:bg-primary/90 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {{ submitting ? (cartStore.editingOrderId ? 'Updating order…' : 'Placing order…') : `${cartStore.editingOrderId ? 'Update order' : 'Place order'} — ${formatPrice(cartStore.total)}` }}
                    </button>
                    <button @click="clearCart" class="w-full rounded-lg border bg-background py-2 text-sm font-medium transition hover:bg-muted">
                        Clear Cart
                    </button>
                </div>
            </FocusScope>
        </Transition>
    </Teleport>

    <!-- Payment Modal -->
    <Teleport to="body">
        <Transition name="fade">
            <FocusScope as="div" loop trapped role="dialog" aria-modal="true" aria-label="Collect payment" @keydown.esc.stop.prevent="paymentDone ? closeAndClear() : holdOrder()"
                v-if="paymentOpen && pendingOrder"
                class="pos-theme fixed inset-0 z-50 flex flex-col sm:items-center sm:justify-center sm:bg-black/60 bg-background sm:p-4 overflow-y-auto sm:overflow-hidden"
            >
                <div class="pos-payment-panel w-full flex-1 sm:flex-none sm:max-w-sm sm:rounded-2xl bg-background sm:shadow-2xl sm:overflow-hidden sm:max-h-[90vh] sm:flex sm:flex-col">

                    <!-- ── SUCCESS STATE ────────────────────────────────── -->
                    <template v-if="paymentDone && completedOrder">
                        <div class="p-6 text-center">
                            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-green-100 mx-auto mb-3">
                                <CheckCircle2 class="h-8 w-8 text-green-600" />
                            </div>
                            <h3 class="text-lg font-black">{{ completedOrder.paid ? 'Payment Complete' : 'Order Placed' }}</h3>
                            <p class="text-sm text-muted-foreground mt-0.5">
                                {{ completedOrder.queueNumber ? 'Queue #' + completedOrder.queueNumber : 'Order #' + completedOrder.orderId }}
                            </p>

                            <!-- Change display -->
                            <div v-if="completedOrder.paid && completedOrder.change > 0"
                                class="mt-4 rounded-xl bg-green-50 border border-green-200 px-5 py-3">
                                <p class="text-xs text-muted-foreground uppercase tracking-wider mb-0.5">Change</p>
                                <p class="text-4xl font-black text-green-600">{{ formatPrice(completedOrder.change) }}</p>
                            </div>
                            <div v-else-if="!completedOrder.paid"
                                class="mt-4 rounded-xl bg-yellow-50 border border-yellow-200 px-5 py-3">
                                <p class="text-sm font-semibold text-yellow-700">Payment Pending</p>
                                <p class="text-xl font-black">{{ formatPrice(completedOrder.total) }}</p>
                            </div>

                            <!-- Mini order summary -->
                            <div class="mt-4 rounded-xl bg-muted/40 p-3 text-left space-y-0.5">
                                <p v-for="item in completedOrder.items" :key="item.name" class="text-xs">
                                    <span class="font-semibold">{{ item.quantity }}×</span> {{ item.name }}
                                    <span class="text-muted-foreground float-right">{{ formatPrice(item.unit_price * item.quantity) }}</span>
                                </p>
                                <div class="border-t mt-1 pt-1 flex justify-between text-xs font-bold">
                                    <span>TOTAL</span><span>{{ formatPrice(completedOrder.total) }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="p-5 border-t space-y-2">
                            <button
                                @click="printReceipt"
                                class="w-full rounded-lg bg-primary py-3 text-sm font-bold text-primary-foreground hover:bg-primary/90 transition flex items-center justify-center gap-2"
                            >
                                <Printer class="h-4 w-4" /> Print Receipt
                            </button>
                            <button
                                v-if="completedOrder?.publicToken"
                                @click="copyPublicLink"
                                class="w-full rounded-lg border py-2.5 text-sm font-medium hover:bg-muted transition flex items-center justify-center gap-2"
                                :class="linkCopied ? 'text-green-600 border-green-400 bg-green-50' : ''"
                            >
                                <Check v-if="linkCopied" class="h-4 w-4" />
                                <Copy v-else class="h-4 w-4" />
                                {{ linkCopied ? 'Link Copied!' : 'Copy Receipt Link' }}
                            </button>
                            <button
                                @click="closeAndClear"
                                class="w-full rounded-lg border bg-background py-2 text-sm font-medium hover:bg-muted transition"
                            >
                                Done
                            </button>
                        </div>
                    </template>

                    <!-- ── PAYMENT FORM ─────────────────────────────────── -->
                    <template v-else>
                        <!-- Header -->
                        <div class="p-5 border-b flex items-center gap-3 sm:shrink-0">
                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-green-100">
                                <CreditCard class="h-5 w-5 text-green-600" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-bold">Collect Payment</h3>
                                <p class="text-xs text-muted-foreground">Order #{{ pendingOrder._offlineQueue ?? pendingOrder.queue_number ?? pendingOrder.id }}</p>
                            </div>
                            <!-- Hold: close temporarily, order stays pending so they can add more -->
                            <button :disabled="paymentSubmitting || cancellingOrder" @click="holdOrder" title="Hold — close & add more later"
                                class="rounded-full p-1.5 hover:bg-muted text-muted-foreground transition shrink-0">
                                <X class="h-5 w-5" />
                            </button>
                        </div>

                        <div class="p-5 space-y-5 sm:flex-1 sm:overflow-y-auto">
                            <!-- Total due -->
                            <div class="rounded-xl bg-primary/5 border border-primary/20 p-4 text-center">
                                <p class="text-xs text-muted-foreground mb-1 uppercase tracking-wider">Amount Due</p>
                                <p class="text-4xl font-black text-primary">{{ formatPrice(pendingOrder.total_amount) }}</p>
                            </div>

                            <!-- Tender selection -->
                            <div>
                                <p class="text-xs font-medium text-muted-foreground mb-2 uppercase tracking-wider">Payment Method</p>
                                <div class="grid grid-cols-2 gap-2">
                                    <button
                                        v-for="tender in tenders"
                                        :key="tender.id"
                                        :aria-pressed="selectedTenderId === tender.id"
                                        @click="selectedTenderId = tender.id"
                                        :class="[
                                            'flex items-center gap-2 rounded-lg border px-3 py-2.5 text-sm font-medium transition',
                                            selectedTenderId === tender.id
                                                ? 'border-primary bg-primary/10 text-primary'
                                                : 'border-border bg-background hover:border-primary/50 hover:bg-muted/50',
                                        ]"
                                    >
                                        <Banknote class="h-4 w-4 shrink-0" />
                                        {{ tender.name }}
                                    </button>
                                </div>
                                <p v-if="tenders.length === 0" class="text-sm text-muted-foreground text-center py-2">
                                    No payment tenders configured.
                                </p>
                            </div>

                            <!-- Amount tendered -->
                            <div>
                                <label for="pos-amount-tendered" class="text-xs font-medium text-muted-foreground block mb-1 uppercase tracking-wider">Amount received (PHP)</label>
                                <input
                                    id="pos-amount-tendered"
                                    v-model="amountTendered"
                                    inputmode="decimal"
                                    type="number" min="0" step="0.01"
                                    :placeholder="pendingOrder.total_amount.toFixed(2)"
                                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                />
                                <div class="pos-cash-shortcuts"><button @click="amountTendered = pendingOrder.total_amount.toFixed(2)">Exact amount</button><button v-for="amount in cashAmounts" :key="amount" @click="amountTendered = amount.toFixed(2)">{{ formatPrice(amount) }}</button></div>
                            </div>

                            <!-- Change / Short-by -->
                            <div v-if="change >= 0 && amountTendered !== ''" class="flex justify-between items-center rounded-lg bg-green-50 border border-green-200 px-4 py-3">
                                <span class="text-sm font-medium text-green-700">Change</span>
                                <span class="text-lg font-black text-green-600">{{ formatPrice(change) }}</span>
                            </div>
                            <div v-else-if="change < 0 && amountTendered !== ''" class="flex justify-between items-center rounded-lg bg-red-50 border border-red-200 px-4 py-3">
                                <span class="text-sm font-medium text-red-600">Short by</span>
                                <span class="text-lg font-black text-red-600">{{ formatPrice(Math.abs(change)) }}</span>
                            </div>

                            <!-- Reference -->
                            <div>
                                <label for="pos-payment-reference" class="text-xs font-medium text-muted-foreground block mb-1">Reference # (optional)</label>
                                <input
                                    id="pos-payment-reference"
                                    v-model="reference"
                                    type="text"
                                    placeholder="e.g. GCash ref, card last 4"
                                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                />
                            </div>
                        </div>

                        <div class="p-5 border-t space-y-2 sm:shrink-0">
                            <button
                                @click="submitPayment"
                                :disabled="!paymentReady"
                                class="w-full rounded-lg bg-primary py-3 text-sm font-bold text-white hover:bg-primary/90 disabled:opacity-50 disabled:cursor-not-allowed transition"
                            >
                                {{ paymentSubmitting ? 'Processing…' : 'Confirm Payment' }}
                            </button>
                            <p v-if="!paymentReady && !paymentSubmitting" class="pos-order-hint" role="status">{{ !selectedTenderId ? 'Select a payment method to continue.' : 'Enter an amount covering the total, with up to two decimal places.' }}</p>
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    :disabled="paymentSubmitting || cancellingOrder" @click="skipPayment"
                                    class="rounded-lg border bg-background py-2 text-sm font-medium hover:bg-muted transition"
                                >
                                    Skip — Pay Later
                                </button>
                                <button
                                    :disabled="paymentSubmitting || cancellingOrder" @click="holdOrder"
                                    class="rounded-lg border bg-background py-2 text-sm font-medium hover:bg-muted transition"
                                >
                                    Hold — Add More
                                </button>
                            </div>
                            <button
                                @click="cancelPendingOrder"
                                :disabled="cancellingOrder || paymentSubmitting"
                                class="w-full rounded-lg border border-red-200 text-red-600 py-2 text-sm font-medium hover:bg-red-50 disabled:opacity-50 transition"
                            >
                                {{ cancellingOrder ? 'Cancelling…' : 'Cancel Order' }}
                            </button>
                        </div>
                    </template>

                </div>
            </FocusScope>
        </Transition>
    </Teleport>

    <!-- Unpaid Orders Panel -->
    <Teleport to="body">
        <Transition name="fade">
            <FocusScope as="div" loop trapped role="dialog" aria-modal="true" aria-label="Pending payments" @keydown.esc.stop.prevent="unpaidOrdersOpen = false"
                v-if="unpaidOrdersOpen"
                class="pos-theme fixed inset-0 z-50 flex flex-col sm:items-center sm:justify-center sm:bg-black/60 bg-background sm:p-4"
                @click.self="unpaidOrdersOpen = false"
            >
                <div class="pos-pending-panel flex flex-col flex-1 sm:flex-none w-full sm:max-w-lg sm:rounded-2xl bg-background sm:shadow-2xl overflow-hidden">
                    <div class="p-4 border-b flex items-center gap-3">
                        <ClipboardList class="h-5 w-5 text-amber-500" />
                        <h3 class="font-bold text-base flex-1">Pending Payments</h3>
                        <button aria-label="Close pending payments" @click="unpaidOrdersOpen = false" class="rounded-full p-1 hover:bg-muted">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="p-4 border-b"><label for="pos-unpaid-search" class="sr-only">Search pending payments</label><input id="pos-unpaid-search" v-model="unpaidSearch" type="search" placeholder="Search order, queue, customer, or table" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div class="flex-1 overflow-y-auto">
                        <div v-if="loadingUnpaid" class="text-center py-10 text-sm text-muted-foreground">
                            Loading…
                        </div>
                        <div v-else-if="unpaidOrders.length === 0" class="text-center py-10 text-sm text-muted-foreground">
                            No pending orders
                        </div>
                        <div v-else-if="filteredUnpaidOrders.length === 0" class="text-center py-10 text-sm text-muted-foreground">No matching pending payments.</div>
                        <div v-else class="divide-y">
                            <div
                                v-for="order in filteredUnpaidOrders"
                                :key="order.id"
                                class="flex items-center gap-3 px-4 py-3 hover:bg-muted/30 transition"
                            >
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-sm font-bold">#{{ order.id }}</span>
                                        <span class="text-xs bg-muted rounded-full px-2 py-0.5 capitalize">
                                            {{ order.order_type.replace('_', ' ') }}
                                        </span>
                                        <span v-if="order.table_number" class="text-xs text-muted-foreground">{{ order.table_number }}</span>
                                    </div>
                                    <p v-if="order.customer_name" class="text-xs text-muted-foreground truncate mt-0.5">{{ order.customer_name }}</p>
                                    <p class="text-xs text-muted-foreground truncate mt-0.5">
                                        {{ (order.items ?? []).map(i => `${i.quantity}× ${i.product?.name ?? '?'}`).join(', ') }}
                                    </p>
                                    <p class="text-xs text-muted-foreground mt-0.5">
                                        {{ new Date(order.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
                                    </p>
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="font-bold text-primary">{{ formatPrice(parseFloat(order.total_amount)) }}</p>
                                    <div class="mt-1.5 flex items-center gap-1.5 justify-end flex-wrap">
                                        <button
                                            @click="modifyUnpaidOrder(order)"
                                            class="rounded-lg border px-3 py-1.5 text-xs font-bold hover:bg-muted transition"
                                        >
                                            Modify
                                        </button>
                                        <button
                                            @click="selectUnpaidOrder(order)"
                                            class="rounded-lg bg-primary px-3 py-1.5 text-xs font-bold text-white hover:bg-primary/90 transition"
                                        >
                                            Pay Now
                                        </button>
                                        <button
                                            @click="cancelUnpaidOrder(order)"
                                            class="rounded-lg border border-red-200 text-red-600 px-3 py-1.5 text-xs font-bold hover:bg-red-50 transition"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </FocusScope>
        </Transition>
    </Teleport>

    <!-- Product Modal -->
    <Teleport to="body">
        <Transition name="fade">
            <FocusScope as="div" loop trapped role="dialog" aria-modal="true" aria-label="Customize product" @keydown.esc.stop.prevent="selectedProduct = null"
                v-if="selectedProduct"
                class="pos-theme fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                @click.self="selectedProduct = null"
            >
                <div class="pos-product-modal w-full max-w-md rounded-2xl bg-background shadow-2xl">
                    <div class="p-5 border-b flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-bold">{{ selectedProduct.name }}</h3>
                            <p class="text-sm text-muted-foreground">{{ selectedProduct.description }}</p>
                        </div>
                        <button aria-label="Close product details" @click="selectedProduct = null" class="ml-2 rounded-full p-1 hover:bg-muted">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="p-5 space-y-4">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium">Quantity</span>
                            <div class="flex items-center gap-2">
                                <button aria-label="Decrease product quantity" :disabled="productQty <= 1" @click="productQty = Math.max(1, productQty - 1)" class="rounded bg-muted p-1 hover:bg-muted/80">
                                    <Minus class="h-4 w-4" />
                                </button>
                                <span class="w-8 text-center font-bold">{{ productQty }}</span>
                                <button aria-label="Increase product quantity" @click="productQty++" class="rounded bg-muted p-1 hover:bg-muted/80">
                                    <Plus class="h-4 w-4" />
                                </button>
                            </div>
                        </div>

                        <div v-if="selectedProduct.modifiers?.length > 0">
                            <p class="text-sm font-medium mb-2">Add-ons</p>
                            <div class="space-y-2">
                                <label
                                    v-for="mod in selectedProduct.modifiers"
                                    :key="mod.id"
                                    class="flex items-center gap-3 rounded-lg border bg-muted/30 p-2 cursor-pointer hover:bg-muted/50"
                                >
                                    <input type="checkbox" :value="mod.id" v-model="selectedModifiers" class="rounded" />
                                    <span class="flex-1 text-sm">{{ mod.name }}</span>
                                    <span class="text-sm font-medium text-primary">+{{ formatPrice(mod.price) }}</span>
                                </label>
                            </div>
                        </div>

                        <div class="rounded-lg bg-muted/40 p-3 flex justify-between items-center">
                            <span class="text-sm text-muted-foreground">Item total</span>
                            <span class="font-bold text-primary">
                                {{ formatPrice((selectedProduct.price + modifierTotal) * productQty) }}
                            </span>
                        </div>
                    </div>
                    <div class="p-5 border-t flex gap-3">
                        <button @click="selectedProduct = null" class="flex-1 rounded-lg border py-2 text-sm font-medium hover:bg-muted">
                            Cancel
                        </button>
                        <button @click="addToCart" class="flex-1 rounded-lg bg-primary py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90">
                            Add to Cart
                        </button>
                    </div>
                </div>
            </FocusScope>
        </Transition>
    </Teleport>
</template>

<style scoped>
/* Grill theme tokens: the Tailwind utilities on this page (and its teleported
   drawer and modals) resolve through these, matching the dashboard palette.
   Like the dashboard, the POS stays light regardless of the app appearance. */
.pos-theme {
    --background: #fffcf6;
    --foreground: #24231e;
    --card: #fffcf6;
    --card-foreground: #24231e;
    --popover: #fffcf6;
    --popover-foreground: #24231e;
    --primary: #c3441c;
    --primary-foreground: #fff;
    --secondary: #efeadf;
    --secondary-foreground: #24231e;
    --muted: #efeadf;
    --muted-foreground: #68665f;
    --accent: #efeadf;
    --accent-foreground: #24231e;
    --destructive: #b3361f;
    --destructive-foreground: #fff;
    --border: #ded7cb;
    --input: #d4cdbf;
    --ring: #ad3b19;
    color: #24231e;
    color-scheme: light;
    font-family: Arial, Helvetica, sans-serif;
}
.pos-theme :focus-visible {
    outline: 2px solid #ad3b19;
    outline-offset: 2px;
}
.pos-theme button:not(:disabled) {
    cursor: pointer;
}

/* Page + heading */
.pos-page {
    background: #f6f2e9;
    min-height: 100%;
    padding: 30px clamp(16px, 3vw, 40px) 40px;
}
.pos-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: 18px 24px;
    margin-bottom: 22px;
}
.pos-eyebrow {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1.6px;
    color: #ad3b19;
}
.pos-heading h1 {
    font-size: clamp(30px, 3.2vw, 44px);
    font-weight: 850;
    letter-spacing: -1.6px;
    line-height: 1.1;
    margin: 10px 0 8px;
}
.pos-heading h1 em {
    font-family: Georgia, serif;
    font-weight: 400;
    color: #ad3b19;
}
.pos-heading p:not(.pos-eyebrow) {
    font-size: 13px;
    line-height: 1.6;
    color: #68665f;
}
.pos-header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.pos-header-actions > * {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 4px;
    padding: 11px 14px;
    font-size: 12px;
    font-weight: 800;
    white-space: nowrap;
    transition: background 0.15s;
}
.pos-header-actions > button {
    background: #24231e;
    color: #f6f2e9;
}
.pos-header-actions > button:hover {
    background: #3a382f;
}
.pos-header-actions > button span {
    min-width: 22px;
    border-radius: 20px;
    padding: 2px 7px;
    background: #c3441c;
    color: #fff;
    font-size: 10px;
}
.pos-header-actions > a {
    border: 1px solid #d4cdbf;
    background: #fffcf6;
    color: #24231e;
}
.pos-header-actions > a:hover {
    background: #efeadf;
}

/* Catalog */
.pos-catalog-tools {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 14px;
    background: #fffcf6;
    border: 1px solid #ded7cb;
    border-radius: 6px;
}
.pos-search {
    height: 44px;
    padding-right: 42px;
    background: #fff;
    border-color: #d4cdbf;
    border-radius: 4px;
    font-size: 14px;
}
.pos-search-clear,
.pos-search-shortcut {
    position: absolute;
    top: 50%;
    right: 10px;
    transform: translateY(-50%);
}
.pos-search-clear {
    display: grid;
    place-items: center;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    color: #68665f;
}
.pos-search-clear:hover {
    background: #efeadf;
}
.pos-search-shortcut {
    border: 1px solid #d4cdbf;
    border-radius: 3px;
    padding: 1px 7px;
    background: #f6f2e9;
    color: #777268;
    font-family: inherit;
    font-size: 11px;
    font-weight: 700;
}
.pos-categories button {
    border-radius: 4px;
    padding: 8px 14px;
    font-size: 12px;
    font-weight: 700;
}
.pos-categories button[aria-pressed='false'] {
    background: #efeadf;
    color: #575144;
}
.pos-categories button[aria-pressed='false']:hover {
    background: #e4dccd;
    color: #24231e;
}
.pos-catalog-caption {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 4px 12px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.3px;
    color: #777268;
}
.pos-product-card {
    overflow: hidden;
    border-color: #ded7cb;
    border-radius: 6px;
    background: #fffcf6;
    transition:
        border-color 0.15s,
        box-shadow 0.15s;
}
.pos-product-card:hover {
    border-color: #e4c4ab;
    box-shadow: 0 8px 20px -14px #24231e80;
}
.pos-product-card.is-selected {
    border-color: #c3441c;
    box-shadow: inset 0 0 0 1px #c3441c;
    background: #fdf6ee;
}
.pos-product-pick {
    display: flex;
    flex: 1;
    flex-direction: column;
    align-items: flex-start;
    width: 100%;
    padding: 12px;
    text-align: left;
    transition: transform 0.1s;
}
.pos-product-pick:active {
    transform: scale(0.98);
}
.pos-product-pick h3 {
    font-weight: 800;
    color: #24231e;
}
.pos-add-label {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 8px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.3px;
    color: #ad3b19;
}
.pos-product-quantity {
    padding: 0 12px 12px;
}
.pos-product-quantity button {
    padding: 5px 0;
}
.pos-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 42px 16px;
    border: 1px dashed #d4cdbf;
    border-radius: 6px;
    background: #fffcf6;
    text-align: center;
    color: #777268;
}
.pos-empty > svg {
    color: #ad3b19;
}
.pos-empty h2 {
    font-size: 15px;
    font-weight: 800;
    color: #24231e;
}
.pos-empty p {
    font-size: 12px;
}
.pos-empty button {
    margin-top: 4px;
    font-size: 12px;
    font-weight: 800;
    color: #ad3b19;
}
.pos-empty button:hover {
    text-decoration: underline;
    text-underline-offset: 4px;
}

/* Cart (desktop panel + mobile drawer) */
.pos-cart {
    border-color: #ded7cb;
    border-radius: 6px;
    background: #fffcf6;
}
.pos-cart h2,
.pos-mobile-drawer h2 {
    font-weight: 800;
    letter-spacing: -0.3px;
}
.pos-cart-line {
    border-color: #ece5da;
    border-radius: 5px;
    background: #fff;
}
.pos-cart-line button:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.pos-order-hint {
    font-size: 11px;
    line-height: 1.5;
    text-align: center;
    color: #777268;
}
.pos-pending-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid #e6d09b;
    border-radius: 20px;
    padding: 3px 9px;
    background: #fbf4e2;
    color: #7b5815;
    font-size: 11px;
    font-weight: 700;
    transition: background 0.15s;
}
.pos-pending-pill:hover {
    background: #f7eccf;
}
.pos-pending-pill span {
    border-radius: 20px;
    padding: 1px 6px;
    background: #c79a2e;
    color: #fff;
    font-size: 10px;
}
.pos-mobile-cart-button {
    left: 16px;
    right: 16px;
    bottom: 16px;
    justify-content: space-between;
    border-radius: 6px;
    padding: 14px 18px;
    background: #24231e;
    color: #f6f2e9;
    box-shadow: 0 12px 28px -12px #24231ecc;
}
.pos-mobile-cart-button:hover {
    background: #3a382f;
}
.pos-fab-total {
    margin-left: auto;
    border-radius: 4px;
    padding: 4px 8px;
    background: #c3441c;
    color: #fff;
    font-size: 13px;
}
.pos-mobile-drawer {
    width: min(24rem, 100vw);
    background: #fffcf6;
}

/* Modals */
.pos-payment-panel,
.pos-pending-panel {
    background: #fffcf6;
}
.pos-product-modal {
    border-radius: 8px;
    background: #fffcf6;
}
.pos-payment-panel h3,
.pos-pending-panel h3,
.pos-product-modal h3 {
    font-weight: 800;
    letter-spacing: -0.3px;
}
.pos-cash-shortcuts {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}
.pos-cash-shortcuts button {
    border: 1px solid #d4cdbf;
    border-radius: 20px;
    padding: 6px 11px;
    background: #fff;
    font-size: 11px;
    font-weight: 700;
    color: #24231e;
    transition:
        background 0.15s,
        color 0.15s;
}
.pos-cash-shortcuts button:hover {
    border-color: #e4c4ab;
    background: #f2e5d8;
    color: #a23817;
}
@media (min-width: 640px) {
    .pos-payment-panel,
    .pos-pending-panel {
        border-radius: 8px;
    }
}
@media (max-width: 640px) {
    .pos-page {
        padding: 22px 16px 32px;
    }
    .pos-heading h1 {
        font-size: 32px;
    }
    .pos-header-actions {
        width: 100%;
    }
    .pos-header-actions > * {
        flex: 1;
    }
    .pos-catalog-caption span:last-child {
        display: none;
    }
}
@media (prefers-reduced-motion: reduce) {
    .pos-product-pick,
    .pos-product-card {
        transition: none;
    }
    .pos-product-pick:active {
        transform: none;
    }
}

.fade-enter-active, .fade-leave-active { transition: opacity 0.15s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.drawer-enter-active, .drawer-leave-active { transition: transform 0.28s ease; }
.drawer-enter-from, .drawer-leave-to { transform: translateX(100%); }
</style>
