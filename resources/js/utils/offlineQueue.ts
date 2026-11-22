import { openDB, type IDBPDatabase } from 'idb'

// ── Schema ─────────────────────────────────────────────────────────────────────
export interface PendingOrder {
    localId: string
    offlineQueueNumber: string
    payload: Record<string, unknown>
    status: 'pending' | 'synced' | 'failed'
    serverOrderId?: number
    createdAt: string
    error?: string
}

export interface PendingPayment {
    localId: string
    orderLocalId: string
    serverOrderId?: number
    payload: Record<string, unknown>
    status: 'pending' | 'synced' | 'failed'
    createdAt: string
    error?: string
}

// ── DB init ────────────────────────────────────────────────────────────────────
let _db: IDBPDatabase | null = null

async function getDB(): Promise<IDBPDatabase> {
    if (_db) return _db
    _db = await openDB('bypassgrill-pos', 1, {
        upgrade(db) {
            if (!db.objectStoreNames.contains('pending_orders')) {
                const s = db.createObjectStore('pending_orders', { keyPath: 'localId' })
                s.createIndex('by_status', 'status')
            }
            if (!db.objectStoreNames.contains('pending_payments')) {
                const s = db.createObjectStore('pending_payments', { keyPath: 'localId' })
                s.createIndex('by_status', 'status')
                s.createIndex('by_order_local_id', 'orderLocalId')
            }
        },
    })
    return _db
}

// ── Offline queue number ───────────────────────────────────────────────────────
// Read counter lazily to avoid localStorage errors at module init
function getCounter(): number {
    try {
        return parseInt(localStorage.getItem('bypassgrill_offline_seq') ?? '0') || 0
    } catch {
        return 0
    }
}

function setCounter(n: number) {
    try { localStorage.setItem('bypassgrill_offline_seq', String(n)) } catch { /* ignore */ }
}

function nextOfflineQueueNumber(): string {
    const n = getCounter() + 1
    setCounter(n)
    return `OFF-${String(n).padStart(3, '0')}`
}

// ── UUID with fallback for non-secure contexts ─────────────────────────────────
function uuid(): string {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID()
    }
    // Fallback: timestamp + random
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`
}

// ── Public API ─────────────────────────────────────────────────────────────────
export async function queueOrder(payload: Record<string, unknown>): Promise<PendingOrder> {
    const db = await getDB()
    const entry: PendingOrder = {
        localId: uuid(),
        offlineQueueNumber: nextOfflineQueueNumber(),
        // JSON round-trip strips Vue reactive Proxies — IDBObjectStore requires plain cloneable objects
        payload: JSON.parse(JSON.stringify(payload)),
        status: 'pending',
        createdAt: new Date().toISOString(),
    }
    await db.put('pending_orders', entry)
    return entry
}

export async function queuePayment(
    orderLocalId: string,
    payload: Record<string, unknown>,
    serverOrderId?: number,
): Promise<PendingPayment> {
    const db = await getDB()
    const entry: PendingPayment = {
        localId: uuid(),
        orderLocalId,
        serverOrderId,
        payload: JSON.parse(JSON.stringify(payload)),
        status: 'pending',
        createdAt: new Date().toISOString(),
    }
    await db.put('pending_payments', entry)
    return entry
}

export async function getPendingCounts(): Promise<{ orders: number; payments: number }> {
    try {
        const db = await getDB()
        const [orders, payments] = await Promise.all([
            db.getAllFromIndex('pending_orders', 'by_status', 'pending'),
            db.getAllFromIndex('pending_payments', 'by_status', 'pending'),
        ])
        return { orders: orders.length, payments: payments.length }
    } catch {
        return { orders: 0, payments: 0 }
    }
}

// Keep successful writes authoritative if foreground checkout and sync overlap.
export async function markOrderSynced(localId: string, serverOrderId: number) {
    await updateEntry('pending_orders', localId, { status: 'synced', serverOrderId })
}

export async function markPaymentSynced(localId: string) {
    await updateEntry('pending_payments', localId, { status: 'synced' })
}

export async function markCheckoutFailed(
    store: 'pending_orders' | 'pending_payments', localId: string, error: string,
) {
    await updateEntry(store, localId, { status: 'failed', error })
}

async function updateEntry(
    store: 'pending_orders' | 'pending_payments', localId: string,
    changes: { status: 'synced' | 'failed'; serverOrderId?: number; error?: string },
) {
    const db = await getDB()
    const tx = db.transaction(store, 'readwrite')
    const row = await tx.store.get(localId)
    if (row && row.status !== 'synced') await tx.store.put({ ...row, ...changes })
    await tx.done
}

export function isRejectedCheckout(status?: number): boolean {
    // Timeouts, throttling and server errors leave the outcome uncertain.
    return status !== undefined && status >= 400 && status < 500 &&
        ![401, 408, 419, 425, 429].includes(status)
}

export async function syncQueue(): Promise<{ synced: number; failed: number }> {
    const db = await getDB()
    let synced = 0
    let failed = 0
    const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    }
    const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
    if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken

    async function send(path: string, payload: Record<string, unknown>) {
        const response = await fetch(path, {
            method: 'POST', headers, credentials: 'same-origin', body: JSON.stringify(payload),
        })
        const body = await response.json().catch(() => ({}))
        return { response, body }
    }

    for (const order of await db.getAllFromIndex('pending_orders', 'by_status', 'pending') as PendingOrder[]) {
        try {
            const { response, body } = await send('/api/v1/orders', { ...order.payload, client_id: order.localId })
            if (response.ok) {
                const serverOrderId = Number((body.data ?? body).id)
                if (!serverOrderId) continue
                await markOrderSynced(order.localId, serverOrderId)
                synced++
            } else if (isRejectedCheckout(response.status)) {
                await markCheckoutFailed('pending_orders', order.localId, body.message ?? `HTTP ${response.status}`)
                failed++
            }
        } catch {
            // Same request ID will be sent again when the connection is available.
        }
    }

    for (const payment of await db.getAllFromIndex('pending_payments', 'by_status', 'pending') as PendingPayment[]) {
        try {
            const parent: PendingOrder | undefined = await db.get('pending_orders', payment.orderLocalId)
            // Also recover online-order payments queued by older builds without serverOrderId.
            const serverOrderId = payment.serverOrderId || parent?.serverOrderId || Number(payment.payload.order_id)
            if (!serverOrderId) {
                if (!parent || parent.status === 'failed') {
                    await markCheckoutFailed('pending_payments', payment.localId, 'Parent order could not sync')
                    failed++
                }
                continue
            }

            const { response, body } = await send('/api/v1/payments', {
                ...payment.payload, order_id: serverOrderId, client_id: payment.localId,
            })
            if (response.ok && (body.data ?? body).id) {
                await markPaymentSynced(payment.localId)
                synced++
            } else if (isRejectedCheckout(response.status)) {
                await markCheckoutFailed('pending_payments', payment.localId, body.message ?? `HTTP ${response.status}`)
                failed++
            }
        } catch {
            // Do not generate a new payment ID after a lost response.
        }
    }

    return { synced, failed }
}

export function requestBackgroundSync() {
    navigator.serviceWorker?.ready
        .then(reg => (reg as any).sync?.register('pos-queue-sync'))
        .catch(() => {})
}
