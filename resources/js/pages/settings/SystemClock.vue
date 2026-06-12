<script setup lang="ts">
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import { Clock, AlertTriangle, RotateCcw } from 'lucide-vue-next'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/profile' },
            { title: 'Date & Time', href: '/settings/clock' },
        ],
    },
})

interface ClockStatus {
    active: boolean
    label: string | null
    effective: string
    effective_iso: string
    real: string
}

const props = defineProps<{ clock: ClockStatus }>()

// datetime-local needs "YYYY-MM-DDTHH:mm"
const toLocalInput = (iso: string) => {
    const d = new Date(iso)
    const pad = (n: number) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

const datetime = ref(toLocalInput(props.clock.effective_iso))
const saving = ref(false)

const enable = () => {
    saving.value = true
    router.post('/settings/clock', { datetime: datetime.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Date/time override enabled'),
        onError: (e) => toast.error(Object.values(e)[0] as string ?? 'Failed to save'),
        onFinish: () => { saving.value = false },
    })
}

const disable = () => {
    if (!confirm('Revert to the real system time?')) return
    router.delete('/settings/clock', {
        preserveScroll: true,
        onSuccess: () => toast.success('Reverted to real time'),
        onError: () => toast.error('Failed to disable'),
    })
}
</script>

<template>
    <Head title="Date & Time Settings" />

    <div class="space-y-6">
        <div>
            <h2 class="text-base font-semibold">Date &amp; Time Override</h2>
            <p class="text-sm text-muted-foreground mt-0.5">
                Set an effective date/time the system uses when creating records — so late entries of orders
                and financial transactions get the correct timestamp. Applies to admins only.
            </p>
        </div>

        <!-- Active banner -->
        <div v-if="clock.active"
            class="rounded-xl border border-amber-300 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/20 p-4 flex items-start gap-3">
            <AlertTriangle class="h-5 w-5 text-amber-600 shrink-0 mt-0.5" />
            <div class="flex-1">
                <p class="text-sm font-bold text-amber-800 dark:text-amber-300">Override is ACTIVE</p>
                <p class="text-sm text-amber-700 dark:text-amber-400 mt-0.5">
                    New records you create are timestamped from <strong>{{ clock.label }}</strong>
                    (now reading as <strong>{{ clock.effective }}</strong>). Real time is {{ clock.real }}.
                </p>
                <p class="text-xs text-amber-700/80 dark:text-amber-400/80 mt-1">Remember to turn this off when you're done.</p>
            </div>
        </div>

        <!-- Current state -->
        <div class="rounded-xl border bg-card shadow-sm p-5 grid sm:grid-cols-2 gap-4">
            <div>
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Effective now</p>
                <p class="text-lg font-black flex items-center gap-2 mt-1">
                    <Clock class="h-4 w-4" :class="clock.active ? 'text-amber-600' : 'text-primary'" />
                    {{ clock.effective }}
                </p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Real system time</p>
                <p class="text-lg font-semibold text-muted-foreground mt-1">{{ clock.real }}</p>
            </div>
        </div>

        <!-- Set override -->
        <div class="rounded-xl border bg-card shadow-sm p-5 space-y-4">
            <h3 class="font-semibold text-sm">Set effective date &amp; time</h3>
            <p class="text-xs text-muted-foreground -mt-2">
                Time advances naturally from this point — set it to when the entries actually happened.
            </p>
            <div class="flex flex-col sm:flex-row sm:items-end gap-3">
                <div class="flex-1">
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Date &amp; time (Asia/Manila)</label>
                    <input v-model="datetime" type="datetime-local"
                        class="w-full sm:max-w-xs rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
                <button @click="enable" :disabled="saving"
                    class="rounded-lg bg-primary px-5 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                    {{ saving ? 'Saving…' : (clock.active ? 'Update Override' : 'Enable Override') }}
                </button>
            </div>
        </div>

        <!-- Disable -->
        <div v-if="clock.active" class="rounded-xl border bg-card shadow-sm p-5 flex items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold text-sm">Revert to real time</h3>
                <p class="text-xs text-muted-foreground mt-0.5">Turn off the override so records use the actual current time again.</p>
            </div>
            <button @click="disable"
                class="flex items-center gap-1.5 rounded-lg border border-red-200 dark:border-red-900/50 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/20">
                <RotateCcw class="h-3.5 w-3.5" /> Disable
            </button>
        </div>
    </div>
</template>
