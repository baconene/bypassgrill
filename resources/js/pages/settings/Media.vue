<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import { Upload, Trash2, Copy, Check, X, Image as ImageIcon, FileText, Film, ExternalLink } from 'lucide-vue-next'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/profile' },
            { title: 'Media', href: '/settings/media' },
        ],
    },
})

interface MediaFile {
    id: number
    original_name: string
    filename: string
    mime_type: string
    size: number
    url: string
    is_image: boolean
    created_at: string
}

const props = defineProps<{ files: MediaFile[] }>()

// ── Upload ─────────────────────────────────────────────────────────────────────
const dropping   = ref(false)
const uploading  = ref(false)
const fileInput  = ref<HTMLInputElement | null>(null)

const MAX_BYTES = 10 * 1024 * 1024  // 10 MB — must match server & controller
const ALLOWED   = ['image/jpeg','image/png','image/webp','image/gif','image/svg+xml',
                   'video/mp4','video/quicktime','video/x-msvideo','application/pdf']

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
    return match ? decodeURIComponent(match[1]) : ''
}

async function uploadFiles(files: FileList | null) {
    if (!files || files.length === 0) return

    // ── Client-side validation before any network request ──
    const valid: File[] = []
    for (const file of Array.from(files)) {
        if (file.size > MAX_BYTES) {
            toast.error(`"${file.name}" is too large (max 10 MB).`)
            continue
        }
        if (!ALLOWED.includes(file.type)) {
            toast.error(`"${file.name}" — unsupported file type (${file.type || 'unknown'}).`)
            continue
        }
        valid.push(file)
    }
    if (!valid.length) return

    uploading.value = true

    for (const file of valid) {
        const fd = new FormData()
        fd.append('file', file)
        try {
            const res = await fetch('/settings/media', {
                method: 'POST',
                body: fd,
                headers: {
                    'X-XSRF-TOKEN':    getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept':           'application/json',
                },
            })

            if (res.status === 413) {
                toast.error(`"${file.name}" was rejected by the server (file too large). Ask your host to raise client_max_body_size in Nginx or post_max_size in PHP.`)
                continue
            }

            if (!res.ok) {
                let msg = `Upload failed: ${file.name}`
                try {
                    const body = await res.json()
                    msg = body.message ?? Object.values(body.errors ?? {})?.[0]?.[0] ?? msg
                } catch { /* ignore */ }
                toast.error(msg)
                continue
            }

            toast.success(`"${file.name}" uploaded.`)
        } catch (err) {
            toast.error(`Network error uploading "${file.name}". Check your connection.`)
        }
    }

    uploading.value = false
    router.reload({ only: ['files'] })
}

const onDrop = (e: DragEvent) => {
    dropping.value = false
    uploadFiles(e.dataTransfer?.files ?? null)
}

const onFileInput = (e: Event) => uploadFiles((e.target as HTMLInputElement).files)

// ── Copy URL ───────────────────────────────────────────────────────────────────
const copied = ref<number | null>(null)
async function copyUrl(f: MediaFile) {
    await navigator.clipboard.writeText(f.url)
    copied.value = f.id
    toast.success('URL copied to clipboard.')
    setTimeout(() => { if (copied.value === f.id) copied.value = null }, 2000)
}

// ── Delete ─────────────────────────────────────────────────────────────────────
const deleting = ref<number | null>(null)
function remove(f: MediaFile) {
    if (!confirm(`Delete "${f.original_name}"?`)) return
    deleting.value = f.id
    router.delete(`/settings/media/${f.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('File deleted.'),
        onError: () => toast.error('Failed to delete.'),
        onFinish: () => { deleting.value = null },
    })
}

// ── Preview modal ──────────────────────────────────────────────────────────────
const preview = ref<MediaFile | null>(null)

// ── Helpers ────────────────────────────────────────────────────────────────────
const formatBytes = (bytes: number) => {
    if (bytes < 1024) return bytes + ' B'
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
    return (bytes / 1024 / 1024).toFixed(1) + ' MB'
}

const isVideo = (mime: string) => mime.startsWith('video/')
const isPdf   = (mime: string) => mime === 'application/pdf'

const filter  = ref<'all' | 'image' | 'video' | 'other'>('all')
const search  = ref('')

const filtered = computed(() => {
    let list = props.files
    if (filter.value === 'image') list = list.filter(f => f.is_image)
    else if (filter.value === 'video') list = list.filter(f => isVideo(f.mime_type))
    else if (filter.value === 'other') list = list.filter(f => !f.is_image && !isVideo(f.mime_type))
    if (search.value.trim()) {
        const q = search.value.toLowerCase()
        list = list.filter(f => f.original_name.toLowerCase().includes(q))
    }
    return list
})
</script>

<template>
    <Head title="Media Library" />

    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold">Media Library</h2>
                <p class="text-sm text-muted-foreground mt-0.5">
                    Upload images and files. Copy URLs to use in page content sections.
                </p>
            </div>
            <span class="text-xs text-muted-foreground mt-1">{{ files.length }} file{{ files.length !== 1 ? 's' : '' }}</span>
        </div>

        <!-- ── Upload zone ───────────────────────────────────────────────── -->
        <div
            @dragover.prevent="dropping = true"
            @dragleave="dropping = false"
            @drop.prevent="onDrop"
            :class="['rounded-xl border-2 border-dashed p-8 text-center transition cursor-pointer',
                dropping ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/50 hover:bg-muted/20']"
            @click="fileInput?.click()"
        >
            <input ref="fileInput" type="file" class="hidden" multiple
                accept="image/*,video/*,.pdf,.svg"
                @change="onFileInput" />
            <Upload class="h-8 w-8 mx-auto mb-3 text-muted-foreground opacity-50" />
            <p class="font-medium text-sm mb-1">
                {{ uploading ? 'Uploading…' : 'Click to upload or drag & drop' }}
            </p>
            <p class="text-xs text-muted-foreground">Images (JPG, PNG, WebP, GIF, SVG) · Videos (MP4, MOV, AVI) · PDF — <strong>max 10 MB</strong> each</p>
        </div>

        <!-- ── Filter bar ────────────────────────────────────────────────── -->
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex rounded-lg border overflow-hidden text-xs font-semibold">
                <button v-for="f in [['all','All'], ['image','Images'], ['video','Videos'], ['other','Other']]" :key="f[0]"
                    @click="filter = f[0] as any"
                    :class="['px-3 py-1.5 transition', filter === f[0] ? 'bg-primary text-primary-foreground' : 'hover:bg-muted text-muted-foreground']">
                    {{ f[1] }}
                </button>
            </div>
            <input v-model="search" type="text" placeholder="Search files…"
                class="rounded-lg border px-3 py-1.5 text-xs bg-background focus:outline-none focus:ring-2 focus:ring-primary flex-1 min-w-48" />
        </div>

        <!-- ── Empty state ───────────────────────────────────────────────── -->
        <div v-if="!filtered.length"
            class="rounded-xl border border-dashed border-border p-12 text-center text-muted-foreground">
            <ImageIcon class="h-10 w-10 mx-auto mb-3 opacity-30" />
            <p class="font-medium">{{ files.length ? 'No files match your filter.' : 'No files uploaded yet.' }}</p>
        </div>

        <!-- ── Media grid ────────────────────────────────────────────────── -->
        <div v-else class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
            <div
                v-for="f in filtered" :key="f.id"
                class="group relative rounded-xl border border-border bg-card overflow-hidden hover:border-primary/50 transition"
            >
                <!-- Thumbnail -->
                <div class="aspect-square bg-muted/30 relative overflow-hidden cursor-pointer" @click="preview = f">
                    <img v-if="f.is_image" :src="f.url" :alt="f.original_name"
                        class="h-full w-full object-cover group-hover:scale-105 transition-transform duration-300" />
                    <div v-else-if="isVideo(f.mime_type)"
                        class="h-full w-full flex items-center justify-center bg-zinc-900">
                        <Film class="h-10 w-10 text-muted-foreground" />
                    </div>
                    <div v-else
                        class="h-full w-full flex items-center justify-center">
                        <FileText class="h-10 w-10 text-muted-foreground" />
                    </div>
                </div>

                <!-- Info + actions -->
                <div class="p-2.5">
                    <p class="text-xs font-medium truncate" :title="f.original_name">{{ f.original_name }}</p>
                    <p class="text-[11px] text-muted-foreground">{{ formatBytes(f.size) }}</p>

                    <div class="flex items-center gap-1 mt-2">
                        <!-- Copy URL -->
                        <button @click="copyUrl(f)" title="Copy URL"
                            :class="['flex-1 flex items-center justify-center gap-1 rounded-md py-1 text-[11px] font-medium transition',
                                copied === f.id ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-muted hover:bg-muted/80']">
                            <component :is="copied === f.id ? Check : Copy" class="h-3 w-3" />
                            {{ copied === f.id ? 'Copied' : 'Copy URL' }}
                        </button>
                        <!-- Open -->
                        <a :href="f.url" target="_blank" title="Open in new tab"
                            class="rounded-md p-1 bg-muted hover:bg-muted/80 text-muted-foreground transition">
                            <ExternalLink class="h-3 w-3" />
                        </a>
                        <!-- Delete -->
                        <button @click="remove(f)" :disabled="deleting === f.id" title="Delete"
                            class="rounded-md p-1 hover:bg-red-100 dark:hover:bg-red-950/30 text-muted-foreground hover:text-red-600 transition disabled:opacity-40">
                            <Trash2 class="h-3 w-3" />
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Image Preview Modal ─────────────────────────────────────────────── -->
    <Teleport to="body">
        <div v-if="preview" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4" @click.self="preview = null">
            <div class="relative max-w-4xl w-full">
                <button @click="preview = null"
                    class="absolute -top-10 right-0 text-white/70 hover:text-white rounded-full p-1">
                    <X class="h-6 w-6" />
                </button>
                <img v-if="preview.is_image" :src="preview.url" :alt="preview.original_name"
                    class="rounded-xl max-h-[80vh] w-full object-contain shadow-2xl" />
                <video v-else-if="isVideo(preview.mime_type)" :src="preview.url" controls
                    class="rounded-xl max-h-[80vh] w-full shadow-2xl"></video>
                <div class="mt-3 flex items-center justify-between text-white/70 text-sm">
                    <span>{{ preview.original_name }}</span>
                    <div class="flex items-center gap-3">
                        <span>{{ formatBytes(preview.size) }}</span>
                        <button @click="copyUrl(preview!)"
                            class="flex items-center gap-1.5 rounded-lg bg-white/10 hover:bg-white/20 px-3 py-1.5 transition text-white text-xs font-medium">
                            <Copy class="h-3.5 w-3.5" /> Copy URL
                        </button>
                        <a :href="preview.url" target="_blank"
                            class="flex items-center gap-1.5 rounded-lg bg-white/10 hover:bg-white/20 px-3 py-1.5 transition text-white text-xs font-medium">
                            <ExternalLink class="h-3.5 w-3.5" /> Open
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
