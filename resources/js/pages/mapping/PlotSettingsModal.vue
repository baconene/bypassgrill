<script setup lang="ts">
import { ref, watch } from 'vue'
import { X, Loader2, Save, Settings2 } from 'lucide-vue-next'
import { toast } from 'vue-sonner'

export interface PlotSettings {
    showDimensions: boolean
    showTenantNames: boolean
    showStallNumbers: boolean
    showStatusLabel: boolean
}

interface StallForm {
    id: number
    number: number
    label: string
    description: string
    is_active: boolean
    _dirty: boolean
}

const props = defineProps<{
    open: boolean
    settings: PlotSettings
}>()

const emit = defineEmits<{
    close: []
    'update:settings': [PlotSettings]
    'stalls-updated': []
}>()

const stallForms  = ref<StallForm[]>([])
const loading     = ref(false)
const saving      = ref(false)
const localSettings = ref<PlotSettings>({ ...props.settings })

const apiFetch = async (url: string, options?: RequestInit) => {
    const res = await fetch(url, {
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        ...options,
    })
    if (!res.ok) {
        const err = await res.json().catch(() => ({}))
        throw new Error(err.message ?? `HTTP ${res.status}`)
    }
    return res.status === 204 ? {} : res.json()
}

watch(() => props.open, async (v) => {
    if (!v) return
    localSettings.value = { ...props.settings }
    loading.value = true
    try {
        const data = await apiFetch('/api/v1/rental/stalls')
        stallForms.value = data.map((s: any) => ({
            id:          s.id,
            number:      s.number,
            label:       s.label,
            description: s.description ?? '',
            is_active:   s.is_active,
            _dirty:      false,
        }))
    } catch (e: any) {
        toast.error(e.message)
    } finally {
        loading.value = false
    }
})

// Emit settings changes immediately so LandViewModal stays in sync live
watch(localSettings, (v) => emit('update:settings', { ...v }), { deep: true })

const markDirty = (form: StallForm) => { form._dirty = true }

const saveStalls = async () => {
    const dirty = stallForms.value.filter(f => f._dirty)
    if (!dirty.length) { toast.info('No changes to save'); return }
    saving.value = true
    try {
        await Promise.all(dirty.map(f =>
            apiFetch(`/api/v1/rental/stalls/${f.id}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    label:       f.label.trim() || `Stall ${f.number}`,
                    description: f.description.trim() || null,
                    is_active:   f.is_active,
                }),
            })
        ))
        stallForms.value.forEach(f => (f._dirty = false))
        toast.success(`${dirty.length} stall${dirty.length > 1 ? 's' : ''} saved`)
        emit('stalls-updated')
    } catch (e: any) {
        toast.error(e.message)
    } finally {
        saving.value = false
    }
}

const hasDirty = () => stallForms.value.some(f => f._dirty)
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
                enter-active-class="transition-all duration-200" leave-active-class="transition-all duration-150"
            >
                <div v-if="open" class="w-full max-w-xl rounded-2xl bg-card border shadow-2xl overflow-hidden flex flex-col max-h-[85vh]">

                    <!-- Header -->
                    <div class="flex items-center justify-between px-5 py-4 border-b flex-shrink-0">
                        <div class="flex items-center gap-2">
                            <Settings2 class="w-4 h-4 text-muted-foreground" />
                            <p class="font-semibold text-foreground">Plot Settings</p>
                        </div>
                        <button @click="emit('close')" class="p-1.5 rounded-lg hover:bg-accent transition-colors">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 overflow-y-auto p-5 space-y-6">

                        <!-- ── Display Options ────────────────────────────────────── -->
                        <div class="space-y-3">
                            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Display Options</p>
                            <div class="rounded-xl border divide-y">
                                <label class="flex items-center justify-between px-4 py-3 cursor-pointer hover:bg-accent/50 transition-colors">
                                    <div>
                                        <p class="text-sm font-medium text-foreground">Dimension labels</p>
                                        <p class="text-xs text-muted-foreground">Show 11m × 3m measurement arrows</p>
                                    </div>
                                    <input type="checkbox" v-model="localSettings.showDimensions"
                                        class="w-4 h-4 accent-primary cursor-pointer" />
                                </label>
                                <label class="flex items-center justify-between px-4 py-3 cursor-pointer hover:bg-accent/50 transition-colors">
                                    <div>
                                        <p class="text-sm font-medium text-foreground">Stall number badges</p>
                                        <p class="text-xs text-muted-foreground">Circular number badge on each stall</p>
                                    </div>
                                    <input type="checkbox" v-model="localSettings.showStallNumbers"
                                        class="w-4 h-4 accent-primary cursor-pointer" />
                                </label>
                                <label class="flex items-center justify-between px-4 py-3 cursor-pointer hover:bg-accent/50 transition-colors">
                                    <div>
                                        <p class="text-sm font-medium text-foreground">Status labels</p>
                                        <p class="text-xs text-muted-foreground">Available / Occupied / Reserved text</p>
                                    </div>
                                    <input type="checkbox" v-model="localSettings.showStatusLabel"
                                        class="w-4 h-4 accent-primary cursor-pointer" />
                                </label>
                                <label class="flex items-center justify-between px-4 py-3 cursor-pointer hover:bg-accent/50 transition-colors">
                                    <div>
                                        <p class="text-sm font-medium text-foreground">Tenant names</p>
                                        <p class="text-xs text-muted-foreground">Business or tenant name inside stall</p>
                                    </div>
                                    <input type="checkbox" v-model="localSettings.showTenantNames"
                                        class="w-4 h-4 accent-primary cursor-pointer" />
                                </label>
                            </div>
                        </div>

                        <!-- ── Stall Configuration ────────────────────────────────── -->
                        <div class="space-y-3">
                            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Stall Configuration</p>
                            <div v-if="loading" class="flex items-center justify-center py-8">
                                <Loader2 class="w-5 h-5 animate-spin text-muted-foreground" />
                            </div>
                            <div v-else class="space-y-2">
                                <div
                                    v-for="form in stallForms"
                                    :key="form.id"
                                    :class="['rounded-xl border p-3 space-y-2 transition-colors', form._dirty ? 'border-primary/40 bg-primary/5' : 'bg-muted/20']"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-semibold text-muted-foreground">Stall {{ form.number }}</span>
                                        <label class="flex items-center gap-1.5 text-xs cursor-pointer select-none">
                                            <input type="checkbox" v-model="form.is_active" @change="markDirty(form)"
                                                class="w-3.5 h-3.5 accent-primary cursor-pointer" />
                                            <span :class="form.is_active ? 'text-emerald-600 dark:text-emerald-400 font-medium' : 'text-muted-foreground'">
                                                {{ form.is_active ? 'Active' : 'Inactive' }}
                                            </span>
                                        </label>
                                    </div>
                                    <input
                                        type="text"
                                        v-model="form.label"
                                        @input="markDirty(form)"
                                        placeholder="Stall label"
                                        maxlength="100"
                                        class="w-full rounded-lg border bg-background px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                    />
                                    <input
                                        type="text"
                                        v-model="form.description"
                                        @input="markDirty(form)"
                                        placeholder="Description (optional)"
                                        maxlength="500"
                                        class="w-full rounded-lg border bg-background px-3 py-1.5 text-sm text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="flex items-center justify-between px-5 py-3 border-t flex-shrink-0 bg-card">
                        <button @click="emit('close')" class="text-sm text-muted-foreground hover:text-foreground transition-colors">
                            Close
                        </button>
                        <button
                            @click="saveStalls"
                            :disabled="saving || loading"
                            :class="[
                                'rounded-lg px-4 py-2 text-sm font-medium flex items-center gap-2 transition-colors',
                                hasDirty()
                                    ? 'bg-primary text-primary-foreground hover:bg-primary/90'
                                    : 'bg-muted text-muted-foreground cursor-not-allowed',
                            ]"
                        >
                            <Loader2 v-if="saving" class="w-3.5 h-3.5 animate-spin" />
                            <Save v-else class="w-3.5 h-3.5" />
                            Save Stalls
                        </button>
                    </div>
                </div>
            </Transition>
        </div>
    </Transition>
</Teleport>
</template>
