<script setup lang="ts">
import { computed } from 'vue'
import { X, Calendar, Clock, DollarSign, User, Edit2, Trash2, Phone, Mail } from 'lucide-vue-next'
import type { StallDay, RentalSchedule } from './types'

const props = defineProps<{
    open: boolean
    stall: StallDay | null
}>()

const emit = defineEmits<{
    close: []
    'new-schedule': []
    'edit-schedule': [s: RentalSchedule]
}>()

const statusLabel = (s: string) => {
    const map: Record<string, string> = {
        confirmed: 'Confirmed', reserved: 'Reserved',
        maintenance: 'Maintenance', cancelled: 'Cancelled',
    }
    return map[s] ?? s
}

const statusClass = (s: string) => {
    switch (s) {
        case 'confirmed':   return 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'
        case 'reserved':    return 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
        case 'maintenance': return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'
        default:            return 'bg-muted text-muted-foreground'
    }
}

const typeLabel = (t: string) => t.charAt(0).toUpperCase() + t.slice(1)
</script>

<template>
<Teleport to="body">
    <Transition
        enter-from-class="opacity-0" enter-to-class="opacity-100"
        leave-from-class="opacity-100" leave-to-class="opacity-0"
        enter-active-class="transition-opacity duration-200"
        leave-active-class="transition-opacity duration-150"
    >
        <div v-if="open" class="fixed inset-0 z-40" @click.self="emit('close')">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="emit('close')"></div>

            <Transition
                enter-from-class="translate-x-full" enter-to-class="translate-x-0"
                leave-from-class="translate-x-0" leave-to-class="translate-x-full"
                enter-active-class="transition-transform duration-300 ease-out"
                leave-active-class="transition-transform duration-200 ease-in"
            >
                <aside
                    v-if="open"
                    class="absolute right-0 top-0 h-full w-full max-w-sm bg-card border-l shadow-2xl flex flex-col z-50"
                    @click.stop
                >
                    <!-- Header -->
                    <div class="flex items-start justify-between px-5 py-4 border-b">
                        <div>
                            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                Stall {{ stall?.number }}
                            </p>
                            <h3 class="font-bold text-lg text-foreground leading-tight">{{ stall?.label }}</h3>
                            <p v-if="stall?.description" class="text-xs text-muted-foreground mt-0.5">{{ stall.description }}</p>
                        </div>
                        <button @click="emit('close')" class="p-1.5 rounded-lg hover:bg-accent mt-0.5 transition-colors">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <!-- Content -->
                    <div class="flex-1 overflow-y-auto p-5 space-y-5">
                        <!-- Active Schedule -->
                        <div v-if="stall?.schedule" class="space-y-3">
                            <div class="flex items-center justify-between">
                                <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Current Schedule</p>
                                <span :class="['text-xs font-medium px-2 py-0.5 rounded-full', statusClass(stall.schedule.status)]">
                                    {{ statusLabel(stall.schedule.status) }}
                                </span>
                            </div>

                            <!-- Tenant info -->
                            <div class="rounded-xl border bg-muted/30 p-4 space-y-2">
                                <div class="flex items-center gap-2">
                                    <User class="w-4 h-4 text-muted-foreground flex-shrink-0" />
                                    <div class="min-w-0">
                                        <p class="font-semibold text-foreground text-sm truncate">
                                            {{ stall.schedule.tenant?.business_name || stall.schedule.tenant?.name }}
                                        </p>
                                        <p v-if="stall.schedule.tenant?.business_name" class="text-xs text-muted-foreground truncate">
                                            {{ stall.schedule.tenant?.name }}
                                        </p>
                                    </div>
                                </div>
                                <div v-if="stall.schedule.tenant?.contact_number" class="flex items-center gap-2 text-xs text-muted-foreground">
                                    <Phone class="w-3.5 h-3.5 flex-shrink-0" />
                                    {{ stall.schedule.tenant.contact_number }}
                                </div>
                                <div v-if="stall.schedule.tenant?.email" class="flex items-center gap-2 text-xs text-muted-foreground">
                                    <Mail class="w-3.5 h-3.5 flex-shrink-0" />
                                    {{ stall.schedule.tenant.email }}
                                </div>
                            </div>

                            <!-- Schedule details -->
                            <div class="space-y-2 text-sm">
                                <div class="flex items-center gap-2 text-muted-foreground">
                                    <Calendar class="w-4 h-4 flex-shrink-0" />
                                    <span>{{ stall.schedule.start_date }} – {{ stall.schedule.end_date }}</span>
                                </div>
                                <div v-if="stall.schedule.start_time" class="flex items-center gap-2 text-muted-foreground">
                                    <Clock class="w-4 h-4 flex-shrink-0" />
                                    <span>{{ stall.schedule.start_time }} – {{ stall.schedule.end_time ?? 'open' }}</span>
                                </div>
                                <div v-if="stall.schedule.price > 0" class="flex items-center gap-2 text-muted-foreground">
                                    <DollarSign class="w-4 h-4 flex-shrink-0" />
                                    <span>₱{{ Number(stall.schedule.price).toLocaleString() }} · {{ typeLabel(stall.schedule.rental_type) }}</span>
                                </div>
                            </div>

                            <p v-if="stall.schedule.notes" class="text-xs text-muted-foreground italic border-l-2 border-border pl-3">
                                {{ stall.schedule.notes }}
                            </p>

                            <button
                                @click="emit('edit-schedule', stall.schedule)"
                                class="w-full rounded-lg border px-4 py-2.5 text-sm font-medium hover:bg-accent transition-colors flex items-center justify-center gap-2"
                            >
                                <Edit2 class="w-3.5 h-3.5" /> Edit Schedule
                            </button>
                        </div>

                        <div v-else class="text-center py-6 space-y-2">
                            <p class="text-sm text-muted-foreground">No active schedule</p>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="px-5 py-4 border-t">
                        <button
                            v-if="stall?.is_active"
                            @click="emit('new-schedule')"
                            class="w-full rounded-xl bg-primary text-primary-foreground py-2.5 text-sm font-medium hover:bg-primary/90 transition-colors"
                        >
                            + New Schedule
                        </button>
                        <p v-else class="text-xs text-center text-muted-foreground">This stall is inactive</p>
                    </div>
                </aside>
            </Transition>
        </div>
    </Transition>
</Teleport>
</template>
