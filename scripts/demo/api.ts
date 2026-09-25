// Isolated sample responses only. No requests leave this fixture.
import { checklist } from './dashboard';
import {
    closeShift,
    depositState,
    reconcileShift,
    startShift,
} from './deposit';
import {
    billsSummary,
    dailyTotals,
    deleteEntry,
    financialList,
    financialSummary,
    periodHistory,
    recordEntry,
    tenders as financialTenders,
} from './financial';
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
    async get(url: string, config?: { params?: Record<string, unknown> }) {
        if (url === '/api/v1/shift-checklist') {
            return { data: checklist };
        }

        if (url === '/api/v1/payment-tenders') {
            return { data: financialTenders };
        }

        if (url === '/api/v1/orders') {
            return { data: { data: pending } };
        }

        // Loaded by the order detail Edit panel's product search.
        if (url === '/api/v1/products') {
            return { data: catalogue };
        }

        if (url === '/api/v1/deposit-controls') {
            return { data: depositState() };
        }

        if (url === '/api/v1/financial-transactions/summary') {
            return { data: financialSummary() };
        }

        if (url === '/api/v1/financial-transactions') {
            return { data: financialList(config?.params) };
        }

        if (url === '/api/v1/financial-transactions/daily') {
            return { data: dailyTotals };
        }

        if (url === '/api/v1/financial-transactions/periods') {
            return { data: periodHistory };
        }

        if (url === '/api/v1/bills/summary') {
            return { data: billsSummary };
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

        // Recording an expense or an income adjustment from the Financial page.
        if (url === '/api/v1/financial-transactions') {
            return { data: recordEntry(payload) };
        }

        // The deposit-control flow: start, then close, then submit the counts.
        if (url === '/api/v1/deposit-controls') {
            return { data: startShift(payload?.opening_cash) };
        }

        if (url.startsWith('/api/v1/deposit-controls/')) {
            if (url.endsWith('/close')) {
                return { data: closeShift() };
            }

            if (url.endsWith('/reconcile')) {
                return { data: reconcileShift(payload) };
            }
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
    async delete(url: string) {
        const match = url.match(/^\/api\/v1\/financial-transactions\/(\d+)$/);

        if (match) {
            return { data: deleteEntry(Number(match[1])) };
        }

        throw Error('Unsupported sample DELETE ' + url);
    },
};
