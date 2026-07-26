<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { X, Loader2 } from 'lucide-vue-next'
import type { RentalTenant, RentalSchedule, StallDay, ScheduleStatus, RentalType } from './types'

const props = defineProps<{
    open: boolean
    stall: StallDay | null
    schedule: RentalSchedule | null
    tenants: RentalTenant[]
    saving: boolean
}>()

const emit = defineEmits<{
    close: []
    save: [data: Partial<RentalSchedule>]
    delete: [id: number]
}>()

const form = ref({
    tenant_id: 0,
    rental_type: 'daily' as RentalType,
    status: 'reserved' as ScheduleStatus,
    start_date: '',
    end_date: '',
    start_time: '',
    end_time: '',
    price: '',
    notes: '',
})

watch(() => props.open, (v) => {
    if (!v) return
    if (props.schedule) {
        form.value = {
            tenant_id:   props.schedule.tenant_id,
            rental_type: props.schedule.rental_type,
            status:      props.schedule.status,
            start_date:  props.schedule.start_date,
            end_date:    props.schedule.end_date,
            start_time:  props.schedule.start_time ?? '',
            end_time:    props.schedule.end_time ?? '',
            price:       String(props.schedule.price ?? ''),
            notes:       props.schedule.notes ?? '',
        }
    } else {
        const today = new Date().toISOString().slice(0, 10)
        form.value = {
            tenant_id: props.tenants[0]?.id ?? 0,
            rental_type: 'daily',
            status: 'reserved',
            start_date: today,
            end_date: today,
            start_time: '',
            end_time: '',
            price: '',
            notes: '',
        }
    }
})

const isEdit = computed(() => !!props.schedule)

const submit = () => {
    const data: Partial<RentalSchedule> & { stall_id?: number } = {
        tenant_id:   form.value.tenant_id,
        rental_type: form.value.rental_type,
        status:      form.value.status,
        start_date:  form.value.start_date,
        end_date:    form.value.end_date,
        start_time:  form.value.start_time || null,
        end_time:    form.value.end_time || null,
        price:       parseFloat(form.value.price) || 0,
        notes:       form.value.notes || null,
    }
    if (!isEdit.value && props.stall) {
        data.stall_id = props.stall.id
    }
    emit('save', data)
}
</script>

<template>
<Teleport to="body">
    <Transition
        enter-from-class="opacity-0" enter-to-class="opacity-100"
        leave-from-class="opacity-100" leave-to-class="opacity-0"
        enter-active-class="transition-opacity duration-200"
        leave-active-class="transition-opacity duration-150"
    >
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
            @click.self="emit('close')"
        >
            <Transition
                enter-from-class="opacity-0 scale-95 translate-y-2"
                enter-to-class="opacity-100 scale-100 translate-y-0"
                leave-from-class="opacity-100 scale-100 translate-y-0"
                leave-to-class="opacity-0 scale-95 translate-y-2"
                enter-active-class="transition-all duration-200"
                leave-active-class="transition-all duration-150"
            >
                <div v-if="open" class="w-full max-w-lg rounded-2xl bg-card border shadow-2xl overflow-hidden">
                    <!-- Header -->
                    <div class="flex items-center justify-between px-6 py-4 border-b">
                        <div>
                            <p class="font-semibold text-foreground">
                                {{ isEdit ? 'Edit Schedule' : 'New Schedule' }}
                            </p>
                            <p v-if="stall" class="text-xs text-muted-foreground">
                                {{ stall.label }}
                            </p>
                        </div>
                        <button @click="emit('close')" class="p-1.5 rounded-lg hover:bg-accent transition-colors">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <!-- Form -->
                    <form @submit.prevent="submit" class="p-6 space-y-4">
                        <!-- Tenant -->
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1">Tenant</label>
                            <select
                                v-model.number="form.tenant_id"
                                required
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                            >
                                <option v-if="tenants.length === 0" disabled value="0">No tenants — add one first</option>
                                <option v-for="t in tenants" :key="t.id" :value="t.id">
                                    {{ t.business_name ? `${t.business_name} (${t.name})` : t.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Type + Status -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-muted-foreground mb-1">Rental Type</label>
                                <select v-model="form.rental_type" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring">
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="custom">Custom</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-muted-foreground mb-1">Status</label>
                                <select v-model="form.status" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring">
                                    <option value="reserved">Reserved</option>
                                    <option value="confirmed">Confirmed</option>
                                    <option v-if="isEdit" value="cancelled">Cancelled</option>
                                    <option value="maintenance">Maintenance</option>
                                </select>
                            </div>
                        </div>

                        <!-- Dates -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-muted-foreground mb-1">Start Date</label>
                                <input type="date" v-model="form.start_date" required
                                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-muted-foreground mb-1">End Date</label>
                                <input type="date" v-model="form.end_date" required :min="form.start_date"
                                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
                            </div>
                        </div>

                        <!-- Times -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-muted-foreground mb-1">Start Time <span class="text-muted-foreground/60">(optional)</span></label>
                                <input type="time" v-model="form.start_time"
                                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-muted-foreground mb-1">End Time <span class="text-muted-foreground/60">(optional)</span></label>
                                <input type="time" v-model="form.end_time"
                                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
                            </div>
                        </div>

                        <!-- Price -->
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1">Price (₱)</label>
                            <input type="number" v-model="form.price" min="0" step="0.01" placeholder="0.00"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
                        </div>

                        <!-- Notes -->
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1">Notes</label>
                            <textarea v-model="form.notes" rows="2" placeholder="Optional notes..."
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring resize-none"></textarea>
                        </div>

                        <!-- Footer -->
                        <div class="flex items-center justify-between pt-2">
                            <button
                                v-if="isEdit && schedule"
                                type="button"
                                @click="emit('delete', schedule.id)"
                                class="text-xs text-destructive hover:underline"
                            >Delete schedule</button>
                            <div v-else></div>
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    @click="emit('close')"
                                    class="rounded-lg border px-4 py-2 text-sm hover:bg-accent transition-colors"
                                >Cancel</button>
                                <button
                                    type="submit"
                                    :disabled="saving || !form.tenant_id"
                                    class="rounded-lg bg-primary text-primary-foreground px-4 py-2 text-sm font-medium hover:bg-primary/90 disabled:opacity-50 transition-colors flex items-center gap-2"
                                >
                                    <Loader2 v-if="saving" class="w-3.5 h-3.5 animate-spin" />
                                    {{ isEdit ? 'Update' : 'Create' }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </Transition>
        </div>
    </Transition>
</Teleport>
</template>
