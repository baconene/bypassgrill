// Fictional example for the actual OrderDetail component; no live records.
//
// A completed, fully paid dine-in order, chosen because it is the state that shows
// every panel at once: a timeline with both timestamps, a customer, cost and gross
// profit under the totals, and a settled payment. Costs are the recipe costs a Food
// item would produce, so the profit line is the one the reports would agree with.
const items = [
    {
        id: 5011,
        product_id: 34,
        product_name: 'Pork Monster Ribs',
        category_name: 'Meals',
        quantity: 2,
        unit_price: 185,
        unit_cost: 70,
        subtotal: 370,
        cost_subtotal: 140,
        special_instructions: 'Sauce on the side',
        modifiers: [{ name: 'Extra sauce', price: 15 }],
    },
    {
        id: 5012,
        product_id: 30,
        product_name: 'Rice',
        category_name: 'Extras',
        quantity: 1,
        unit_price: 25,
        unit_cost: 8,
        subtotal: 25,
        cost_subtotal: 8,
        special_instructions: null,
        modifiers: [],
    },
];
export const order = {
    id: 1042,
    queue_number: 42,
    order_type: 'dine_in',
    order_type_label: 'Dine In',
    status: 'completed',
    payment_status: 'paid',
    // The screen prefixes this with "Table", so the number alone belongs here.
    table_number: '3',
    customer_name: 'Demo Guest',
    customer_contact: '0917 000 0000',
    customer_address: null,
    notes: 'Celebration — serve the ribs last.',
    // 395.00 of items, 20.00 off, so the discount line is visible.
    subtotal: 395,
    discount_amount: 20,
    tax_amount: 0,
    total_amount: 375,
    created_at: '2026-09-26 11:30:00',
    completed_at: '2026-09-26 11:52:00',
    created_by: 'Demo Cashier',
    public_token: '00000000000000000000000000000000ab',
    items,
    payments: [
        {
            id: 9001,
            amount: 375,
            tender: 'Cash',
            status: 'completed',
            reference: null,
            created_at: '2026-09-26 11:50:00',
        },
    ],
};

// Offered by the Edit Order panel's product search.
export const products = [
    {
        id: 34,
        name: 'Pork Monster Ribs',
        price: 185,
        category: { name: 'Meals' },
    },
    { id: 35, name: 'Chicken Jerk', price: 165, category: { name: 'Meals' } },
    { id: 30, name: 'Rice', price: 25, category: { name: 'Extras' } },
    { id: 31, name: 'Soft Drink', price: 45, category: { name: 'Extras' } },
];
