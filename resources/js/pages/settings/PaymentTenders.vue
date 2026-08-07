<script setup lang="ts">
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import { Plus, Pencil, Trash2, X, CheckCircle, XCircle, Upload, ImageIcon } from 'lucide-vue-next'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/profile' },
            { title: 'Payment Tenders', href: '/settings/payment-tenders' },
        ],
    },
})

interface Tender { id: number; name: string; is_active: boolean; display_order: number }

const props = defineProps<{ tenders: Tender[]; gcashQrUrl: string | null }>()

const tenders = ref<Tender[]>([...props.tenders])
const showForm = ref(false)
const editingId = ref<number | null>(null)
const form = ref({ name: '', display_order: 1, is_active: true })
const saving = ref(false)

// ── GCash QR ─────────────────────────────────────────────────────────────────
const qrPreview = ref<string | null>(props.gcashQrUrl)
const qrFile = ref<File | null>(null)
const qrUploading = ref(false)
const qrDropping = ref(false)

const onQrFile = (e: Event) => {
    const f = (e.target as HTMLInputElement).files?.[0]
    if (!f) return
    qrFile.value = f
    qrPreview.value = URL.createObjectURL(f)
}

const onQrDrop = (e: DragEvent) => {
    qrDropping.value = false
    const f = e.dataTransfer?.files[0]
    if (!f || !f.type.startsWith('image/')) return
    qrFile.value = f
    qrPreview.value = URL.createObjectURL(f)
}

const uploadQr = () => {
    if (!qrFile.value) return
    qrUploading.value = true
    const formData = new FormData()
    formData.append('gcash_qr', qrFile.value)
    router.post('/settings/gcash-qr', formData, {
        preserveScroll: true,
        onSuccess: () => { qrFile.value = null; toast.success('GCash QR updated') },
        onError: (errors) => toast.error(Object.values(errors)[0] as string ?? 'Upload failed'),
        onFinish: () => { qrUploading.value = false },
    })
}

const removeQr = () => {
    if (!confirm('Remove the GCash QR code?')) return
    router.delete('/settings/gcash-qr', {
        preserveScroll: true,
        onSuccess: () => { qrPreview.value = null; qrFile.value = null; toast.success('GCash QR removed') },
        onError: () => toast.error('Failed to remove QR'),
    })
}

// ── Tenders ───────────────────────────────────────────────────────────────────
const openAdd = () => {
    editingId.value = null
    form.value = { name: '', display_order: tenders.value.length + 1, is_active: true }
    showForm.value = true
}

const openEdit = (t: Tender) => {
    editingId.value = t.id
    form.value = { name: t.name, display_order: t.display_order, is_active: t.is_active }
    showForm.value = true
}

const save = async () => {
    if (!form.value.name.trim()) { toast.error('Name is required'); return }
    saving.value = true
    try {
        if (editingId.value) {
            const res = await api.put(`/api/v1/payment-tenders/${editingId.value}`, form.value)
            const idx = tenders.value.findIndex((t) => t.id === editingId.value)
            if (idx !== -1) tenders.value[idx] = res.data
        } else {
            const res = await api.post('/api/v1/payment-tenders', form.value)
            tenders.value.push(res.data)
        }
        tenders.value.sort((a, b) => a.display_order - b.display_order)
        toast.success('Payment tender saved')
        showForm.value = false
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to save')
    } finally {
        saving.value = false
    }
}

const toggle = async (tender: Tender) => {
    try {
        const res = await api.put(`/api/v1/payment-tenders/${tender.id}`, {
            name: tender.name,
            display_order: tender.display_order,
            is_active: !tender.is_active,
        })
        const idx = tenders.value.findIndex((t) => t.id === tender.id)
        if (idx !== -1) tenders.value[idx] = res.data
    } catch {
        toast.error('Failed to update status')
    }
}

const remove = async (id: number) => {
    if (!confirm('Delete this payment tender?')) return
    try {
        await api.delete(`/api/v1/payment-tenders/${id}`)
        tenders.value = tenders.value.filter((t) => t.id !== id)
        toast.success('Deleted')
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to delete')
    }
}
</script>

<template>
    <Head title="Payment Tenders" />

    <div class="max-w-2xl space-y-8">

        <!-- GCash QR Code -->
        <div class="space-y-4">
            <div>
                <h2 class="text-base font-semibold">GCash QR Code</h2>
                <p class="text-sm text-muted-foreground mt-0.5">
                    Upload your GCash QR image. It will appear on public order pages when payment is still pending.
                </p>
            </div>

            <div class="rounded-xl border bg-card shadow-sm p-5 space-y-4">
                <label
                    @dragover.prevent="qrDropping = true"
                    @dragleave="qrDropping = false"
                    @drop.prevent="onQrDrop"
                    :class="[
                        'flex flex-col items-center justify-center gap-3 rounded-lg border-2 border-dashed p-8 cursor-pointer transition',
                        qrDropping ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/50 hover:bg-muted/30',
                    ]"
                >
                    <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="onQrFile" />

                    <div v-if="qrPreview" class="flex flex-col items-center gap-3">
                        <img :src="qrPreview" class="h-40 w-40 object-contain rounded-lg border bg-white p-2" alt="GCash QR preview" />
                        <p class="text-xs text-muted-foreground">Click or drag to replace</p>
                    </div>
                    <div v-else class="flex flex-col items-center gap-2 text-muted-foreground">
                        <ImageIcon class="h-10 w-10 opacity-40" />
                        <p class="text-sm font-medium">Click to upload or drag & drop</p>
                        <p class="text-xs">PNG, JPG, or WebP — max 2MB</p>
                    </div>
                </label>

                <div class="flex items-center gap-3">
                    <button
                        @click="uploadQr"
                        :disabled="!qrFile || qrUploading"
                        class="flex items-center gap-1.5 rounded-lg bg-primary px-5 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-40"
                    >
                        <Upload class="h-3.5 w-3.5" />
                        {{ qrUploading ? 'Uploading…' : 'Save QR Code' }}
                    </button>
                    <button
                        v-if="qrPreview"
                        @click="removeQr"
                        class="flex items-center gap-1.5 rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/20"
                    >
                        <Trash2 class="h-3.5 w-3.5" />
                        Remove
                    </button>
                </div>
            </div>
        </div>

        <!-- Payment Tenders list -->
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold">Payment Tenders</h2>
                    <p class="text-sm text-muted-foreground">Manage accepted payment methods at the POS.</p>
                </div>
                <button
                    @click="openAdd"
                    class="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90"
                >
                    <Plus class="h-4 w-4" /> Add Tender
                </button>
            </div>

            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-muted-foreground text-xs uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3 text-left w-16">Order</th>
                            <th class="px-4 py-3 text-left">Name</th>
                            <th class="px-4 py-3 text-center w-28">Status</th>
                            <th class="px-4 py-3 text-right w-24">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="t in tenders" :key="t.id" class="hover:bg-muted/20">
                            <td class="px-4 py-3 text-muted-foreground text-center">{{ t.display_order }}</td>
                            <td class="px-4 py-3 font-medium">{{ t.name }}</td>
                            <td class="px-4 py-3">
                                <button
                                    @click="toggle(t)"
                                    :class="t.is_active ? 'text-green-600 hover:text-green-700' : 'text-muted-foreground hover:text-foreground'"
                                    class="flex items-center gap-1 mx-auto transition-colors"
                                >
                                    <CheckCircle v-if="t.is_active" class="h-4 w-4" />
                                    <XCircle v-else class="h-4 w-4" />
                                    <span class="text-xs">{{ t.is_active ? 'Active' : 'Inactive' }}</span>
                                </button>
                            </td>
                            <td class="px-4 py-3 text-right space-x-1">
                                <button
                                    @click="openEdit(t)"
                                    class="rounded p-1.5 hover:bg-muted text-muted-foreground hover:text-foreground transition-colors"
                                >
                                    <Pencil class="h-3.5 w-3.5" />
                                </button>
                                <button
                                    @click="remove(t.id)"
                                    class="rounded p-1.5 hover:bg-destructive/10 text-destructive transition-colors"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="tenders.length === 0">
                            <td colspan="4" class="px-4 py-10 text-center text-muted-foreground">
                                No payment tenders configured. Click <strong>Add Tender</strong> to get started.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add / Edit Modal -->
    <Teleport to="body">
        <Transition name="fade">
            <div
                v-if="showForm"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                @click.self="showForm = false"
            >
                <div class="w-full max-w-sm rounded-2xl bg-background shadow-2xl">
                    <div class="p-5 border-b flex items-center justify-between">
                        <h3 class="font-bold">{{ editingId ? 'Edit' : 'Add' }} Payment Tender</h3>
                        <button @click="showForm = false" class="rounded-full p-1 hover:bg-muted">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="p-5 space-y-4">
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1">Name</label>
                            <input
                                v-model="form.name"
                                type="text"
                                placeholder="e.g. GCash, Cash, Credit Card"
                                autofocus
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                @keydown.enter="save"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1">Display Order</label>
                            <input
                                v-model.number="form.display_order"
                                type="number"
                                min="1"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            />
                        </div>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" v-model="form.is_active" class="rounded" />
                            <span class="text-sm font-medium">Active (visible at POS)</span>
                        </label>
                    </div>
                    <div class="p-5 border-t flex gap-3">
                        <button
                            @click="showForm = false"
                            class="flex-1 rounded-lg border py-2 text-sm font-medium hover:bg-muted"
                        >
                            Cancel
                        </button>
                        <button
                            @click="save"
                            :disabled="saving"
                            class="flex-1 rounded-lg bg-primary py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            {{ saving ? 'Saving…' : 'Save' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.15s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
