<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import { Plus, Pencil, Trash2, ToggleLeft, ToggleRight, X, Megaphone, Tag, AlarmClock } from 'lucide-vue-next'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/profile' },
            { title: 'Advertisements', href: '/settings/advertisements' },
        ],
    },
})

type AdType = 'banner' | 'promo' | 'announcement'
type BgColor = 'orange' | 'red' | 'green' | 'blue' | 'yellow'

interface Ad {
    id: number
    type: AdType
    title: string
    body: string | null
    badge_text: string | null
    bg_color: BgColor
    is_active: boolean
    display_order: number
    starts_at: string | null
    ends_at: string | null
}

const props = defineProps<{ advertisements: Ad[] }>()

// ── Colors & labels ────────────────────────────────────────────────────────────
const colorOptions: { value: BgColor; label: string; class: string }[] = [
    { value: 'orange', label: 'Orange', class: 'bg-orange-500' },
    { value: 'red',    label: 'Red',    class: 'bg-red-600' },
    { value: 'green',  label: 'Green',  class: 'bg-green-600' },
    { value: 'blue',   label: 'Blue',   class: 'bg-blue-600' },
    { value: 'yellow', label: 'Yellow', class: 'bg-yellow-400' },
]

const typeLabel: Record<AdType, string> = {
    banner: 'Banner',
    promo: 'Promo',
    announcement: 'Announcement',
}

const typeBadge: Record<AdType, string> = {
    banner:       'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    promo:        'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    announcement: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
}

const bgPreviewClass: Record<BgColor, string> = {
    orange: 'bg-orange-500 text-black',
    red:    'bg-red-600 text-white',
    green:  'bg-green-600 text-white',
    blue:   'bg-blue-600 text-white',
    yellow: 'bg-yellow-400 text-black',
}

// ── Modal state ────────────────────────────────────────────────────────────────
const blank = (): Omit<Ad, 'id'> => ({
    type: 'banner',
    title: '',
    body: null,
    badge_text: null,
    bg_color: 'orange',
    is_active: true,
    display_order: 0,
    starts_at: null,
    ends_at: null,
})

const showModal = ref(false)
const editing = ref<Ad | null>(null)
const form = ref(blank())
const saving = ref(false)

function openCreate() {
    editing.value = null
    form.value = blank()
    showModal.value = true
}

function openEdit(ad: Ad) {
    editing.value = ad
    form.value = { ...ad }
    showModal.value = true
}

function closeModal() {
    showModal.value = false
}

function save() {
    saving.value = true
    const isEdit = editing.value !== null

    const payload = {
        ...form.value,
        is_active: form.value.is_active ? 1 : 0,
    }

    if (isEdit) {
        router.patch(`/settings/advertisements/${editing.value!.id}`, payload, {
            preserveScroll: true,
            onSuccess: () => { closeModal(); toast.success('Advertisement updated.') },
            onError: (e) => toast.error(Object.values(e)[0] as string ?? 'Save failed'),
            onFinish: () => { saving.value = false },
        })
    } else {
        router.post('/settings/advertisements', payload, {
            preserveScroll: true,
            onSuccess: () => { closeModal(); toast.success('Advertisement created.') },
            onError: (e) => toast.error(Object.values(e)[0] as string ?? 'Save failed'),
            onFinish: () => { saving.value = false },
        })
    }
}

function toggle(ad: Ad) {
    router.post(`/settings/advertisements/${ad.id}/toggle`, {}, {
        preserveScroll: true,
        onSuccess: () => toast.success(ad.is_active ? 'Deactivated.' : 'Activated.'),
        onError: () => toast.error('Failed to toggle.'),
    })
}

function remove(ad: Ad) {
    if (!confirm(`Delete "${ad.title}"? This cannot be undone.`)) return
    router.delete(`/settings/advertisements/${ad.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Advertisement deleted.'),
        onError: () => toast.error('Failed to delete.'),
    })
}

const banners = computed(() => props.advertisements.filter(a => a.type === 'banner'))
const promos  = computed(() => props.advertisements.filter(a => a.type === 'promo'))
const others  = computed(() => props.advertisements.filter(a => a.type === 'announcement'))
</script>

<template>
    <Head title="Advertisements" />

    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold">Advertisements</h2>
                <p class="text-sm text-muted-foreground mt-0.5">
                    Manage banners and promotions shown on the public storefront.
                </p>
            </div>
            <button
                @click="openCreate"
                class="flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 shrink-0"
            >
                <Plus class="h-4 w-4" /> New Ad
            </button>
        </div>

        <!-- ── Legend ──────────────────────────────────────────────────────── -->
        <div class="flex flex-wrap gap-3 text-xs">
            <span v-for="t in ['banner','promo','announcement']" :key="t"
                :class="['px-2.5 py-1 rounded-full font-semibold', typeBadge[t as AdType]]">
                {{ typeLabel[t as AdType] }}
                <span class="opacity-60 font-normal ml-1">—
                    {{ t === 'banner' ? 'top strip on homepage' : t === 'promo' ? '"Today\'s Specials" section' : 'informational notice' }}
                </span>
            </span>
        </div>

        <!-- ── Empty state ─────────────────────────────────────────────────── -->
        <div v-if="!advertisements.length"
            class="rounded-xl border border-dashed border-border p-12 text-center text-muted-foreground">
            <Megaphone class="h-10 w-10 mx-auto mb-3 opacity-30" />
            <p class="font-medium">No advertisements yet</p>
            <p class="text-sm mt-1">Create a banner or promo to display on the storefront.</p>
        </div>

        <!-- ── Ad list ─────────────────────────────────────────────────────── -->
        <div v-else class="space-y-3">
            <div
                v-for="ad in advertisements" :key="ad.id"
                :class="['rounded-xl border bg-card p-4 flex flex-col sm:flex-row sm:items-center gap-4 transition', ad.is_active ? 'border-border' : 'border-dashed border-border opacity-60']"
            >
                <!-- color swatch -->
                <div :class="['w-3 h-12 rounded-full shrink-0', bgPreviewClass[ad.bg_color].split(' ')[0]]"></div>

                <!-- info -->
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2 mb-1">
                        <span :class="['text-[11px] font-semibold px-2 py-0.5 rounded-full', typeBadge[ad.type]]">
                            {{ typeLabel[ad.type] }}
                        </span>
                        <span v-if="!ad.is_active" class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-muted text-muted-foreground">Inactive</span>
                        <span v-if="ad.badge_text" class="flex items-center gap-1 text-[11px] text-muted-foreground">
                            <Tag class="h-3 w-3" /> {{ ad.badge_text }}
                        </span>
                    </div>
                    <p class="font-semibold text-sm truncate">{{ ad.title }}</p>
                    <p v-if="ad.body" class="text-xs text-muted-foreground mt-0.5 line-clamp-1">{{ ad.body }}</p>
                    <p v-if="ad.starts_at || ad.ends_at" class="text-[11px] text-muted-foreground mt-1 flex items-center gap-1">
                        <AlarmClock class="h-3 w-3" />
                        <span v-if="ad.starts_at">From {{ ad.starts_at.slice(0, 10) }}</span>
                        <span v-if="ad.ends_at"> until {{ ad.ends_at.slice(0, 10) }}</span>
                    </p>
                </div>

                <!-- actions -->
                <div class="flex items-center gap-2 shrink-0">
                    <button @click="toggle(ad)" :title="ad.is_active ? 'Deactivate' : 'Activate'"
                        class="rounded-lg p-2 hover:bg-muted transition">
                        <ToggleRight v-if="ad.is_active" class="h-5 w-5 text-green-600" />
                        <ToggleLeft v-else class="h-5 w-5 text-muted-foreground" />
                    </button>
                    <button @click="openEdit(ad)" title="Edit"
                        class="rounded-lg p-2 hover:bg-muted transition">
                        <Pencil class="h-4 w-4 text-muted-foreground" />
                    </button>
                    <button @click="remove(ad)" title="Delete"
                        class="rounded-lg p-2 hover:bg-red-50 dark:hover:bg-red-950/20 transition">
                        <Trash2 class="h-4 w-4 text-red-500" />
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Create / Edit Modal ─────────────────────────────────────────────── -->
    <Teleport to="body">
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
            <div class="w-full max-w-lg rounded-xl bg-background shadow-2xl border border-border">
                <div class="flex items-center justify-between border-b px-5 py-4">
                    <h2 class="font-bold">{{ editing ? 'Edit Advertisement' : 'New Advertisement' }}</h2>
                    <button @click="closeModal" class="rounded p-1 hover:bg-muted">
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <div class="p-5 space-y-4 max-h-[70vh] overflow-y-auto">
                    <!-- Type -->
                    <div>
                        <label class="block text-sm font-semibold mb-1.5">Type</label>
                        <div class="flex gap-2">
                            <button v-for="t in ['banner','promo','announcement']" :key="t"
                                @click="form.type = t as AdType"
                                :class="['flex-1 rounded-lg border py-2 text-xs font-semibold transition',
                                    form.type === t
                                        ? 'border-primary bg-primary/10 text-primary'
                                        : 'border-border hover:border-primary/50']">
                                {{ typeLabel[t as AdType] }}
                            </button>
                        </div>
                        <p class="text-xs text-muted-foreground mt-1">
                            <template v-if="form.type === 'banner'">Displays as a coloured strip at the top of the homepage.</template>
                            <template v-else-if="form.type === 'promo'">Shows in the "Today's Specials" section on the homepage.</template>
                            <template v-else>Informational notice card.</template>
                        </p>
                    </div>

                    <!-- Title -->
                    <div>
                        <label class="block text-sm font-semibold mb-1.5">Title <span class="text-red-500">*</span></label>
                        <input v-model="form.title" type="text" placeholder="e.g. Buy 1 Get 1 on Grilled Burgers today!"
                            class="w-full rounded-lg border px-3 py-2 text-sm bg-background focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>

                    <!-- Body -->
                    <div>
                        <label class="block text-sm font-semibold mb-1.5">Description <span class="text-muted-foreground font-normal">(optional)</span></label>
                        <textarea v-model="form.body" rows="2" placeholder="Extra detail shown alongside the title…"
                            class="w-full rounded-lg border px-3 py-2 text-sm bg-background focus:outline-none focus:ring-2 focus:ring-primary resize-none"></textarea>
                    </div>

                    <!-- Badge text -->
                    <div>
                        <label class="block text-sm font-semibold mb-1.5">Badge Label <span class="text-muted-foreground font-normal">(optional, max 50 chars)</span></label>
                        <input v-model="form.badge_text" type="text" placeholder="e.g. TODAY ONLY, NEW, 20% OFF"
                            class="w-full rounded-lg border px-3 py-2 text-sm bg-background focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>

                    <!-- BG Color (banners only) -->
                    <div v-if="form.type === 'banner'">
                        <label class="block text-sm font-semibold mb-1.5">Banner Colour</label>
                        <div class="flex gap-2">
                            <button v-for="c in colorOptions" :key="c.value"
                                @click="form.bg_color = c.value"
                                :class="['w-8 h-8 rounded-full border-2 transition', c.class,
                                    form.bg_color === c.value ? 'border-foreground scale-110' : 'border-transparent opacity-70 hover:opacity-100']"
                                :title="c.label"></button>
                        </div>
                    </div>

                    <!-- Date range -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold mb-1.5">Start Date <span class="text-muted-foreground font-normal">(optional)</span></label>
                            <input v-model="form.starts_at" type="date"
                                class="w-full rounded-lg border px-3 py-2 text-sm bg-background focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1.5">End Date <span class="text-muted-foreground font-normal">(optional)</span></label>
                            <input v-model="form.ends_at" type="date"
                                class="w-full rounded-lg border px-3 py-2 text-sm bg-background focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                    </div>

                    <!-- Order & Active -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold mb-1.5">Display Order</label>
                            <input v-model.number="form.display_order" type="number" min="0"
                                class="w-full rounded-lg border px-3 py-2 text-sm bg-background focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                        <div class="flex flex-col justify-end">
                            <label class="flex items-center gap-2 cursor-pointer pb-2">
                                <input v-model="form.is_active" type="checkbox" class="rounded" />
                                <span class="text-sm font-semibold">Active</span>
                            </label>
                        </div>
                    </div>

                    <!-- Preview -->
                    <div v-if="form.type === 'banner' && form.title" class="space-y-1.5">
                        <p class="text-xs font-semibold text-muted-foreground">Preview</p>
                        <div :class="['flex items-center justify-center gap-3 rounded-lg px-4 py-2.5 text-sm font-semibold', bgPreviewClass[form.bg_color]]">
                            <span v-if="form.badge_text" class="rounded-full bg-black/20 px-2 py-0.5 text-xs font-black uppercase">{{ form.badge_text }}</span>
                            <span>{{ form.title }}</span>
                            <span v-if="form.body" class="hidden sm:inline opacity-80 font-normal">— {{ form.body }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t p-4">
                    <button @click="closeModal" class="rounded-lg border px-4 py-2 text-sm font-medium hover:bg-muted">Cancel</button>
                    <button @click="save" :disabled="!form.title || saving"
                        class="rounded-lg bg-primary px-5 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-40">
                        {{ saving ? 'Saving…' : (editing ? 'Save Changes' : 'Create') }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
