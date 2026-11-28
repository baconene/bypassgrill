<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, ChevronLeft, ChevronRight, ChevronRight as Go, Plus, RefreshCw, Users } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import type { Reservation } from '@/components/reservations/reservations';
import { dateKey, dayLabel, isActive, peso, statusClass, statusLabel, timeLabel, totalOf, typeLabel } from '@/components/reservations/reservations';
import '@/components/reservations/grill-page.css';
import api from '@/utils/api';

defineOptions({ layout: { breadcrumbs: [{ title: 'Reservations', href: '/reservations' }] } });

// The selected day lives in the URL, so Back from a reservation returns to the same day
const initial = new URLSearchParams(window.location.search).get('date');
const selected = ref(initial && /^\d{4}-\d{2}-\d{2}$/.test(initial) ? initial : dateKey(new Date()));
const month = ref(new Date(selected.value + 'T00:00:00'));
month.value.setDate(1);
const rows = ref<Reservation[]>([]);
const busy = ref(false);
const todayKey = dateKey(new Date());

const activeRows = computed(() => rows.value.filter(isActive));
const toConfirm = computed(() => rows.value.filter((r) => r.status === 'pending').length);
const unpaid = computed(() => activeRows.value.filter((r) => !r.order_id).reduce((n, r) => n + Number(r.planned_total || 0), 0));
const byDay = computed(() => {
    const map = new Map<string, Reservation[]>();
    rows.value.forEach((r) => {
        const k = r.reserved_local.slice(0, 10);
        map.set(k, [...(map.get(k) ?? []), r]);
    });

    return map;
});
const cells = computed(() => {
    const y = month.value.getFullYear(), m = month.value.getMonth();
    const out: ({ day: number; key: string; count: number; pending: boolean } | null)[] = Array(new Date(y, m, 1).getDay()).fill(null);

    for (let d = 1; d <= new Date(y, m + 1, 0).getDate(); d++) {
        const key = dateKey(new Date(y, m, d));
        const day = (byDay.value.get(key) ?? []).filter(isActive);
        out.push({ day: d, key, count: day.length, pending: day.some((r) => r.status === 'pending') });
    }

    return out;
});
const dayRows = computed(() => [...(byDay.value.get(selected.value) ?? [])].sort((a, b) => a.reserved_local.localeCompare(b.reserved_local)));
const dayGuests = computed(() => dayRows.value.filter(isActive).reduce((n, r) => n + Number(r.party_size || 0), 0));

function remember() {
    history.replaceState(history.state, '', `/reservations?date=${selected.value}`);
}
async function load() {
    busy.value = true;

    try {
        const y = month.value.getFullYear(), m = month.value.getMonth();
        rows.value = (await api.get('/api/v1/reservations', {
            params: { from: dateKey(new Date(y, m, 1)) + ' 00:00:00', to: dateKey(new Date(y, m + 1, 0)) + ' 23:59:59' },
        })).data;
    } catch {
        toast.error('Could not load reservations.');
    } finally {
        busy.value = false;
    }
}
function move(n: number) {
    month.value = new Date(month.value.getFullYear(), month.value.getMonth() + n, 1);
    load();
}
function goToday() {
    selected.value = todayKey;
    month.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    remember();
    load();
}
function choose(key: string) {
    selected.value = key;
    remember();
}
onMounted(load);
</script>

<template>
    <Head title="Reservations" />
    <div class="grill-page flex flex-col">
    <div class="mx-auto flex w-full min-w-0 max-w-3xl flex-1 flex-col gap-3 p-3 sm:p-6">
        <header class="flex items-center justify-between gap-2">
            <div>
                <p class="grill-eyebrow"><CalendarDays class="size-3.5" /> BYPASS GRILL / RESERVATIONS</p>
                <h1 class="grill-title mt-1.5">{{ month.toLocaleDateString('en-PH', { month: 'long' }) }} <em>{{ month.getFullYear() }}.</em></h1>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" class="grid size-10 place-items-center rounded-lg border border-[#ded7cb] bg-[#fffcf6]" :disabled="busy" aria-label="Refresh" @click="load">
                    <RefreshCw class="size-4" :class="{ 'animate-spin': busy }" />
                </button>
                <Link :href="`/reservations/new?date=${selected}`" class="flex h-10 items-center gap-1.5 rounded-lg bg-[#c3441c] px-3.5 text-sm font-bold text-white hover:bg-[#a73513]">
                    <Plus class="size-4" /> New
                </Link>
            </div>
        </header>

        <dl class="grid grid-cols-[repeat(3,minmax(0,1fr))] gap-2">
            <div class="rounded-lg border border-[#e4c4ab] bg-[#f2e5d8] px-3 py-2">
                <dt class="text-[11px] font-semibold text-[#68665f]">This month</dt>
                <dd class="text-xl font-extrabold text-[#ad3b19]">{{ activeRows.length }}</dd>
            </div>
            <div class="rounded-lg border border-[#ded7cb] bg-[#fffcf6] px-3 py-2">
                <dt class="text-[11px] font-semibold text-[#68665f]">To confirm</dt>
                <dd class="text-xl font-extrabold">{{ toConfirm }}</dd>
            </div>
            <div class="rounded-lg border border-[#ded7cb] bg-[#fffcf6] px-3 py-2">
                <dt class="text-[11px] font-semibold text-[#68665f]">Unpaid orders</dt>
                <dd class="truncate text-xl font-extrabold">{{ peso(unpaid).replace('.00', '') }}</dd>
            </div>
        </dl>

        <section class="rounded-xl border border-[#ded7cb] bg-[#fffcf6] p-3" aria-label="Calendar">
            <div class="mb-2 flex items-center justify-between">
                <button type="button" class="grid size-9 place-items-center rounded-lg border border-[#ded7cb]" aria-label="Previous month" @click="move(-1)"><ChevronLeft class="size-4" /></button>
                <button type="button" class="rounded-lg border border-[#ded7cb] px-3 py-1.5 text-xs font-bold" @click="goToday">Today</button>
                <button type="button" class="grid size-9 place-items-center rounded-lg border border-[#ded7cb]" aria-label="Next month" @click="move(1)"><ChevronRight class="size-4" /></button>
            </div>
            <div class="grid grid-cols-[repeat(7,minmax(0,1fr))] text-center text-[10px] font-bold tracking-wider text-[#777268]">
                <span v-for="(d, i) in ['S', 'M', 'T', 'W', 'T', 'F', 'S']" :key="i" class="py-1">{{ d }}</span>
            </div>
            <div class="grid grid-cols-[repeat(7,minmax(0,1fr))] gap-1">
                <template v-for="(cell, i) in cells" :key="i">
                    <span v-if="!cell" aria-hidden="true"></span>
                    <button
                        v-else
                        type="button"
                        :aria-pressed="selected === cell.key"
                        :aria-label="`${dayLabel(cell.key)}, ${cell.count} reservation${cell.count === 1 ? '' : 's'}`"
                        class="relative flex h-11 flex-col items-center justify-center rounded-lg text-sm font-bold transition sm:h-14"
                        :class="[
                            selected === cell.key ? 'bg-[#24231e] text-white' : cell.count ? 'bg-[#f2e5d8] text-[#24231e]' : 'hover:bg-[#f6f2e9]',
                            cell.key === todayKey && selected !== cell.key ? 'ring-1 ring-[#c3441c]' : '',
                        ]"
                        @click="choose(cell.key)"
                    >
                        {{ cell.day }}
                        <span v-if="cell.count" class="mt-0.5 text-[10px] font-extrabold leading-none" :class="selected === cell.key ? 'text-[#ffb35c]' : 'text-[#ad3b19]'">
                            {{ cell.count }}
                        </span>
                        <i v-if="cell.pending" class="absolute right-1 top-1 size-1.5 rounded-full bg-amber-500" aria-hidden="true"></i>
                    </button>
                </template>
            </div>
            <p class="mt-2 text-[11px] text-[#777268]">Numbers show reservations per day · <i class="inline-block size-1.5 rounded-full bg-amber-500"></i> has one to confirm</p>
        </section>

        <section class="rounded-xl border border-[#ded7cb] bg-[#fffcf6]" aria-live="polite">
            <div class="flex items-baseline justify-between gap-2 border-b border-[#ece5da] px-3 py-2.5">
                <h2 class="font-extrabold">{{ dayLabel(selected) }}</h2>
                <span class="text-xs text-[#68665f]">{{ dayRows.length }} booking{{ dayRows.length === 1 ? '' : 's' }}<template v-if="dayGuests"> · {{ dayGuests }} guests</template></span>
            </div>
            <ul v-if="dayRows.length" class="divide-y divide-[#ece5da]">
                <li v-for="r in dayRows" :key="r.id">
                    <Link :href="`/reservations/${r.id}`" class="flex items-center gap-3 px-3 py-3 hover:bg-[#f9f5ee]" :class="{ 'opacity-60': !isActive(r) }">
                        <span class="w-16 shrink-0 text-sm font-extrabold">{{ timeLabel(r.reserved_local) }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-bold">{{ r.contact_name }}</span>
                            <span class="block truncate text-xs text-[#68665f]">
                                {{ typeLabel(r.order_type) }} · {{ r.party_size }} pax<template v-if="r.items.length"> · {{ r.items.reduce((n, i) => n + i.quantity, 0) }} items {{ peso(totalOf(r)) }}</template>
                            </span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end gap-1">
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-extrabold" :class="statusClass[r.status]">{{ statusLabel(r.status) }}</span>
                            <span v-if="r.order_id" class="text-[10px] font-extrabold text-emerald-800">Paid</span>
                            <span v-else-if="r.source === 'online'" class="text-[10px] font-bold text-[#ad3b19]">Homepage</span>
                        </span>
                        <Go class="size-4 shrink-0 text-[#a8a192]" />
                    </Link>
                </li>
            </ul>
            <div v-else class="flex flex-col items-center gap-2 px-4 py-8 text-center text-sm text-[#68665f]">
                <Users class="size-6 text-[#ad3b19]" />
                No reservations on this day.
                <Link :href="`/reservations/new?date=${selected}`" class="font-bold text-[#ad3b19] underline">Add one</Link>
            </div>
        </section>
        </div>
    </div>
</template>
