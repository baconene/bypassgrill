<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, CalendarClock, MapPin, MessageCircle, Pencil, Phone, ReceiptText, Trash2, Users, X } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import type { Reservation } from '@/components/reservations/reservations';
import { dayLabel, errorText, isActive, peso, statusClass, statusLabel, statuses, timeLabel, totalOf, typeLabel } from '@/components/reservations/reservations';
import api from '@/utils/api';

const props = defineProps<{ id: number }>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Reservations', href: '/reservations' }, { title: 'Details', href: '#' }] } });

type Tender = { id: number; name: string };
const r = ref<Reservation | null>(null);
const failed = ref(false);
const tenders = ref<Tender[]>([]);
const stock = ref<Record<number, { soldOut: boolean; lowStock: boolean }>>({});
const day = computed(() => r.value?.reserved_local.slice(0, 10) ?? '');
const back = computed(() => (day.value ? `/reservations?date=${day.value}` : '/reservations'));
const itemCount = computed(() => r.value?.items.reduce((n, i) => n + i.quantity, 0) ?? 0);
const needsProduction = computed(() => r.value?.items.filter((i) => stock.value[i.product_id]?.soldOut) ?? []);

async function load() {
    try {
        r.value = (await api.get(`/api/v1/reservations/${props.id}`)).data;
    } catch {
        failed.value = true;
    }
}
async function loadExtras() {
    try {
        const [t, menu] = await Promise.all([api.get('/api/v1/payment-tenders'), api.get('/api/v1/reservations/menu')]);
        tenders.value = t.data;
        stock.value = Object.fromEntries(
            menu.data.flatMap((c: { products: { id: number; soldOut: boolean; lowStock: boolean }[] }) => c.products.map((p) => [p.id, p])),
        );
    } catch {
        /* the page still works without stock flags */
    }
}
async function setStatus(status: string) {
    if (!r.value) {
return;
}

    try {
        r.value = (await api.patch(`/api/v1/reservations/${r.value.id}`, { status })).data;
        toast.success('Status updated.');
    } catch (e) {
        toast.error(errorText(e, 'Could not update the status.'));
    }
}
async function remove() {
    if (!r.value || !confirm(`Delete the reservation for ${r.value.contact_name}?`)) {
return;
}

    try {
        await api.delete(`/api/v1/reservations/${r.value.id}`);
        toast.success('Reservation deleted.');
        router.visit(back.value);
    } catch (e) {
        toast.error(errorText(e, 'Could not delete the reservation.'));
    }
}

// Collect payment: the POS order is created only now, together with the payment record
const paying = ref(false);
const payment = ref({ payment_tender_id: 0, amount: 0, reference: '' });
const collecting = ref(false);
function openPayment() {
    if (!r.value) {
return;
}

    if (!r.value.order_type) {
return toast.error('Edit the reservation and choose an order type first.');
}

    if (!r.value.items.length) {
return toast.error('Edit the reservation and add the order first.');
}

    payment.value = { payment_tender_id: tenders.value[0]?.id ?? 0, amount: Number(r.value.planned_total), reference: '' };
    paying.value = true;
}
async function collect() {
    if (!r.value || !payment.value.payment_tender_id) {
return toast.error('Choose how the customer paid.');
}

    collecting.value = true;

    try {
        const { data } = await api.post(`/api/v1/reservations/${r.value.id}/checkout`, { ...payment.value, reference: payment.value.reference || null });
        r.value = data.reservation;
        paying.value = false;
        toast.success(data.payment_status === 'paid' ? `Paid. Order #${data.order_id} created.` : `Partial payment saved on order #${data.order_id}. Collect the rest in the POS.`);
    } catch (e) {
        toast.error(errorText(e, 'Could not record the payment.'));
    } finally {
        collecting.value = false;
    }
}
onMounted(() => {
    load();
    loadExtras();
});
</script>

<template>
    <Head :title="r ? `Reservation · ${r.contact_name}` : 'Reservation'" />
    <div class="mx-auto flex w-full min-w-0 max-w-2xl flex-col gap-3 p-3 pb-0 text-[#24231e] sm:p-6 sm:pb-0">
        <Link :href="back" class="flex w-fit items-center gap-1.5 rounded-lg py-2 pr-3 text-sm font-bold text-[#ad3b19]">
            <ArrowLeft class="size-4" /> Back to {{ day ? dayLabel(day, { month: 'short', day: 'numeric' }) : 'reservations' }}
        </Link>

        <p v-if="failed" class="rounded-xl border border-[#ded7cb] bg-[#fffcf6] p-4 text-sm">
            This reservation could not be loaded. It may have been deleted.
        </p>
        <p v-else-if="!r" role="status" class="rounded-xl border border-[#ded7cb] bg-[#fffcf6] p-4 text-sm">Loading…</p>

        <template v-else>
            <section class="rounded-xl border border-[#ded7cb] bg-[#fffcf6] p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h1 class="truncate text-2xl font-extrabold tracking-tight">{{ r.contact_name }}</h1>
                        <p class="mt-0.5 text-xs text-[#68665f]">
                            Reservation #{{ r.id }}<template v-if="r.source === 'online'"> · sent from the homepage</template>
                        </p>
                    </div>
                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-extrabold" :class="statusClass[r.status]">{{ statusLabel(r.status) }}</span>
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div class="flex gap-2"><CalendarClock class="mt-0.5 size-4 shrink-0 text-[#ad3b19]" /><div><dt class="text-[11px] text-[#68665f]">When</dt><dd class="font-bold">{{ dayLabel(day, { weekday: 'short', month: 'short', day: 'numeric' }) }}, {{ timeLabel(r.reserved_local) }}</dd></div></div>
                    <div class="flex gap-2"><Users class="mt-0.5 size-4 shrink-0 text-[#ad3b19]" /><div><dt class="text-[11px] text-[#68665f]">{{ typeLabel(r.order_type) }}</dt><dd class="font-bold">{{ r.party_size }} {{ r.party_size === 1 ? 'person' : 'people' }}</dd></div></div>
                    <div v-if="r.phone" class="flex gap-2"><Phone class="mt-0.5 size-4 shrink-0 text-[#ad3b19]" /><div><dt class="text-[11px] text-[#68665f]">Phone</dt><dd><a :href="`tel:${r.phone}`" class="font-bold underline">{{ r.phone }}</a></dd></div></div>
                    <div v-if="r.social_media" class="flex gap-2"><MessageCircle class="mt-0.5 size-4 shrink-0 text-[#ad3b19]" /><div class="min-w-0"><dt class="text-[11px] text-[#68665f]">Social media</dt><dd class="truncate font-bold">{{ r.social_media }}</dd></div></div>
                    <div v-if="r.customer_address" class="col-span-2 flex gap-2"><MapPin class="mt-0.5 size-4 shrink-0 text-[#ad3b19]" /><div><dt class="text-[11px] text-[#68665f]">Deliver to</dt><dd class="font-bold">{{ r.customer_address }}</dd></div></div>
                </dl>
                <p v-if="!r.order_type" class="mt-3 rounded-lg bg-red-50 p-2.5 text-xs font-semibold text-red-800">No order type yet. Edit the reservation and choose dine in, takeout or delivery.</p>
                <p v-if="r.notes" class="mt-3 rounded-lg bg-[#f6f2e9] p-2.5 text-sm">{{ r.notes }}</p>
                <label class="mt-4 flex items-center justify-between gap-3 text-sm font-bold">
                    Status
                    <select :value="r.status" class="rounded-lg border border-[#d4cdbf] bg-white px-3 py-2 text-sm" @change="setStatus(($event.target as HTMLSelectElement).value)">
                        <option v-for="s in statuses" :key="s" :value="s">{{ statusLabel(s) }}</option>
                    </select>
                </label>
            </section>

            <section class="rounded-xl border border-[#ded7cb] bg-[#fffcf6] p-4">
                <h2 class="flex items-center gap-2 font-extrabold"><ReceiptText class="size-4 text-[#ad3b19]" /> Order <span class="text-xs font-semibold text-[#68665f]">{{ itemCount }} item{{ itemCount === 1 ? '' : 's' }}</span></h2>
                <ul v-if="r.items.length" class="mt-2 divide-y divide-[#ece5da]">
                    <li v-for="i in r.items" :key="i.product_id" class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span class="min-w-0">
                            <span class="font-bold">{{ i.quantity }} × {{ i.product?.name ?? 'Item' }}</span>
                            <span v-if="!r.order_id && stock[i.product_id]?.soldOut" class="ml-1.5 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-extrabold text-amber-900">Needs production</span>
                        </span>
                        <span class="shrink-0 tabular-nums">{{ peso(Number(i.product?.price ?? 0) * i.quantity) }}</span>
                    </li>
                </ul>
                <p v-else class="mt-2 text-sm text-[#68665f]">No order planned yet.</p>
                <p class="mt-2 flex items-baseline justify-between border-t border-dashed border-[#d4cdbf] pt-2 text-sm">
                    <span>{{ r.order_id ? 'Order total' : 'Total at current prices' }}</span><strong class="text-lg text-[#ad3b19]">{{ peso(totalOf(r)) }}</strong>
                </p>
                <p v-if="r.order_id" class="mt-2 rounded-lg bg-emerald-50 p-2.5 text-sm text-emerald-900">
                    {{ r.order?.payment_status === 'paid' ? 'Paid' : 'Partly paid' }}.
                    <Link :href="`/orders/${r.order_id}`" class="font-bold underline">Open order #{{ r.order_id }}</Link>
                </p>
                <p v-else-if="needsProduction.length" class="mt-2 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-900">
                    Not enough stock yet for {{ needsProduction.map((i) => i.product?.name).join(', ') }}. Record the production run in Inventory before collecting payment.
                </p>
                <p v-else class="mt-2 text-xs text-[#68665f]">No order, stock use or sale is recorded until payment is collected.</p>
            </section>
        </template>

        <div v-if="r" class="sticky bottom-0 z-20 -mx-3 mt-auto border-t border-[#ded7cb] bg-[#fffcf6]/95 p-3 backdrop-blur sm:-mx-6">
            <div class="mx-auto flex max-w-2xl gap-2">
                <button v-if="!r.order_id" type="button" class="grid size-12 shrink-0 place-items-center rounded-lg border border-[#d4cdbf] text-red-700" aria-label="Delete reservation" @click="remove"><Trash2 class="size-5" /></button>
                <Link :href="`/reservations/${r.id}/edit`" class="flex h-12 flex-1 items-center justify-center gap-2 rounded-lg border border-[#24231e] font-bold"><Pencil class="size-4" /> Edit</Link>
                <button v-if="!r.order_id && isActive(r)" type="button" class="h-12 flex-[2] rounded-lg bg-[#c3441c] font-bold text-white hover:bg-[#a73513]" @click="openPayment">Collect payment</button>
            </div>
        </div>

        <div v-if="paying && r" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 sm:items-center" @click.self="paying = false">
            <section role="dialog" aria-modal="true" aria-labelledby="pay-title" class="max-h-[90vh] w-full overflow-y-auto rounded-t-2xl bg-[#fffcf6] p-4 sm:max-w-md sm:rounded-2xl">
                <div class="flex items-center justify-between">
                    <h2 id="pay-title" class="text-lg font-extrabold">Collect {{ peso(Number(r.planned_total)) }}</h2>
                    <button type="button" class="grid size-10 place-items-center" aria-label="Close" @click="paying = false"><X class="size-5" /></button>
                </div>
                <p class="text-xs text-[#68665f]">{{ r.contact_name }} · {{ typeLabel(r.order_type) }} · {{ itemCount }} items</p>
                <label class="mt-3 block text-xs font-bold">Paid with
                    <select v-model.number="payment.payment_tender_id" class="mt-1 w-full rounded-lg border border-[#d4cdbf] bg-white p-3 text-sm">
                        <option v-for="t in tenders" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                </label>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <label class="block text-xs font-bold">Amount received
                        <input v-model.number="payment.amount" type="number" min="0.01" step="0.01" inputmode="decimal" class="mt-1 w-full rounded-lg border border-[#d4cdbf] bg-white p-3 text-sm" />
                    </label>
                    <label class="block text-xs font-bold">Reference
                        <input v-model="payment.reference" placeholder="Optional" class="mt-1 w-full rounded-lg border border-[#d4cdbf] bg-white p-3 text-sm" />
                    </label>
                </div>
                <p class="mt-3 text-xs text-[#68665f]">Creates the POS order at current menu prices, uses the stock and records this payment. A smaller amount is saved as a partial payment.</p>
                <button type="button" class="mt-3 h-12 w-full rounded-lg bg-[#c3441c] font-bold text-white disabled:opacity-60" :disabled="collecting" @click="collect">
                    {{ collecting ? 'Recording…' : 'Record payment & create order' }}
                </button>
            </section>
        </div>
    </div>
</template>
