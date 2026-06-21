<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import { Printer, CheckCircle2, AlertCircle, Loader2, Bell, Send, Wifi, Share2, QrCode } from 'lucide-vue-next'
import api from '@/utils/api'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/profile' },
            { title: 'Print Service', href: '/settings/print-service' },
        ],
    },
})

interface PrintServiceSettings {
    print_service_url: string
    print_paper_width: 32 | 48
    print_store_name: string
    print_store_address: string
    print_store_phone: string
    print_footer: string
    print_auto_print: boolean
    print_enabled: boolean
    print_channel: string
    social_facebook: string | null
    social_instagram: string | null
    receipt_qr_type: 'none' | 'facebook' | 'order_url'
}

const props = defineProps<{
    settings: PrintServiceSettings
    beams_instance_id: string | null
    beams_configured: boolean
    channels_configured: boolean
    channels_driver_ok: boolean
    channels_driver: string
    channels_app_key: string | null
    channels_cluster: string
}>()

const form = ref<PrintServiceSettings>({ ...props.settings })

// Config cache
const cacheClearBusy = ref(false)
const cacheClearResult = ref<{ ok: boolean; message: string } | null>(null)

const clearConfigCache = async () => {
    cacheClearBusy.value = true
    cacheClearResult.value = null
    try {
        const res = await api.post('/api/v1/print-service/clear-config-cache')
        cacheClearResult.value = { ok: true, message: res.data.message }
        // Reload the page so Inertia picks up the fresh props (updated driver name)
        setTimeout(() => window.location.reload(), 1200)
    } catch (err: any) {
        cacheClearResult.value = { ok: false, message: err.response?.data?.message ?? 'Failed to clear cache' }
    } finally {
        cacheClearBusy.value = false
    }
}

// Pusher Channels test (primary — WebSocket) — uses the channel from the form above
const channelsTesting = ref(false)
const channelsResult = ref<{ ok: boolean; message: string } | null>(null)

const sendTestChannels = async () => {
    channelsTesting.value = true
    channelsResult.value = null
    try {
        const res = await api.post('/api/v1/print-jobs/test-channels', { channel: form.value.print_channel || 'orders' })
        channelsResult.value = { ok: true, message: res.data.message }
    } catch (err: any) {
        channelsResult.value = { ok: false, message: err.response?.data?.message ?? 'Request failed' }
    } finally {
        channelsTesting.value = false
    }
}

// Pusher Beams test (secondary — FCM wake-up)
const beamsTest = ref({ interest: 'print-jobs', title: 'Test from BypassGrill', body: 'Push notifications are working!' })
const beamsTesting = ref(false)
const beamsResult = ref<{ ok: boolean; message: string } | null>(null)

const sendTestNotification = async () => {
    beamsTesting.value = true
    beamsResult.value = null
    try {
        const res = await api.post('/api/v1/print-jobs/test-notification', beamsTest.value)
        beamsResult.value = { ok: true, message: res.data.message }
    } catch (err: any) {
        beamsResult.value = { ok: false, message: err.response?.data?.message ?? 'Request failed' }
    } finally {
        beamsTesting.value = false
    }
}
const saving = ref(false)
const testing = ref(false)
const connectionStatus = ref<'idle' | 'ok' | 'error'>('idle')
const connectionMessage = ref('')

const save = async () => {
    saving.value = true
    try {
        const res = await api.post('/api/v1/print-service/settings', form.value)
        Object.assign(form.value, res.data)
        toast.success('Print service settings saved')
    } catch (err: any) {
        const errors = err.response?.data?.errors
        if (errors) {
            toast.error(Object.values(errors).flat().join(' '))
        } else {
            toast.error(err.response?.data?.message ?? 'Failed to save settings')
        }
    } finally {
        saving.value = false
    }
}

const testConnection = async () => {
    if (!form.value.print_service_url) {
        toast.error('Enter a service URL first')
        return
    }
    testing.value = true
    connectionStatus.value = 'idle'
    connectionMessage.value = ''
    try {
        const res = await api.post('/api/v1/print-service/test', {
            url: form.value.print_service_url,
        })
        const data = res.data
        if (data.reachable) {
            connectionStatus.value = 'ok'
            connectionMessage.value = data.printer_connected
                ? 'Service reachable — printer connected'
                : 'Service reachable — printer not connected'
        } else {
            connectionStatus.value = 'error'
            connectionMessage.value = data.error ?? 'Service unreachable'
        }
    } catch (err: any) {
        connectionStatus.value = 'error'
        connectionMessage.value = err.response?.data?.message ?? 'Connection test failed'
    } finally {
        testing.value = false
    }
}

onMounted(() => {
    form.value = { ...props.settings }
})
</script>

<template>
    <Head title="Print Service Settings" />

    <div class="space-y-6">
        <div>
            <h2 class="text-base font-semibold">Print Service</h2>
            <p class="text-sm text-muted-foreground mt-0.5">
                Configure the Bluetooth receipt printer bridge running on your staff phone.
            </p>
        </div>

        <!-- Master toggle -->
        <div class="rounded-xl border bg-card shadow-sm p-5">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="font-semibold text-sm">Enable receipt printing</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        Turn on to allow the system to send receipts to the print service.
                    </p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" v-model="form.print_enabled" class="sr-only peer" />
                    <div class="w-10 h-5 bg-muted rounded-full peer peer-checked:bg-primary transition-colors after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5"></div>
                </label>
            </div>
        </div>

        <!-- Print Channel (Pusher WebSocket) -->
        <div class="rounded-xl border bg-card shadow-sm p-5 space-y-3">
            <h3 class="font-semibold text-sm flex items-center gap-2">
                <Wifi class="h-4 w-4" /> Print Channel
            </h3>
            <p class="text-xs text-muted-foreground -mt-1">
                The Pusher WebSocket channel receipts are broadcast to. Point different outlets at
                different channels — e.g. <code class="bg-muted px-1 rounded">BypassGrill</code> or
                <code class="bg-muted px-1 rounded">Load-Cafe</code> — and set the Android app's
                <code class="bg-muted px-1 rounded">ws_channel</code> to match.
            </p>
            <div>
                <label class="text-xs font-medium text-muted-foreground block mb-1">Channel name</label>
                <input
                    v-model="form.print_channel"
                    type="text"
                    placeholder="orders"
                    class="w-full sm:max-w-xs rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                />
                <p class="text-xs text-muted-foreground mt-1">
                    Letters, numbers, dots, dashes and underscores only. Leave blank to use
                    <code class="bg-muted px-1 rounded">orders</code>.
                </p>
            </div>
        </div>

        <!-- Service URL -->
        <div class="rounded-xl border bg-card shadow-sm p-5 space-y-4">
            <h3 class="font-semibold text-sm flex items-center gap-2">
                <Printer class="h-4 w-4" /> Service Connection
            </h3>

            <div>
                <label class="text-xs font-medium text-muted-foreground block mb-1">
                    Service URL
                </label>
                <div class="flex gap-2">
                    <input
                        v-model="form.print_service_url"
                        type="url"
                        placeholder="http://192.168.1.42:8080"
                        class="flex-1 rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                    />
                    <button
                        @click="testConnection"
                        :disabled="testing"
                        class="flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium hover:bg-muted disabled:opacity-50 whitespace-nowrap"
                    >
                        <Loader2 v-if="testing" class="h-3.5 w-3.5 animate-spin" />
                        <Printer v-else class="h-3.5 w-3.5" />
                        Test Connection
                    </button>
                </div>
                <p class="text-xs text-muted-foreground mt-1">
                    The IP address and port of the PrintingServicd app on your staff phone (must be on the same Wi-Fi network).
                </p>
            </div>

            <!-- Connection status badge -->
            <div
                v-if="connectionStatus !== 'idle'"
                :class="[
                    'flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium',
                    connectionStatus === 'ok'
                        ? 'bg-green-50 text-green-700 dark:bg-green-950/20 dark:text-green-400'
                        : 'bg-red-50 text-red-700 dark:bg-red-950/20 dark:text-red-400',
                ]"
            >
                <CheckCircle2 v-if="connectionStatus === 'ok'" class="h-3.5 w-3.5" />
                <AlertCircle v-else class="h-3.5 w-3.5" />
                {{ connectionMessage }}
            </div>
        </div>

        <!-- Paper width -->
        <div class="rounded-xl border bg-card shadow-sm p-5 space-y-4">
            <h3 class="font-semibold text-sm">Paper Width</h3>
            <div class="flex gap-3">
                <label
                    v-for="opt in [
                        { val: 32, label: '58 mm (standard)', desc: '32 chars per line' },
                        { val: 48, label: '80 mm (wide)', desc: '48 chars per line' },
                    ]"
                    :key="opt.val"
                    :class="[
                        'flex-1 flex items-start gap-3 rounded-lg border p-3 cursor-pointer transition',
                        form.print_paper_width === opt.val
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:bg-muted/50',
                    ]"
                >
                    <input
                        type="radio"
                        :value="opt.val"
                        v-model="form.print_paper_width"
                        class="mt-0.5"
                    />
                    <div>
                        <p class="text-sm font-semibold">{{ opt.label }}</p>
                        <p class="text-xs text-muted-foreground">{{ opt.desc }}</p>
                    </div>
                </label>
            </div>
        </div>

        <!-- Store details -->
        <div class="rounded-xl border bg-card shadow-sm p-5 space-y-4">
            <h3 class="font-semibold text-sm">Store Details</h3>
            <p class="text-xs text-muted-foreground -mt-2">
                Printed at the top of every receipt.
            </p>

            <div>
                <label class="text-xs font-medium text-muted-foreground block mb-1">Store Name</label>
                <input
                    v-model="form.print_store_name"
                    type="text"
                    placeholder="e.g. BypassGrill"
                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                />
            </div>

            <div>
                <label class="text-xs font-medium text-muted-foreground block mb-1">Address</label>
                <input
                    v-model="form.print_store_address"
                    type="text"
                    placeholder="e.g. 123 Main Street"
                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                />
            </div>

            <div>
                <label class="text-xs font-medium text-muted-foreground block mb-1">Phone</label>
                <input
                    v-model="form.print_store_phone"
                    type="text"
                    placeholder="e.g. 09XX-XXX-XXXX"
                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                />
            </div>

            <div>
                <label class="text-xs font-medium text-muted-foreground block mb-1">Footer Text</label>
                <input
                    v-model="form.print_footer"
                    type="text"
                    placeholder="e.g. Thank you for dining with us!"
                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                />
            </div>
        </div>

        <!-- Auto-print toggle -->
        <div class="rounded-xl border bg-card shadow-sm p-5">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="font-semibold text-sm">Automatically print on completed sale</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        Silently sends a receipt to the printer whenever an order is marked as completed.
                    </p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" v-model="form.print_auto_print" class="sr-only peer" />
                    <div class="w-10 h-5 bg-muted rounded-full peer peer-checked:bg-primary transition-colors after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5"></div>
                </label>
            </div>
        </div>

        <!-- Pusher Channels test (primary WebSocket delivery) -->
        <div class="rounded-xl border bg-card shadow-sm p-5 space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-sm flex items-center gap-2">
                        <Wifi class="h-4 w-4" /> Pusher Channels — WebSocket Test
                    </h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        Primary delivery path. Broadcasts a test receipt to the Android app via WebSocket.
                        Android must have <code class="bg-muted px-1 rounded text-[11px]">ws_key</code> and <code class="bg-muted px-1 rounded text-[11px]">ws_host</code> configured.
                    </p>
                </div>
                <span :class="['shrink-0 text-[10px] font-bold px-2 py-0.5 rounded-full',
                    channels_configured
                        ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                        : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400']">
                    {{ channels_configured ? 'Configured' : 'Not Configured' }}
                </span>
            </div>

            <!-- Warn if BROADCAST_CONNECTION is not pusher, with a one-click cache clear -->
            <div v-if="!channels_driver_ok"
                class="rounded-lg bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 px-3 py-3 text-xs text-amber-700 dark:text-amber-400 space-y-2">
                <div class="flex items-start gap-2">
                    <AlertCircle class="h-3.5 w-3.5 mt-0.5 shrink-0" />
                    <span>
                        <strong>BROADCAST_CONNECTION={{ channels_driver || 'null' }}</strong> — config cache is stale.
                        Your <code class="bg-amber-100 dark:bg-amber-900/30 px-1 rounded">.env</code> has <code class="bg-amber-100 dark:bg-amber-900/30 px-1 rounded">BROADCAST_CONNECTION=pusher</code>
                        but the cached config still has the old value. Click below to clear it.
                    </span>
                </div>
                <div class="flex items-center gap-2 pl-5">
                    <button @click="clearConfigCache" :disabled="cacheClearBusy"
                        class="flex items-center gap-1.5 rounded-lg bg-amber-600 hover:bg-amber-700 text-white px-3 py-1.5 text-xs font-bold disabled:opacity-50 transition">
                        <Loader2 v-if="cacheClearBusy" class="h-3 w-3 animate-spin" />
                        {{ cacheClearBusy ? 'Clearing…' : 'Clear Config Cache' }}
                    </button>
                    <span v-if="cacheClearResult" :class="cacheClearResult.ok ? 'text-green-600' : 'text-red-600'">
                        {{ cacheClearResult.message }}
                    </span>
                </div>
            </div>

            <div v-if="channels_app_key" class="rounded-lg bg-muted/50 px-3 py-2 text-xs space-y-1">
                <div class="flex gap-2">
                    <span class="text-muted-foreground w-20 shrink-0">App Key</span>
                    <code class="font-mono break-all">{{ channels_app_key }}</code>
                </div>
                <div class="flex gap-2">
                    <span class="text-muted-foreground w-20 shrink-0">Cluster</span>
                    <code class="font-mono">{{ channels_cluster }}</code>
                </div>
                <div class="flex gap-2">
                    <span class="text-muted-foreground w-20 shrink-0">ws_host</span>
                    <code class="font-mono">ws-{{ channels_cluster }}.pusher.com</code>
                </div>
            </div>
            <p v-else class="text-xs text-red-600 dark:text-red-400">
                PUSHER_APP_KEY is not set. Add Pusher Channels credentials to <code class="bg-muted px-1 rounded">.env</code>.
            </p>

            <div class="flex items-end gap-3">
                <div class="flex-1 max-w-[200px]">
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Channel</label>
                    <input :value="form.print_channel || 'orders'" type="text" readonly
                        class="w-full rounded-lg border bg-muted/40 px-3 py-2 text-sm text-muted-foreground cursor-not-allowed" />
                    <p class="text-[10px] text-muted-foreground mt-1">Set above in “Print Channel”</p>
                </div>
                <button
                    @click="sendTestChannels"
                    :disabled="channelsTesting || !channels_configured"
                    class="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition"
                >
                    <Loader2 v-if="channelsTesting" class="h-3.5 w-3.5 animate-spin" />
                    <Send v-else class="h-3.5 w-3.5" />
                    {{ channelsTesting ? 'Broadcasting…' : 'Broadcast Test Receipt' }}
                </button>
            </div>

            <div v-if="channelsResult"
                :class="['flex items-start gap-2 rounded-lg px-3 py-2.5 text-xs font-medium',
                    channelsResult.ok
                        ? 'bg-green-50 text-green-700 dark:bg-green-950/20 dark:text-green-400'
                        : 'bg-red-50 text-red-700 dark:bg-red-950/20 dark:text-red-400']">
                <CheckCircle2 v-if="channelsResult.ok" class="h-3.5 w-3.5 mt-0.5 shrink-0" />
                <AlertCircle v-else class="h-3.5 w-3.5 mt-0.5 shrink-0" />
                {{ channelsResult.message }}
            </div>
        </div>

        <!-- Pusher Beams webhook test -->
        <div class="rounded-xl border bg-card shadow-sm p-5 space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-sm flex items-center gap-2">
                        <Bell class="h-4 w-4" /> Pusher Beams — FCM Wake-up Test
                    </h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        Secondary path — sends an FCM push notification to wake the Android app.
                        Does not carry receipt data; use the Channels test above for full receipt delivery.
                    </p>
                </div>
                <span
                    :class="[
                        'shrink-0 text-[10px] font-bold px-2 py-0.5 rounded-full',
                        beams_configured
                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                            : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                    ]"
                >
                    {{ beams_configured ? 'Configured' : 'Not Configured' }}
                </span>
            </div>

            <div v-if="beams_instance_id" class="rounded-lg bg-muted/50 px-3 py-2 text-xs font-mono text-muted-foreground break-all">
                Instance: {{ beams_instance_id }}
            </div>
            <p v-else class="text-xs text-red-600 dark:text-red-400">
                PUSHER_BEAMS_INSTANCE_ID and PUSHER_BEAMS_SECRET_KEY are not set in your .env file.
            </p>

            <div class="grid sm:grid-cols-3 gap-3">
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Interest (channel)</label>
                    <input v-model="beamsTest.interest" type="text" placeholder="print-jobs"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Title</label>
                    <input v-model="beamsTest.title" type="text"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Body</label>
                    <input v-model="beamsTest.body" type="text"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button
                    @click="sendTestNotification"
                    :disabled="beamsTesting || !beams_configured"
                    class="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition"
                >
                    <Loader2 v-if="beamsTesting" class="h-3.5 w-3.5 animate-spin" />
                    <Send v-else class="h-3.5 w-3.5" />
                    {{ beamsTesting ? 'Sending…' : 'Send Test Notification' }}
                </button>
            </div>

            <div v-if="beamsResult"
                :class="[
                    'flex items-start gap-2 rounded-lg px-3 py-2.5 text-xs font-medium',
                    beamsResult.ok
                        ? 'bg-green-50 text-green-700 dark:bg-green-950/20 dark:text-green-400'
                        : 'bg-red-50 text-red-700 dark:bg-red-950/20 dark:text-red-400',
                ]"
            >
                <CheckCircle2 v-if="beamsResult.ok" class="h-3.5 w-3.5 mt-0.5 shrink-0" />
                <AlertCircle v-else class="h-3.5 w-3.5 mt-0.5 shrink-0" />
                {{ beamsResult.message }}
            </div>
        </div>

        <!-- Social Links -->
        <div class="rounded-xl border bg-card shadow-sm p-5 space-y-4">
            <h3 class="font-semibold text-sm flex items-center gap-2">
                <Share2 class="h-4 w-4" /> Social Links
            </h3>
            <p class="text-xs text-muted-foreground -mt-2">
                Used when printing QR codes on receipts. Enter the full URL for each platform.
            </p>

            <div>
                <label class="text-xs font-medium text-muted-foreground block mb-1">Facebook Page URL</label>
                <input
                    v-model="form.social_facebook"
                    type="url"
                    placeholder="https://www.facebook.com/yourpage"
                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                />
            </div>

            <div>
                <label class="text-xs font-medium text-muted-foreground block mb-1">Instagram Profile URL</label>
                <input
                    v-model="form.social_instagram"
                    type="url"
                    placeholder="https://www.instagram.com/yourprofile"
                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                />
            </div>
        </div>

        <!-- Receipt QR Code -->
        <div class="rounded-xl border bg-card shadow-sm p-5 space-y-4">
            <h3 class="font-semibold text-sm flex items-center gap-2">
                <QrCode class="h-4 w-4" /> Receipt QR Code
            </h3>
            <p class="text-xs text-muted-foreground -mt-2">
                Choose what the QR code on printed receipts links to. Only applies to the Android print service.
            </p>

            <div class="space-y-2">
                <label
                    v-for="opt in [
                        { val: 'order_url', label: 'Order details link', desc: 'Links to the public order status page for this specific order.' },
                        { val: 'facebook', label: 'Facebook page', desc: 'Links to your Facebook page URL set above.' },
                        { val: 'none', label: 'No QR code', desc: 'The receipt will not include a QR code.' },
                    ]"
                    :key="opt.val"
                    :class="[
                        'flex items-start gap-3 rounded-lg border p-3 cursor-pointer transition',
                        form.receipt_qr_type === opt.val
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:bg-muted/50',
                    ]"
                >
                    <input
                        type="radio"
                        :value="opt.val"
                        v-model="form.receipt_qr_type"
                        class="mt-0.5"
                    />
                    <div>
                        <p class="text-sm font-semibold">{{ opt.label }}</p>
                        <p class="text-xs text-muted-foreground">{{ opt.desc }}</p>
                    </div>
                </label>
            </div>

            <div
                v-if="form.receipt_qr_type === 'facebook' && !form.social_facebook"
                class="flex items-start gap-2 rounded-lg bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 px-3 py-2.5 text-xs text-amber-700 dark:text-amber-400"
            >
                <AlertCircle class="h-3.5 w-3.5 mt-0.5 shrink-0" />
                No Facebook URL set — add it in the Social Links section above.
            </div>
        </div>

        <!-- Save button -->
        <div>
            <button
                @click="save"
                :disabled="saving"
                class="rounded-lg bg-primary px-5 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
            >
                {{ saving ? 'Saving…' : 'Save Settings' }}
            </button>
        </div>
    </div>
</template>
