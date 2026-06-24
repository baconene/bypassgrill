<script setup lang="ts">
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import { Save, Users } from 'lucide-vue-next'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/profile' },
            { title: 'HRIS', href: '/settings/hris' },
        ],
    },
})

interface Tender { id: number; name: string }

const props = defineProps<{
    settings: { payroll_tender_id: number | null }
    tenders: Tender[]
}>()

const payrollTenderId = ref<number | null>(props.settings.payroll_tender_id)
const saving = ref(false)

const save = () => {
    saving.value = true
    router.post('/settings/hris', {
        payroll_tender_id: payrollTenderId.value ?? '',
    }, {
        preserveScroll: true,
        onSuccess: () => toast.success('HRIS settings saved.'),
        onError: (errors) => toast.error(Object.values(errors).join(' ')),
        onFinish: () => { saving.value = false },
    })
}
</script>

<template>
    <Head title="HRIS Settings" />

    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-semibold">HRIS Settings</h2>
            <p class="text-sm text-muted-foreground">Configure payroll and HR-related financial options.</p>
        </div>

        <!-- Payroll Tender -->
        <div class="rounded-xl border bg-card shadow-sm p-5 space-y-5">
            <div class="flex items-center gap-2">
                <Users class="h-4 w-4 text-primary" />
                <h3 class="font-semibold text-sm">Payroll Payment Tender</h3>
            </div>
            <p class="text-xs text-muted-foreground -mt-2">
                Select the payment method used when releasing payroll. This tender will be recorded on each payroll
                financial transaction so it appears correctly in reports.
            </p>

            <div class="max-w-xs">
                <label class="text-xs font-medium text-muted-foreground block mb-1.5">
                    Tender type
                </label>
                <select v-model="payrollTenderId"
                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                    <option :value="null">— None / Unspecified —</option>
                    <option v-for="t in tenders" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
                <p class="text-xs text-muted-foreground mt-1.5">
                    If set to <em>None</em>, payroll transactions will be logged without a tender.
                </p>
            </div>

            <div class="flex justify-end pt-1">
                <button @click="save" :disabled="saving"
                    class="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-colors">
                    <Save class="h-3.5 w-3.5" />
                    {{ saving ? 'Saving…' : 'Save Settings' }}
                </button>
            </div>
        </div>
    </div>
</template>
