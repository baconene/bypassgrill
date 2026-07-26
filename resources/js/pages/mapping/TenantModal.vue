<script setup lang="ts">
import { ref, watch } from 'vue'
import { X, Loader2 } from 'lucide-vue-next'
import type { RentalTenant } from './types'

const props = defineProps<{
    open: boolean
    tenant: RentalTenant | null
    saving: boolean
}>()

const emit = defineEmits<{
    close: []
    save: [data: Partial<RentalTenant>]
    delete: [id: number]
}>()

const form = ref({ name: '', business_name: '', contact_number: '', email: '', notes: '' })

watch(() => props.open, (v) => {
    if (!v) return
    if (props.tenant) {
        form.value = {
            name:           props.tenant.name,
            business_name:  props.tenant.business_name ?? '',
            contact_number: props.tenant.contact_number ?? '',
            email:          props.tenant.email ?? '',
            notes:          props.tenant.notes ?? '',
        }
    } else {
        form.value = { name: '', business_name: '', contact_number: '', email: '', notes: '' }
    }
})

const submit = () => {
    emit('save', {
        name:           form.value.name,
        business_name:  form.value.business_name || null,
        contact_number: form.value.contact_number || null,
        email:          form.value.email || null,
        notes:          form.value.notes || null,
    })
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
        <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @click.self="emit('close')">
            <Transition
                enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100"
                leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95"
                enter-active-class="transition-all duration-200"
                leave-active-class="transition-all duration-150"
            >
                <div v-if="open" class="w-full max-w-md rounded-2xl bg-card border shadow-2xl overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 border-b">
                        <p class="font-semibold text-foreground">{{ tenant ? 'Edit Tenant' : 'New Tenant' }}</p>
                        <button @click="emit('close')" class="p-1.5 rounded-lg hover:bg-accent transition-colors">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submit" class="p-6 space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1">Name <span class="text-destructive">*</span></label>
                            <input type="text" v-model="form.name" required placeholder="Full name"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1">Business Name</label>
                            <input type="text" v-model="form.business_name" placeholder="Optional"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-muted-foreground mb-1">Contact</label>
                                <input type="text" v-model="form.contact_number" placeholder="+63..."
                                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-muted-foreground mb-1">Email</label>
                                <input type="email" v-model="form.email" placeholder="Optional"
                                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1">Notes</label>
                            <textarea v-model="form.notes" rows="2" placeholder="Optional..."
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring resize-none"></textarea>
                        </div>

                        <div class="flex items-center justify-between pt-2">
                            <button v-if="tenant" type="button" @click="emit('delete', tenant.id)" class="text-xs text-destructive hover:underline">
                                Delete tenant
                            </button>
                            <div v-else></div>
                            <div class="flex gap-2">
                                <button type="button" @click="emit('close')" class="rounded-lg border px-4 py-2 text-sm hover:bg-accent transition-colors">Cancel</button>
                                <button type="submit" :disabled="saving || !form.name.trim()"
                                    class="rounded-lg bg-primary text-primary-foreground px-4 py-2 text-sm font-medium hover:bg-primary/90 disabled:opacity-50 transition-colors flex items-center gap-2">
                                    <Loader2 v-if="saving" class="w-3.5 h-3.5 animate-spin" />
                                    {{ tenant ? 'Update' : 'Create' }}
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
