<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ChevronDown, Minus, Plus, Search, ShoppingBag } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import type { MenuCategory, MenuProduct, OrderType, Reservation } from '@/components/reservations/reservations';
import { errorText, orderTypes, peso, statusLabel, statuses } from '@/components/reservations/reservations';
import '@/components/reservations/grill-page.css';
import api from '@/utils/api';

const props = defineProps<{ id: number | null }>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Reservations', href: '/reservations' }, { title: 'Reservation', href: '#' }] } });

const startDate = new URLSearchParams(window.location.search).get('date');
const form = ref({
    contact_name: '',
    phone: '',
    social_media: '',
    reserved_at: startDate && /^\d{4}-\d{2}-\d{2}$/.test(startDate) ? `${startDate}T18:00` : '',
    party_size: 2,
    status: 'confirmed',
    notes: '',
    order_type: '' as OrderType | '',
    customer_address: '',
});
const existing = ref<Reservation | null>(null);
const cart = ref<Record<number, number>>({});
const menu = ref<MenuCategory[]>([]);
const category = ref<number | 'all'>('all');
const search = ref('');
const moreOpen = ref(false);
const saving = ref(false);
const loading = ref(true);
const locked = computed(() => existing.value?.order_id != null);
const back = computed(() => (existing.value ? `/reservations/${existing.value.id}` : `/reservations${startDate ? `?date=${startDate}` : ''}`));

const products = computed(() => menu.value.flatMap((c) => c.products));
const productById = computed(() => new Map(products.value.map((p) => [p.id, p])));
const shown = computed(() => {
    const q = search.value.trim().toLowerCase();
    const pool = category.value === 'all' ? products.value : (menu.value.find((c) => c.id === category.value)?.products ?? []);

    return q ? products.value.filter((p) => p.name.toLowerCase().includes(q)) : pool;
});
const lines = computed(() =>
    Object.entries(cart.value)
        .filter(([, qty]) => qty > 0)
        .map(([id, quantity]) => ({ product: productById.value.get(Number(id)), product_id: Number(id), quantity })),
);
const count = computed(() => lines.value.reduce((n, l) => n + l.quantity, 0));
const total = computed(() => lines.value.reduce((n, l) => n + (l.product?.price ?? 0) * l.quantity, 0));
const needsProduction = computed(() => lines.value.filter((l) => l.product?.soldOut).length);

function add(p: MenuProduct | number, amount = 1) {
    const id = typeof p === 'number' ? p : p.id;
    const next = Math.max(0, Math.min(999, (cart.value[id] ?? 0) + amount));

    if (next) {
cart.value[id] = next;
} else {
delete cart.value[id];
}
}

async function load() {
    try {
        const requests: Promise<{ data: unknown }>[] = [api.get('/api/v1/reservations/menu')];

        if (props.id) {
requests.push(api.get(`/api/v1/reservations/${props.id}`));
}

        const [m, r] = await Promise.all(requests);
        menu.value = m.data as MenuCategory[];

        if (r) {
            const x = r.data as Reservation;
            existing.value = x;
            form.value = {
                contact_name: x.contact_name, phone: x.phone ?? '', social_media: x.social_media ?? '', reserved_at: x.reserved_local,
                party_size: x.party_size, status: x.status, notes: x.notes ?? '', order_type: x.order_type ?? '', customer_address: x.customer_address ?? '',
            };
            cart.value = Object.fromEntries(x.items.map((i) => [i.product_id, i.quantity]));
            moreOpen.value = !!(x.social_media || x.notes);
        }
    } catch {
        toast.error('Could not load the menu.');
    } finally {
        loading.value = false;
    }
}

async function save() {
    const f = form.value;

    if (!f.contact_name.trim()) {
return toast.error('Add the customer name.');
}

    if (!f.reserved_at) {
return toast.error('Choose the date and time.');
}

    if (!f.order_type) {
return toast.error('Choose the order type: dine in, takeout or delivery.');
}

    if (f.order_type === 'delivery' && !f.customer_address.trim()) {
return toast.error('Add the delivery address.');
}

    saving.value = true;
    const body: Record<string, unknown> = { ...f, reserved_at: f.reserved_at.replace('T', ' ') };

    if (!locked.value) {
body.items = lines.value.map(({ product_id, quantity }) => ({ product_id, quantity }));
}

    try {
        const { data } = existing.value
            ? await api.patch(`/api/v1/reservations/${existing.value.id}`, body)
            : await api.post('/api/v1/reservations', body);
        toast.success(existing.value ? 'Reservation updated.' : 'Reservation saved. No order is created until it is paid.');
        router.visit(`/reservations/${data.id}`);
    } catch (e) {
        toast.error(errorText(e, 'Could not save the reservation.'));
    } finally {
        saving.value = false;
    }
}
onMounted(load);
</script>

<template>
    <Head :title="id ? 'Edit reservation' : 'New reservation'" />
    <div class="grill-page flex flex-col">
    <div class="mx-auto flex w-full min-w-0 max-w-5xl flex-1 flex-col gap-3 p-3 pb-0 sm:p-6 sm:pb-0">
        <header>
            <Link :href="back" class="flex w-fit items-center gap-1.5 py-1 pr-3 text-sm font-bold text-[#ad3b19]"><ArrowLeft class="size-4" /> Back</Link>
            <p class="grill-eyebrow mt-2">BYPASS GRILL / {{ id ? 'EDIT' : 'NEW' }} RESERVATION</p>
            <h1 class="grill-title mt-1.5">{{ id ? 'Update the' : 'Plan the' }} <em>order.</em></h1>
        </header>

        <div class="grid grid-cols-[minmax(0,1fr)] gap-3 lg:grid-cols-[minmax(0,340px)_minmax(0,1fr)] lg:items-start">
            <!-- Details -->
            <section class="min-w-0 rounded-xl border border-[#ded7cb] bg-[#fffcf6] p-3 lg:sticky lg:top-3" aria-label="Reservation details">
                <fieldset>
                    <legend class="mb-1.5 text-xs font-extrabold">Order type <span class="text-[#ad3b19]">*</span></legend>
                    <div class="grid grid-cols-3 gap-1.5">
                        <button
                            v-for="t in orderTypes"
                            :key="t.value"
                            type="button"
                            :aria-pressed="form.order_type === t.value"
                            class="h-10 rounded-lg border border-[#d4cdbf] bg-white text-sm font-bold aria-pressed:border-[#24231e] aria-pressed:bg-[#24231e] aria-pressed:text-white"
                            @click="form.order_type = t.value"
                        >
                            {{ t.label }}
                        </button>
                    </div>
                </fieldset>
                <div class="mt-2.5 grid grid-cols-2 gap-2">
                    <label class="col-span-2 text-xs font-bold">Customer name
                        <input v-model="form.contact_name" autocomplete="off" class="mt-1 w-full rounded-lg border border-[#d4cdbf] bg-white px-3 py-2.5 text-sm font-normal" />
                    </label>
                    <label class="text-xs font-bold">Phone
                        <input v-model="form.phone" type="tel" inputmode="tel" placeholder="09xx" class="mt-1 w-full rounded-lg border border-[#d4cdbf] bg-white px-3 py-2.5 text-sm font-normal" />
                    </label>
                    <label class="text-xs font-bold">{{ form.order_type === 'dine_in' ? 'Guests' : 'People' }}
                        <input v-model.number="form.party_size" type="number" min="1" inputmode="numeric" class="mt-1 w-full rounded-lg border border-[#d4cdbf] bg-white px-3 py-2.5 text-sm font-normal" />
                    </label>
                    <label class="col-span-2 text-xs font-bold">{{ form.order_type === 'delivery' ? 'Delivery date & time' : form.order_type === 'takeout' ? 'Pickup date & time' : 'Date & time' }}
                        <input v-model="form.reserved_at" type="datetime-local" class="mt-1 w-full rounded-lg border border-[#d4cdbf] bg-white px-3 py-2.5 text-sm font-normal" />
                    </label>
                    <label v-if="form.order_type === 'delivery'" class="col-span-2 text-xs font-bold">Delivery address
                        <textarea v-model="form.customer_address" rows="2" placeholder="Street, barangay, landmark" class="mt-1 w-full rounded-lg border border-[#d4cdbf] bg-white px-3 py-2 text-sm font-normal" />
                    </label>
                </div>
                <button type="button" class="mt-2 flex items-center gap-1 text-xs font-bold text-[#68665f]" :aria-expanded="moreOpen" @click="moreOpen = !moreOpen">
                    <ChevronDown class="size-3.5 transition" :class="{ 'rotate-180': moreOpen }" /> Status, social media, notes
                </button>
                <div v-if="moreOpen" class="mt-2 grid gap-2">
                    <label class="text-xs font-bold">Status
                        <select v-model="form.status" class="mt-1 w-full rounded-lg border border-[#d4cdbf] bg-white px-3 py-2.5 text-sm font-normal">
                            <option v-for="s in statuses" :key="s" :value="s">{{ statusLabel(s) }}</option>
                        </select>
                    </label>
                    <label class="text-xs font-bold">Social media
                        <input v-model="form.social_media" placeholder="@username or profile" class="mt-1 w-full rounded-lg border border-[#d4cdbf] bg-white px-3 py-2.5 text-sm font-normal" />
                    </label>
                    <label class="text-xs font-bold">Notes
                        <textarea v-model="form.notes" rows="2" placeholder="Occasion, seating, requests" class="mt-1 w-full rounded-lg border border-[#d4cdbf] bg-white px-3 py-2 text-sm font-normal" />
                    </label>
                </div>
            </section>

            <!-- Mini POS -->
            <section class="min-w-0 rounded-xl border border-[#ded7cb] bg-[#fffcf6] p-3" aria-label="Order">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-extrabold">Order</h2>
                    <span class="text-xs text-[#68665f]">Optional · nothing is charged until paid</span>
                </div>
                <p v-if="locked" class="mt-2 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">
                    Paid: order #{{ existing?.order_id }} holds the items now.
                    <Link :href="`/orders/${existing?.order_id}`" class="font-bold underline">Change them in the order</Link>.
                </p>
                <template v-else>
                    <label class="mt-2 flex items-center gap-2 rounded-lg border border-[#d4cdbf] bg-white px-3">
                        <Search class="size-4 text-[#777268]" />
                        <input v-model="search" placeholder="Search the menu" aria-label="Search the menu" class="w-full border-0 bg-transparent py-2.5 text-sm outline-none" />
                    </label>
                    <div v-if="!search" class="-mx-3 mt-2 flex gap-1.5 overflow-x-auto px-3 pb-1" role="group" aria-label="Category">
                        <button
                            v-for="c in [{ id: 'all' as const, name: 'All' }, ...menu]"
                            :key="c.id"
                            type="button"
                            :aria-pressed="category === c.id"
                            class="h-9 shrink-0 rounded-full border border-[#d4cdbf] bg-white px-3.5 text-xs font-bold aria-pressed:border-[#c3441c] aria-pressed:bg-[#c3441c] aria-pressed:text-white"
                            @click="category = c.id"
                        >
                            {{ c.name }}
                        </button>
                    </div>
                    <p v-if="loading" role="status" class="py-6 text-center text-sm text-[#68665f]">Loading the menu…</p>
                    <div v-else class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                        <button
                            v-for="p in shown"
                            :key="p.id"
                            type="button"
                            class="relative flex flex-col overflow-hidden rounded-lg border bg-white text-left transition active:scale-[0.98]"
                            :class="cart[p.id] ? 'border-[#c3441c] ring-1 ring-[#c3441c]' : 'border-[#e3dec8] hover:border-[#c3441c]'"
                            :aria-label="`Add ${p.name}, ${peso(p.price)}${cart[p.id] ? `, ${cart[p.id]} in order` : ''}`"
                            @click="add(p)"
                        >
                            <span class="block h-16 w-full bg-[#f2e5d8] sm:h-24">
                                <img v-if="p.image" :src="p.image" alt="" class="size-full object-cover" loading="lazy" @error="($event.target as HTMLImageElement).style.visibility = 'hidden'" />
                                <span v-else class="grid size-full place-items-center text-xl font-extrabold text-[#ad3b19]">{{ p.name.replace(/^[A-Z]+ - /, '').charAt(0) }}</span>
                            </span>
                            <span class="flex flex-1 flex-col gap-0.5 p-2">
                                <span class="line-clamp-2 text-xs font-bold leading-snug">{{ p.name }}</span>
                                <span class="text-xs font-extrabold text-[#ad3b19]">{{ peso(p.price) }}</span>
                                <span v-if="p.soldOut" class="w-fit rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-extrabold text-amber-900">Needs production</span>
                                <span v-else-if="p.lowStock" class="w-fit rounded bg-stone-100 px-1.5 py-0.5 text-[10px] font-bold text-stone-700">Low stock</span>
                            </span>
                            <b v-if="cart[p.id]" class="absolute right-1.5 top-1.5 grid size-7 place-items-center rounded-full bg-[#c3441c] text-xs text-white">{{ cart[p.id] }}</b>
                        </button>
                        <p v-if="!shown.length" class="col-span-full py-6 text-center text-sm text-[#68665f]">No menu items match.</p>
                    </div>
                </template>
            </section>
        </div>

        <!-- Order summary and save -->
        <div class="sticky bottom-0 z-20 -mx-3 mt-auto border-t border-[#ded7cb] bg-[#fffcf6]/95 p-3 backdrop-blur sm:-mx-6">
            <div class="mx-auto max-w-5xl">
                <ul v-if="lines.length && !locked" class="mb-2 flex max-h-28 flex-col gap-1 overflow-y-auto" aria-label="Items in this order">
                    <li v-for="l in lines" :key="l.product_id" class="flex items-center justify-between gap-2 text-sm">
                        <span class="min-w-0 truncate"><b>{{ l.product?.name ?? 'Item' }}</b> <span class="text-xs text-[#68665f]">{{ peso((l.product?.price ?? 0) * l.quantity) }}</span></span>
                        <span class="flex shrink-0 items-center gap-1.5">
                            <button type="button" class="grid size-8 place-items-center rounded-lg border border-[#d4cdbf] bg-white" :aria-label="`Remove one ${l.product?.name}`" @click="add(l.product_id, -1)"><Minus class="size-3.5" /></button>
                            <b class="w-6 text-center tabular-nums">{{ l.quantity }}</b>
                            <button type="button" class="grid size-8 place-items-center rounded-lg border border-[#d4cdbf] bg-white" :aria-label="`Add one ${l.product?.name}`" @click="add(l.product_id)"><Plus class="size-3.5" /></button>
                        </span>
                    </li>
                </ul>
                <div class="flex items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-1.5 text-sm font-extrabold"><ShoppingBag class="size-4 text-[#ad3b19]" /> {{ count }} item{{ count === 1 ? '' : 's' }} · {{ peso(total) }}</p>
                        <p class="truncate text-[11px] text-[#68665f]">
                            {{ needsProduction ? `${needsProduction} item${needsProduction === 1 ? '' : 's'} need production before payment` : 'Order is created only when paid' }}
                        </p>
                    </div>
                    <button type="button" class="h-12 shrink-0 rounded-lg bg-[#c3441c] px-6 font-bold text-white hover:bg-[#a73513] disabled:opacity-60" :disabled="saving || loading" @click="save">
                        {{ saving ? 'Saving…' : 'Save' }}
                    </button>
                </div>
            </div>
        </div>
        </div>
    </div>
</template>
