<script setup lang="ts">
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import { Save, Timer } from 'lucide-vue-next'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/profile' },
            { title: 'Kitchen', href: '/settings/kitchen' },
        ],
    },
})

const props = defineProps<{
    settings: { serving_fast_minutes: number; serving_slow_minutes: number }
}>()

const fastMinutes = ref(props.settings.serving_fast_minutes)
const slowMinutes = ref(props.settings.serving_slow_minutes)
const saving = ref(false)

const save = () => {
    saving.value = true
    router.post('/settings/kitchen', {
        serving_fast_minutes: fastMinutes.value,
        serving_slow_minutes: slowMinutes.value,
    }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Kitchen settings saved.'),
        onError: (errors) => toast.error(Object.values(errors).join(' ')),
        onFinish: () => { saving.value = false },
    })
}
</script>

<template>
    <Head title="Kitchen Settings" />

    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-semibold">Kitchen Settings</h2>
            <p class="text-sm text-muted-foreground">Configure kitchen performance thresholds shown on the dashboard.</p>
        </div>

        <!-- Serving Speed Thresholds -->
        <div class="rounded-xl border bg-card shadow-sm p-5 space-y-5">
            <div class="flex items-center gap-2">
                <Timer class="h-4 w-4 text-primary" />
                <h3 class="font-semibold text-sm">Serving Speed Thresholds</h3>
            </div>
            <p class="text-xs text-muted-foreground -mt-2">
                These thresholds determine the colour rating shown for average serving time on the dashboard.
            </p>

            <!-- Visual legend -->
            <div class="flex flex-wrap gap-3">
                <div class="flex items-center gap-1.5 text-xs">
                    <span class="inline-block w-3 h-3 rounded-full bg-emerald-500"></span>
                    <span class="font-medium text-emerald-700 dark:text-emerald-400">Fast</span>
                    <span class="text-muted-foreground">— under {{ fastMinutes }} min</span>
                </div>
                <div class="flex items-center gap-1.5 text-xs">
                    <span class="inline-block w-3 h-3 rounded-full bg-yellow-500"></span>
                    <span class="font-medium text-yellow-700 dark:text-yellow-400">Moderate</span>
                    <span class="text-muted-foreground">— {{ fastMinutes }} to {{ slowMinutes }} min</span>
                </div>
                <div class="flex items-center gap-1.5 text-xs">
                    <span class="inline-block w-3 h-3 rounded-full bg-red-500"></span>
                    <span class="font-medium text-red-700 dark:text-red-400">Slow</span>
                    <span class="text-muted-foreground">— over {{ slowMinutes }} min</span>
                </div>
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1.5">
                        Fast threshold (minutes)
                        <span class="text-muted-foreground/60 font-normal">— orders under this time are Fast</span>
                    </label>
                    <div class="flex items-center gap-2">
                        <input v-model.number="fastMinutes" type="number" min="1" max="60"
                            class="w-24 rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        <span class="text-sm text-muted-foreground">min</span>
                        <span class="rounded-full bg-emerald-100 dark:bg-emerald-950/30 text-emerald-700 dark:text-emerald-400 text-xs font-bold px-2 py-0.5">Fast</span>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1.5">
                        Slow threshold (minutes)
                        <span class="text-muted-foreground/60 font-normal">— orders over this time are Slow</span>
                    </label>
                    <div class="flex items-center gap-2">
                        <input v-model.number="slowMinutes" type="number" min="1" max="120"
                            class="w-24 rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        <span class="text-sm text-muted-foreground">min</span>
                        <span class="rounded-full bg-red-100 dark:bg-red-950/30 text-red-700 dark:text-red-400 text-xs font-bold px-2 py-0.5">Slow</span>
                    </div>
                </div>
            </div>

            <p v-if="fastMinutes >= slowMinutes" class="text-xs text-red-600 font-medium">
                The slow threshold must be greater than the fast threshold.
            </p>

            <div class="flex justify-end pt-1">
                <button @click="save" :disabled="saving || fastMinutes >= slowMinutes"
                    class="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-colors">
                    <Save class="h-3.5 w-3.5" />
                    {{ saving ? 'Saving…' : 'Save Settings' }}
                </button>
            </div>
        </div>
    </div>
</template>
