<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, ChevronLeft, ChevronRight, Clock3, Minus, Plus, ReceiptText, RefreshCw, Search, Users, UserRoundPlus, X } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import api from '@/utils/api';

defineOptions({ layout: { breadcrumbs: [{ title: 'Reservations', href: '/reservations' }] } });

type OrderType = 'dine_in' | 'takeout' | 'delivery';
type Product = { id: number; name: string; price: number | string };
type Item = { product_id: number; quantity: number; product?: Product | null };
type Reservation = {
    id: number; contact_name: string; phone?: string | null; social_media?: string | null; reserved_at: string;
    reserved_local: string; party_size: number;
    status: string; notes?: string | null; order_type: OrderType | null; customer_address?: string | null; source: 'staff' | 'online';
    order_id: number | null; paid_at: string | null; planned_total: number; items: Item[];
    order?: { id: number; total_amount: string; payment_status: string } | null;
};
type Tender = { id: number; name: string };

const orderTypes: { value: OrderType; label: string }[] = [
    { value: 'dine_in', label: 'Dine in' }, { value: 'takeout', label: 'Takeout' }, { value: 'delivery', label: 'Delivery' },
];
const typeLabel = (type: OrderType | null) => orderTypes.find((t) => t.value === type)?.label ?? 'No order type';
const statuses = ['pending', 'confirmed', 'arrived', 'completed', 'cancelled', 'no_show'];
const peso = (n: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(n);

const rows = ref<Reservation[]>([]), products = ref<Product[]>([]), tenders = ref<Tender[]>([]);
const month = ref(new Date()), busy = ref(false), saving = ref(false);
const key = (d: Date) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
const selected = ref(key(new Date()));
const blank = () => ({ contact_name: '', phone: '', social_media: '', reserved_at: '', party_size: 1, status: 'confirmed', notes: '', order_type: '' as OrderType | '', customer_address: '' });
const form = ref(blank());
const planned = ref<{ product_id: number; quantity: number }[]>([]);
const editing = ref<Reservation | null>(null);
const search = ref('');

const active = (x: Reservation) => !['cancelled', 'no_show'].includes(x.status);
const dayOf = (x: Reservation) => x.reserved_local.slice(0, 10);
const days = computed(() => {
    const y = month.value.getFullYear(), m = month.value.getMonth(), first = new Date(y, m, 1);
    const out: ({ d: number; k: string; count: number; guests: number } | null)[] = [];

    for (let i = 0; i < first.getDay(); i++) {
out.push(null);
}

    for (let d = 1; d <= new Date(y, m + 1, 0).getDate(); d++) {
        const k = key(new Date(y, m, d)), bookings = rows.value.filter((x) => dayOf(x) === k && active(x));
        out.push({ d, k, count: bookings.length, guests: bookings.reduce((n, x) => n + (Number(x.party_size) || 0), 0) });
    }

    return out;
});
const selectedRows = computed(() => rows.value.filter((x) => dayOf(x) === selected.value).sort((a, b) => a.reserved_local.localeCompare(b.reserved_local)));
const activeRows = computed(() => rows.value.filter(active));
const guests = computed(() => activeRows.value.reduce((n, x) => n + (Number(x.party_size) || 0), 0));
const toConfirm = computed(() => rows.value.filter((x) => x.status === 'pending').length);
// Planned but unpaid sales are not in the sales records yet: show them so staff plan prep and cash around them
const plannedSales = computed(() => activeRows.value.filter((x) => !x.order_id).reduce((n, x) => n + Number(x.planned_total || 0), 0));
const selectedLabel = computed(() => new Date(selected.value + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric' }));
const dayPrep = computed(() => {
    const totals = new Map<string, number>();
    selectedRows.value.filter(active).forEach((x) => x.items.forEach((i) => totals.set(i.product?.name ?? 'Item', (totals.get(i.product?.name ?? 'Item') ?? 0) + i.quantity)));

    return [...totals].map(([name, quantity]) => ({ name, quantity }));
});

const productById = computed(() => new Map(products.value.map((p) => [p.id, p])));
const matches = computed(() => {
    const q = search.value.trim().toLowerCase();

    return (q ? products.value.filter((p) => p.name.toLowerCase().includes(q)) : products.value).slice(0, 8);
});
const plannedTotal = computed(() => planned.value.reduce((n, i) => n + Number(productById.value.get(i.product_id)?.price ?? 0) * i.quantity, 0));
function addItem(id: number, amount = 1) {
    const line = planned.value.find((i) => i.product_id === id);

    if (line) {
line.quantity = Math.max(0, Math.min(999, line.quantity + amount));
} else if (amount > 0) {
planned.value.push({ product_id: id, quantity: amount });
}

    planned.value = planned.value.filter((i) => i.quantity > 0);
}

async function load() {
    busy.value = true;

    try {
        const y = month.value.getFullYear(), m = month.value.getMonth();
        rows.value = (await api.get('/api/v1/reservations', { params: { from: key(new Date(y, m, 1)) + ' 00:00:00', to: key(new Date(y, m + 1, 0)) + ' 23:59:59' } })).data;
    } catch {
 toast.error('Could not load reservations.'); 
} finally {
 busy.value = false; 
}
}
async function loadMenu() {
    try {
        const [p, t] = await Promise.all([api.get('/api/v1/products'), api.get('/api/v1/payment-tenders')]);
        products.value = p.data; tenders.value = t.data;
    } catch {
 toast.error('Could not load the menu.'); 
}
}
function move(n: number) {
 month.value = new Date(month.value.getFullYear(), month.value.getMonth() + n, 1); load(); 
}
function today() {
 month.value = new Date(); selected.value = key(new Date()); load(); 
}
function choose(k: string) {
 selected.value = k;

 if (!form.value.reserved_at) {
form.value.reserved_at = k + 'T18:00';
} 
}
function reset() {
 form.value = blank(); planned.value = []; editing.value = null; search.value = ''; 
}
function edit(x: Reservation) {
    editing.value = x;
    form.value = { contact_name: x.contact_name, phone: x.phone ?? '', social_media: x.social_media ?? '', reserved_at: x.reserved_local,
        party_size: x.party_size, status: x.status, notes: x.notes ?? '', order_type: x.order_type ?? '', customer_address: x.customer_address ?? '' };
    planned.value = x.items.map((i) => ({ product_id: i.product_id, quantity: i.quantity }));
    document.querySelector('.form-panel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
const errorText = (e: any, fallback: string) => {
    const errors = e?.response?.data?.errors;

    return errors ? Object.values(errors).flat().join(' ') : e?.response?.data?.message ?? fallback;
};
async function save() {
    if (!form.value.contact_name || !form.value.reserved_at) {
 toast.error('Contact name and reservation time are required.');

 return; 
}

    if (!form.value.order_type) {
 toast.error('Choose the order type: dine in, takeout or delivery.');

 return; 
}

    if (form.value.order_type === 'delivery' && !form.value.customer_address.trim()) {
 toast.error('Add the delivery address.');

 return; 
}

    saving.value = true;
    const body: Record<string, unknown> = { ...form.value, reserved_at: form.value.reserved_at.replace('T', ' ') };

    if (!editing.value?.order_id) {
body.items = planned.value;
}

    try {
        if (editing.value) {
await api.patch('/api/v1/reservations/' + editing.value.id, body);
} else {
await api.post('/api/v1/reservations', body);
}

        toast.success(editing.value ? 'Reservation updated.' : 'Reservation saved. No order is created until it is paid.');
        reset(); await load();
    } catch (e) {
 toast.error(errorText(e, 'Could not save reservation.')); 
} finally {
 saving.value = false; 
}
}
async function status(x: Reservation, s: string) {
    try {
 await api.patch('/api/v1/reservations/' + x.id, { status: s }); await load(); 
} catch (e) {
 toast.error(errorText(e, 'Could not update reservation.')); 
}
}
async function del(x: Reservation) {
    if (!confirm('Delete this reservation?')) {
return;
}

    try {
 await api.delete('/api/v1/reservations/' + x.id); toast.success('Reservation deleted.'); await load(); 
} catch (e) {
 toast.error(errorText(e, 'Could not delete reservation.')); 
}
}

// Collect payment: only now is the POS order created, together with the payment record
const paying = ref<Reservation | null>(null);
const payment = ref({ payment_tender_id: 0, amount: 0, reference: '' });
const collecting = ref(false);
function openPayment(x: Reservation) {
    if (!x.order_type) {
 toast.error('Edit the reservation and choose an order type first.');

 return; 
}

    if (!x.items.length) {
 toast.error('Edit the reservation and add the planned order first.');

 return; 
}

    paying.value = x;
    payment.value = { payment_tender_id: tenders.value[0]?.id ?? 0, amount: Number(x.planned_total), reference: '' };
}
async function collect() {
    if (!paying.value || !payment.value.payment_tender_id) {
 toast.error('Choose how the customer paid.');

 return; 
}

    collecting.value = true;

    try {
        const { data } = await api.post(`/api/v1/reservations/${paying.value.id}/checkout`, { ...payment.value, reference: payment.value.reference || null });
        toast.success(data.payment_status === 'paid' ? `Paid. Order #${data.order_id} created.` : `Partial payment recorded on order #${data.order_id}. Collect the rest in the POS.`);
        paying.value = null; await load();
    } catch (e) {
 toast.error(errorText(e, 'Could not record the payment.')); 
} finally {
 collecting.value = false; 
}
}

onMounted(() => {
 load(); loadMenu(); 
});
</script>

<template>
<Head title="Reservations"/>
<div class="reservation-dashboard">
<header class="dashboard-heading"><div><p class="eyebrow"><CalendarDays :size="14"/> BYPASS GRILL / RESERVATIONS</p><h1>Plan the <em>next table.</em></h1><p class="intro">Plot bookings, plan what each party will order, and turn it into a POS order only once it is paid.</p></div><div class="heading-meta"><span>{{month.toLocaleDateString('en-PH',{month:'long',year:'numeric'})}}</span><button class="refresh-button" :disabled="busy" @click="load"><RefreshCw :size="14" :class="{spinning:busy}"/>{{busy?'Refreshing…':'Refresh calendar'}}</button></div></header>
<section class="work-bar"><div class="work-bar-copy"><strong>Know who's coming and what to prep.</strong><span>A planned order uses no stock and records no sale. When the customer pays, collect payment here: the order is created and the payment recorded together.</span></div><button class="primary-action" @click="today"><CalendarDays :size="17"/>Today</button></section>
<section class="metric-grid"><article class="metric metric-featured"><p>Active reservations</p><strong>{{activeRows.length}}</strong><span>{{guests}} expected guests this month</span></article><article class="metric"><p>To confirm</p><strong>{{toConfirm}}</strong><span>Pending, incl. homepage requests</span></article><article class="metric"><p>Planned, unpaid</p><strong>{{peso(plannedSales)}}</strong><span>Not in sales until paid</span></article><article class="metric"><p>Selected day</p><strong>{{selectedRows.filter(active).length}}</strong><span>{{selectedLabel}}</span></article></section>
<div class="dashboard-columns"><section class="panel calendar-panel"><div class="panel-heading"><div><p class="eyebrow">BOOKING CALENDAR</p><h2>{{month.toLocaleDateString('en-PH',{month:'long',year:'numeric'})}}</h2></div><div class="month-actions"><button aria-label="Previous month" @click="move(-1)"><ChevronLeft :size="17"/></button><button @click="today">Today</button><button aria-label="Next month" @click="move(1)"><ChevronRight :size="17"/></button></div></div><div class="week"><b v-for="d in ['SUN','MON','TUE','WED','THU','FRI','SAT']" :key="d">{{d}}</b></div><div class="calendar"><button v-for="(d,i) in days" :key="i" class="day" :class="{blank:!d,selected:d&&selected===d.k,booked:d&&d.count}" :disabled="!d" @click="d&&choose(d.k)"><template v-if="d"><span class="date">{{d.d}}</span><span v-if="d.count" class="booking-count">{{d.count}} booking{{d.count>1?'s':''}}</span><small v-if="d.guests">{{d.guests}} guest{{d.guests>1?'s':''}}</small><i v-if="d.count"></i></template></button></div></section>
<aside class="panel form-panel"><div class="form-icon"><UserRoundPlus :size="20"/></div><p class="eyebrow">{{editing?'EDIT RESERVATION':'NEW RESERVATION'}}</p><h2>{{editing?'Update the booking.':'Save a booking.'}}</h2><p class="panel-copy">Contact details and the planned order stay with the reservation.</p>
<label>Contact name<input v-model="form.contact_name" placeholder="Customer name"></label><div class="field-pair"><label>Phone<input v-model="form.phone" placeholder="09xx xxx xxxx"></label><label>Party size<input v-model.number="form.party_size" type="number" min="1"></label></div><label>Social media<input v-model="form.social_media" placeholder="@username or profile"></label><label>Date & time<input v-model="form.reserved_at" type="datetime-local"></label>
<fieldset class="type-picker"><legend>Order type <b aria-hidden="true">*</b></legend><div><button v-for="t in orderTypes" :key="t.value" type="button" :aria-pressed="form.order_type===t.value" @click="form.order_type=t.value">{{t.label}}</button></div></fieldset>
<label v-if="form.order_type==='delivery'">Delivery address<textarea v-model="form.customer_address" rows="2" placeholder="Street, barangay, landmark"></textarea></label>
<div class="planner"><p class="planner-title"><ReceiptText :size="14"/> Planned order <span>optional</span></p>
<p v-if="editing?.order_id" class="planner-note">Paid: order #{{editing.order_id}} holds the items now. Change them in the POS.</p>
<template v-else><label class="search-field"><Search :size="14"/><input v-model="search" placeholder="Search the menu (e.g. platter)" aria-label="Search the menu"></label>
<div class="menu-picks"><button v-for="p in matches" :key="p.id" type="button" @click="addItem(p.id)"><span>{{p.name}}</span><b>{{peso(Number(p.price))}}</b><Plus :size="14"/></button><p v-if="!matches.length" class="planner-note">No menu items match.</p></div>
<ul v-if="planned.length" class="planned-lines"><li v-for="line in planned" :key="line.product_id"><span>{{productById.get(line.product_id)?.name ?? 'Item'}}</span><div class="qty"><button type="button" :aria-label="'Remove one '+(productById.get(line.product_id)?.name??'item')" @click="addItem(line.product_id,-1)"><Minus :size="13"/></button><b>{{line.quantity}}</b><button type="button" :aria-label="'Add one '+(productById.get(line.product_id)?.name??'item')" @click="addItem(line.product_id)"><Plus :size="13"/></button></div></li></ul>
<p class="planned-total"><span>Planned total</span><strong>{{peso(plannedTotal)}}</strong></p><p class="planner-note">No order, stock use or sale is recorded until payment is collected.</p></template></div>
<label>Notes<textarea v-model="form.notes" rows="3" placeholder="Requests, occasion, seating notes…"></textarea></label><button class="save-action" :disabled="saving" @click="save">{{saving?'Saving…':editing?'Update reservation':'Save reservation'}}<ChevronRight :size="16"/></button><button v-if="editing" class="cancel-edit" type="button" @click="reset">Cancel editing</button></aside></div>
<section class="panel bookings-panel"><div class="panel-heading"><div><p class="eyebrow">SELECTED DAY</p><h2>{{selectedLabel}} <span class="count-badge">{{selectedRows.length}} bookings</span></h2></div><Clock3 :size="20" class="muted-icon"/></div>
<p v-if="dayPrep.length" class="day-prep"><strong>Prep for reservations:</strong> {{dayPrep.map(p=>p.quantity+' × '+p.name).join(' · ')}}</p>
<div v-if="selectedRows.length" class="booking-list"><article v-for="x in selectedRows" :key="x.id" class="booking-row"><div class="time-block"><strong>{{new Date(x.reserved_local).toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit'})}}</strong><span>{{x.party_size}} guest{{x.party_size>1?'s':''}}</span></div>
<div class="guest"><strong>{{x.contact_name}} <span class="tag" :class="{'tag-missing':!x.order_type}">{{typeLabel(x.order_type)}}</span><span v-if="x.source==='online'" class="tag tag-online">Homepage request</span></strong><span>{{x.phone||'No phone'}} · {{x.social_media||'No social media'}}</span><small v-if="x.customer_address">Deliver to {{x.customer_address}}</small>
<small v-if="x.items.length" class="planned-summary">{{x.items.map(i=>i.quantity+' × '+(i.product?.name??'Item')).join(', ')}} · {{peso(Number(x.order?Number(x.order.total_amount):x.planned_total))}}</small><small v-else>No planned order</small><small v-if="x.notes">{{x.notes}}</small></div>
<div class="booking-actions"><Link v-if="x.order_id" :href="'/orders/'+x.order_id" class="paid-link">{{x.order?.payment_status==='paid'?'Paid':'Part paid'}} · Order #{{x.order_id}}</Link><button v-else-if="active(x)" class="collect-action" @click="openPayment(x)">Collect payment</button><select :value="x.status" :class="'status status-'+x.status" :aria-label="'Status for '+x.contact_name" @change="status(x,($event.target as HTMLSelectElement).value)"><option v-for="s in statuses" :key="s" :value="s">{{s.replace('_',' ')}}</option></select><button class="edit-action" @click="edit(x)">Edit</button><button v-if="!x.order_id" class="delete-action" @click="del(x)">Delete</button></div></article></div><div v-else class="empty-state"><Users :size="28"/><h3>No reservations yet.</h3><p>Select this date in the calendar and add the first booking.</p></div></section>
<div v-if="paying" class="pay-backdrop" @click.self="paying=null"><section class="pay-dialog" role="dialog" aria-modal="true" aria-labelledby="pay-title"><div class="panel-heading"><div><p class="eyebrow">COLLECT PAYMENT</p><h2 id="pay-title">{{paying.contact_name}} · {{typeLabel(paying.order_type)}}</h2></div><button class="icon-close" aria-label="Close" @click="paying=null"><X :size="18"/></button></div>
<ul class="pay-lines"><li v-for="i in paying.items" :key="i.product_id"><span>{{i.quantity}} × {{i.product?.name}}</span><b>{{peso(Number(i.product?.price??0)*i.quantity)}}</b></li></ul><p class="planned-total"><span>Order total</span><strong>{{peso(Number(paying.planned_total))}}</strong></p>
<label>Paid with<select v-model.number="payment.payment_tender_id"><option v-for="t in tenders" :key="t.id" :value="t.id">{{t.name}}</option></select></label><label>Amount received<input v-model.number="payment.amount" type="number" min="0.01" step="0.01"></label><label>Reference (optional)<input v-model="payment.reference" placeholder="e.g. GCash reference no."></label>
<p class="planner-note">This creates POS order for {{typeLabel(paying.order_type).toLowerCase()}} at current menu prices, uses the stock, and records the payment. A smaller amount is saved as a partial payment; collect the rest in the POS.</p><button class="save-action" :disabled="collecting" @click="collect">{{collecting?'Recording…':'Record payment & create order'}}</button></section></div>
<footer class="dashboard-footer"><span>BYPASS GRILL · RESERVATION CONTROL</span><span>Bookings, planned orders and payments</span></footer>
</div>
</template>

<style scoped>
.reservation-dashboard{max-width:1440px;margin:0 auto;padding:32px;background:#f6f2e9;color:#24231e;min-height:100%;font-family:Arial,Helvetica,sans-serif;color-scheme:light}.dashboard-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;margin-bottom:24px}.eyebrow{display:flex;align-items:center;gap:7px;font-size:9px;font-weight:800;letter-spacing:1.6px;color:#ad3b19}.dashboard-heading h1{font-size:clamp(34px,4vw,52px);font-weight:800;letter-spacing:-2px;line-height:1.05;margin:10px 0}.dashboard-heading h1 em{font-family:Georgia,serif;font-weight:400;color:#ad3b19}.intro,.panel-copy{font-size:12px;line-height:1.7;color:#68665f}.heading-meta{display:flex;flex-direction:column;align-items:flex-end;gap:10px;font-size:11px;color:#68665f}.refresh-button,.month-actions button{display:flex;align-items:center;justify-content:center;gap:7px;border:1px solid #d4cdbf;border-radius:4px;padding:9px 12px;background:#fffcf6;font-size:11px;font-weight:700}.work-bar{display:flex;align-items:center;justify-content:space-between;gap:20px;background:#24231e;color:#f6f2e9;border-radius:6px;padding:23px 25px;margin-bottom:22px}.work-bar-copy strong{display:block;font-size:16px}.work-bar-copy span{display:block;margin-top:5px;color:#c3bfb3;font-size:11px}.primary-action,.save-action{display:flex;align-items:center;justify-content:center;gap:8px;border-radius:4px;background:#c3441c;color:#fff;font-size:11px;font-weight:800;padding:13px 16px}.primary-action:hover,.save-action:hover{background:#a73513}.metric-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:22px}.metric{background:#fffcf6;border:1px solid #ded7cb;padding:21px;border-radius:5px}.metric-featured{background:#f2e5d8;border-color:#e4c4ab}.metric p{font-size:11px;font-weight:700;color:#68665f}.metric strong{display:block;font-size:30px;letter-spacing:-1px;margin:10px 0 5px}.metric-featured strong{color:#ad3b19}.metric span{font-size:10px;color:#777268}.dashboard-columns{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:22px}.panel{background:#fffcf6;border:1px solid #ded7cb;border-radius:6px;padding:23px}.panel-heading{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:20px}.panel h2{font-size:18px;font-weight:800;letter-spacing:-.5px}.month-actions{display:flex;gap:6px}.week,.calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr))}.week b{text-align:center;padding:9px 3px;font-size:8px;letter-spacing:1px;color:#777268}.day{position:relative;min-height:105px;border:1px solid #ece5da;background:#fffdf8;padding:10px;text-align:left;transition:transform .15s,border-color .15s,background .15s}.day:not(.blank):hover{z-index:1;transform:translateY(-2px);border-color:#c3441c;background:#fff9f2}.day.blank{background:#f7f3eb;cursor:default}.day.selected{z-index:2;outline:2px solid #ad3b19;outline-offset:-2px;background:#f8eee4}.day .date{font-size:12px;font-weight:800}.booking-count{display:block;margin-top:14px;font-size:10px;font-weight:800;color:#ad3b19}.day small{display:block;margin-top:3px;font-size:9px;color:#777268}.day i{position:absolute;right:9px;top:9px;width:6px;height:6px;border-radius:50%;background:#c3441c}.form-panel{align-self:start;background:#ebe8d3;border-color:#d8d3b8}.form-icon{display:grid;place-items:center;width:42px;height:42px;border:1px solid #bbb793;border-radius:50%;margin-bottom:17px}.form-panel h2{margin:8px 0}.form-panel label{display:flex;flex-direction:column;gap:6px;margin-top:12px;font-size:9px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#69644e}.form-panel input,.form-panel textarea,.booking-actions select{width:100%;border:1px solid #d4cdbf;border-radius:4px;background:#fffcf6;padding:11px;color:#24231e;font-size:12px;text-transform:none;letter-spacing:0}.field-pair{display:grid;grid-template-columns:1fr 90px;gap:9px}.save-action{width:100%;margin-top:16px}.save-action:disabled{opacity:.6}.bookings-panel{margin-top:22px}.count-badge{display:inline-block;margin-left:8px;padding:4px 8px;border-radius:20px;background:#f2e5d8;color:#ad3b19;font-size:10px;letter-spacing:0}.booking-row{display:grid;grid-template-columns:125px minmax(0,1fr) auto;align-items:center;gap:18px;padding:16px 0;border-top:1px solid #ece5da}.time-block strong,.guest strong{display:block;font-size:13px}.time-block span,.guest span,.guest small{display:block;margin-top:4px;font-size:10px;color:#777268}.guest small{color:#948d7e}.booking-actions{display:flex;align-items:center;gap:8px}.booking-actions select{width:125px;padding:8px;font-size:10px;font-weight:700}.delete-action{font-size:10px;font-weight:700;color:#a03015;padding:8px}.empty-state{display:flex;flex-direction:column;align-items:center;text-align:center;gap:9px;padding:32px;color:#777268}.empty-state svg{color:#ad3b19}.empty-state h3{font-size:15px;color:#24231e}.empty-state p{font-size:11px}.dashboard-footer{display:flex;justify-content:space-between;gap:15px;margin-top:30px;padding-top:18px;border-top:1px solid #ded7cb;font-size:9px;color:#777268}.spinning{animation:spin 1s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}.reservation-dashboard :focus-visible{outline:2px solid #ad3b19;outline-offset:3px}
.reservation-dashboard,.reservation-dashboard h1,.reservation-dashboard h2,.reservation-dashboard h3,.reservation-dashboard strong,.reservation-dashboard p,.reservation-dashboard span,.reservation-dashboard label,.reservation-dashboard button,.reservation-dashboard input,.reservation-dashboard textarea,.reservation-dashboard select{color-scheme:light}
.reservation-dashboard .dashboard-heading h1,.reservation-dashboard .panel h2,.reservation-dashboard .booking-row strong,.reservation-dashboard .metric strong{color:#24231e}
.reservation-dashboard .work-bar,.reservation-dashboard .work-bar strong{color:#f6f2e9}
.reservation-dashboard .primary-action,.reservation-dashboard .save-action{color:#fff}
.reservation-dashboard input,.reservation-dashboard textarea,.reservation-dashboard select{color:#24231e;-webkit-text-fill-color:#24231e}
.reservation-dashboard button{cursor:pointer}
@media(max-width:1000px){.dashboard-columns{grid-template-columns:1fr}.form-panel{width:100%}}@media(max-width:760px){.reservation-dashboard{padding:24px 16px}.dashboard-heading{flex-direction:column}.heading-meta{align-items:flex-start}.metric-grid{grid-template-columns:repeat(2,1fr)}.day{min-height:78px;padding:7px}.booking-count{font-size:8px;margin-top:10px}.day small{display:none}.booking-row{grid-template-columns:90px 1fr}.booking-actions{grid-column:1/-1;justify-content:flex-end}}@media(max-width:520px){.work-bar{align-items:flex-start;flex-direction:column;padding:20px}.primary-action{width:100%}.metric{padding:15px}.metric strong{font-size:24px}.calendar-panel{padding:14px}.panel-heading{align-items:flex-start}.month-actions button{padding:8px}.month-actions button:nth-child(2){display:none}.day{min-height:62px}.booking-count{font-size:0}.booking-count:after{content:'Booked';font-size:8px}.field-pair{grid-template-columns:1fr}.booking-row{grid-template-columns:1fr}.time-block{display:flex;justify-content:space-between}.booking-actions{grid-column:auto;justify-content:space-between}.dashboard-footer{flex-direction:column}}
@media(prefers-reduced-motion:reduce){.day{transition:none}.spinning{animation:none}}

.type-picker{margin-top:12px;border:0;padding:0}.type-picker legend{font-size:9px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#69644e;margin-bottom:6px}.type-picker legend b{color:#ad3b19}.type-picker div{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}.type-picker button{border:1px solid #d4cdbf;border-radius:4px;background:#fffcf6;padding:10px 4px;font-size:11px;font-weight:700;color:#24231e}.type-picker button[aria-pressed=true]{background:#24231e;border-color:#24231e;color:#f6f2e9}
.planner{margin-top:14px;border:1px solid #d8d3b8;border-radius:5px;background:#f4f1df;padding:12px}.planner-title{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:800;color:#24231e}.planner-title span{margin-left:auto;font-size:9px;font-weight:600;color:#777268}.planner-note{margin-top:8px;font-size:10px;line-height:1.5;color:#68665f}
.form-panel .search-field{flex-direction:row;align-items:center;gap:6px;border:1px solid #d4cdbf;border-radius:4px;background:#fffcf6;padding:0 9px;margin-top:9px}.form-panel .search-field input{border:0;padding:9px 0;background:transparent}
.menu-picks{display:grid;gap:4px;margin-top:7px;max-height:180px;overflow-y:auto}.menu-picks button{display:flex;align-items:center;gap:8px;text-align:left;border:1px solid #e3dec8;border-radius:4px;background:#fffcf6;padding:8px 9px;font-size:11px;color:#24231e}.menu-picks button:hover{border-color:#c3441c}.menu-picks span{flex:1;min-width:0}.menu-picks b{font-size:10px;color:#68665f}
.planned-lines{margin-top:10px;display:grid;gap:5px}.planned-lines li{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:11px;font-weight:700}.qty{display:flex;align-items:center;gap:6px}.qty button{display:grid;place-items:center;width:26px;height:26px;border:1px solid #d4cdbf;border-radius:4px;background:#fffcf6}.qty b{min-width:18px;text-align:center}
.planned-total{display:flex;justify-content:space-between;align-items:baseline;margin-top:10px;padding-top:9px;border-top:1px dashed #d4cdbf;font-size:11px}.planned-total strong{font-size:15px;color:#ad3b19}
.cancel-edit{width:100%;margin-top:8px;font-size:11px;font-weight:700;color:#68665f;padding:8px}
.tag{display:inline-block;margin-left:6px;padding:2px 7px;border-radius:20px;background:#ebe8d3;color:#4c4936;font-size:9px;font-weight:800;vertical-align:middle}.tag-missing{background:#f8dcd2;color:#a03015}.tag-online{background:#f2e5d8;color:#ad3b19}
.reservation-dashboard .guest .tag{display:inline-block;width:auto;margin-top:0}
.guest .planned-summary{color:#24231e;font-weight:700}
.day-prep{margin:-6px 0 12px;padding:10px 12px;border-radius:5px;background:#f2e5d8;font-size:11px;color:#24231e}
.collect-action{border-radius:4px;background:#c3441c;color:#fff;font-size:10px;font-weight:800;padding:9px 11px;white-space:nowrap}.collect-action:hover{background:#a73513}.paid-link{font-size:10px;font-weight:800;color:#1f6b45;padding:8px;white-space:nowrap}.edit-action{font-size:10px;font-weight:700;color:#24231e;padding:8px}
.pay-backdrop{position:fixed;inset:0;z-index:50;display:grid;place-items:center;padding:16px;background:rgba(36,35,30,.55)}.pay-dialog{width:min(440px,100%);max-height:calc(100vh - 32px);overflow-y:auto;background:#fffcf6;border-radius:6px;padding:22px;color:#24231e}.pay-dialog label{display:flex;flex-direction:column;gap:6px;margin-top:12px;font-size:9px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#69644e}.pay-dialog input,.pay-dialog select{width:100%;border:1px solid #d4cdbf;border-radius:4px;background:#fff;padding:11px;font-size:12px;color:#24231e;text-transform:none;letter-spacing:0}.pay-lines{display:grid;gap:5px;font-size:12px}.pay-lines li{display:flex;justify-content:space-between;gap:10px}.pay-dialog .save-action{margin-top:14px}.icon-close{display:grid;place-items:center;width:34px;height:34px;border-radius:4px}
.reservation-dashboard .collect-action{color:#fff}.reservation-dashboard .type-picker button[aria-pressed=true]{color:#f6f2e9}
@media(max-width:760px){.booking-actions{flex-wrap:wrap}}
</style>