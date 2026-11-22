// Module-level singleton — all components that call useOfflineSync() share this state.
import { ref } from 'vue'
import { syncQueue, getPendingCounts, requestBackgroundSync } from './offlineQueue'
import { toast } from 'vue-sonner'

export const pendingCount = ref(0)
export const syncing = ref(false)

let syncTimer: ReturnType<typeof setTimeout> | null = null

export async function refreshCount() {
    const counts = await getPendingCounts()
    pendingCount.value = counts.orders + counts.payments
}

export async function doSync() {
    if (syncing.value || !navigator.onLine) return
    syncing.value = true
    try {
        await refreshCount()
        if (pendingCount.value === 0) return
        const result = navigator.locks
            ? await navigator.locks.request('bypassgrill-pos-sync', { ifAvailable: true }, lock => lock ? syncQueue() : null)
            : await syncQueue()
        if (!result) {
            scheduleSync(15_000)
            return
        }
        await refreshCount()
        if (result.synced > 0) toast.success(`${result.synced} offline transaction(s) synced.`)
        if (result.failed > 0) toast.error(`${result.failed} queued transaction(s) were rejected. Review the order before collecting again.`)
        if (pendingCount.value > 0) scheduleSync(15_000)
    } catch {
        await refreshCount()
        toast.error('Sync error. Will retry when connection stabilises.')
    } finally {
        syncing.value = false
    }
}

export function requestCheckoutSync() {
    requestBackgroundSync()
    if (navigator.onLine) scheduleSync()
}

function scheduleSync(delay = 1500) {
    if (syncTimer) clearTimeout(syncTimer)
    syncTimer = setTimeout(doSync, delay)
}

// ── One-time setup — called from app.ts ───────────────────────────────────────
export function initOfflineSync() {
    window.addEventListener('online', () => scheduleSync())

    // Receive wake-up messages from the service worker (Background Sync API)
    navigator.serviceWorker?.addEventListener('message', (event: MessageEvent) => {
        if (event.data?.type === 'SYNC_QUEUE') scheduleSync()
    })

    // Register the service worker
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {})
    }

    // Sync any leftover items from a previous session on startup
    refreshCount().then(() => {
        if (navigator.onLine && pendingCount.value > 0) scheduleSync()
    })
}
