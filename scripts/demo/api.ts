// Isolated sample responses only. No requests leave this fixture.
import { checklist } from './dashboard';
import { products as catalogue } from './orders';
const seed = () => ({
    id: 1042,
    queue_number: 42,
    order_type: 'dine_in',
    table_number: 'Table 3',
    customer_name: 'Demo Guest',
    customer_contact: null,
    customer_address: null,
    notes: null,
    subtotal: '185.00',
    discount_amount: '0.00',
    total_amount: '185.00',
    payment_status: 'pending',
    public_token: '00000000000000000000000000000000ab',
    items: [
        {
            id: 1,
            product: { id: 34, name: 'Pork Monster Ribs' },
            quantity: 1,
            unit_price: '185.00',
        },
    ],
    created_at: '2026-09-25T11:30:00+08:00',
});
let order = seed();
let pending = [order];
export default {
    async get(url: string) {
        if (url === '/api/v1/shift-checklist') {
            return { data: checklist };
        }

        if (url === '/api/v1/payment-tenders') {
            return {
                data: [
                    { id: 1, name: 'Cash', is_active: true, display_order: 1 },
                    { id: 2, name: 'GCash', is_active: true, display_order: 2 },
                ],
            };
        }

        if (url === '/api/v1/orders') {
            return { data: { data: pending } };
        }

        // Loaded by the order detail Edit panel's product search.
        if (url === '/api/v1/products') {
            return { data: catalogue };
        }

        throw Error('Unsupported sample GET ' + url);
    },
    async post(url: string, payload: any) {
        if (url === '/api/v1/orders') {
            order = {
                ...seed(),
                ...payload,
                subtotal: '185.00',
                total_amount: '185.00',
            };
            pending = [order];

            return { data: { data: order } };
        }

        if (url === '/api/v1/payments' || url.endsWith('/cancel')) {
            pending = [];

            return { data: { success: true } };
        }

        if (url === '/api/v1/print-jobs') {
            return { data: { success: true } };
        }

        throw Object.assign(Error('Unsupported sample POST ' + url), {
            response: { data: { message: 'Unsupported sample action' } },
        });
    },
    async put(url: string, payload: any) {
        if (url === '/api/v1/orders/1042') {
            order = {
                ...order,
                ...payload,
                subtotal: '370.00',
                total_amount: '370.00',
            };

            return { data: { data: order } };
        }

        throw Error('Unsupported sample PUT ' + url);
    },
};
