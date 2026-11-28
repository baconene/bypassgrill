export type OrderType = 'dine_in' | 'takeout' | 'delivery';
export type ReservationItem = {
    product_id: number;
    quantity: number;
    product?: { id: number; name: string; price: number | string } | null;
};
export type Reservation = {
    id: number;
    contact_name: string;
    phone?: string | null;
    social_media?: string | null;
    reserved_at: string;
    /** Store-local (Manila) date and time, e.g. 2026-12-01T18:00 */
    reserved_local: string;
    party_size: number;
    status: string;
    notes?: string | null;
    order_type: OrderType | null;
    customer_address?: string | null;
    source: 'staff' | 'online';
    order_id: number | null;
    paid_at: string | null;
    planned_total: number;
    items: ReservationItem[];
    order?: { id: number; total_amount: string; payment_status: string } | null;
};
export type MenuProduct = {
    id: number;
    name: string;
    price: number;
    image: string | null;
    soldOut: boolean;
    lowStock: boolean;
};
export type MenuCategory = { id: number; name: string; products: MenuProduct[] };

export const orderTypes: { value: OrderType; label: string }[] = [
    { value: 'dine_in', label: 'Dine in' },
    { value: 'takeout', label: 'Takeout' },
    { value: 'delivery', label: 'Delivery' },
];
export const typeLabel = (type: OrderType | null) =>
    orderTypes.find((t) => t.value === type)?.label ?? 'No order type';
export const statuses = ['pending', 'confirmed', 'arrived', 'completed', 'cancelled', 'no_show'];
export const statusLabel = (status: string) =>
    status.charAt(0).toUpperCase() + status.slice(1).replace('_', '-');
export const statusClass: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-900',
    confirmed: 'bg-emerald-100 text-emerald-900',
    arrived: 'bg-sky-100 text-sky-900',
    completed: 'bg-stone-200 text-stone-700',
    cancelled: 'bg-red-100 text-red-800',
    no_show: 'bg-red-100 text-red-800',
};
export const isActive = (r: Reservation) => !['cancelled', 'no_show'].includes(r.status);
export const peso = (n: number) =>
    new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 2 }).format(n);
export const dateKey = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
export const timeLabel = (local: string) =>
    new Date(local).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
export const dayLabel = (key: string, opts: Intl.DateTimeFormatOptions = { weekday: 'long', month: 'long', day: 'numeric' }) =>
    new Date(key + 'T00:00:00').toLocaleDateString('en-PH', opts);
/** The amount shown for a booking: the order total once paid, else the planned total. */
export const totalOf = (r: Reservation) => (r.order ? Number(r.order.total_amount) : Number(r.planned_total));
export const errorText = (e: unknown, fallback: string) => {
    const data = (e as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } })?.response?.data;

    return data?.errors ? Object.values(data.errors).flat().join(' ') : (data?.message ?? fallback);
};
