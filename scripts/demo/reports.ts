// Fictional examples for the actual ReportsPage component; no live records.
//
// One September of trading, sized so every tab has something to show without
// any of them needing a thousand rows. The day the other guides use —
// 26 September, ₱12,450.00 taken — is the last day here, so a reader moving
// between the guides keeps seeing the same business.
const day = (n: number) => `2026-09-${String(n).padStart(2, '0')}`;

const DAYS = Array.from({ length: 26 }, (_, i) => {
    const date = day(i + 1);
    const weekend = [0, 6].includes(new Date(date + 'T00:00:00').getDay());
    const sales = weekend ? 15200 + i * 45 : 11400 + i * 35;

    return { date, sales, orders: weekend ? 38 : 29 };
});

export const dailyReport = {
    date: day(26),
    total_orders: 11,
    total_sales: 12450,
    total_discount: 320,
};

export const monthlyReport = {
    month: '2026-09',
    total_orders: DAYS.reduce((t, d) => t + d.orders, 0),
    total_sales: DAYS.reduce((t, d) => t + d.sales, 0),
    total_discount: 4180,
};

export const productSales = [
    {
        product_id: 34,
        product_name: 'Pork Monster Ribs',
        total_quantity: 184,
        total_sales: 34040,
    },
    {
        product_id: 35,
        product_name: 'Chicken Jerk',
        total_quantity: 131,
        total_sales: 21615,
    },
    {
        product_id: 36,
        product_name: 'Grilled Liempo',
        total_quantity: 96,
        total_sales: 15360,
    },
    {
        product_id: 30,
        product_name: 'Rice',
        total_quantity: 288,
        total_sales: 7200,
    },
    {
        product_id: 31,
        product_name: 'Soft Drink',
        total_quantity: 142,
        total_sales: 6390,
    },
];

export const productDailySales = DAYS.map((d, i) => ({
    date: d.date,
    qty: 5 + (i % 4),
    sales: (5 + (i % 4)) * 185,
}));

export const dailyChart = DAYS.map((d) => ({
    date: d.date,
    income: d.sales,
    expense: Math.round(d.sales * 0.29),
}));

export const monthlyChart = [
    '2026-05',
    '2026-06',
    '2026-07',
    '2026-08',
    '2026-09',
].map((month, i) => ({
    month,
    income: 286000 + i * 14500,
    expense: 92000 + i * 3100,
}));

const FT_TYPES = [
    { type: 'payment', total: 331400, count: 812 },
    { type: 'expense', total: 61200, count: 74 },
    { type: 'payroll', total: 48000, count: 12 },
    { type: 'income_adjustment', total: 2400, count: 3 },
];

export const ftBreakdown = {
    period: { start: day(1), end: day(26) },
    by_type: FT_TYPES,
    by_tender: [
        {
            tender: 'Cash',
            total_in: 208400,
            total_out: 71300,
            net: 137100,
            count: 542,
        },
        {
            tender: 'GCash',
            total_in: 125400,
            total_out: 37900,
            net: 87500,
            count: 359,
        },
    ],
};

export const ftSummary = {
    period: { start: day(1), end: day(26) },
    payments: { total: 331400, count: 812 },
    expenses: { total: 61200, count: 74 },
    income_adjustments: { total: 2400, count: 3 },
    payroll: { total: 48000, count: 12 },
    net: 224600,
    by_tender: [
        { tender: 'Cash', total: 279700, count: 542 },
        { tender: 'GCash', total: 163300, count: 359 },
    ],
};

export const ftTransactions = {
    data: [
        {
            id: 901,
            type: 'expense',
            amount: 800,
            description: 'Market run — pork and charcoal',
            transacted_at: `${day(26)} 09:00:00`,
            notes: null,
            user: { name: 'Demo Cashier' },
            tender: { name: 'Cash' },
        },
        {
            id: 902,
            type: 'payment',
            amount: 1850,
            description: 'Order #1039 payment',
            transacted_at: `${day(26)} 11:00:00`,
            notes: null,
            user: { name: 'Demo Cashier' },
            tender: { name: 'Cash' },
        },
        {
            id: 903,
            type: 'payroll',
            amount: 2500,
            description: 'Salary released — Demo Cook',
            transacted_at: `${day(26)} 19:00:00`,
            notes: null,
            user: { name: 'Demo Cashier' },
            tender: { name: 'Cash' },
        },
    ],
    current_page: 1,
    last_page: 1,
    total: 3,
};

export const orders = {
    data: [
        {
            id: 1046,
            queue_number: 46,
            order_type: 'dine_in',
            status: 'completed',
            payment_status: 'paid',
            table_number: '5',
            notes: null,
            customer_name: 'Demo Guest',
            total_amount: 1400,
            items: [],
            user: { name: 'Demo Cashier' },
            created_at: `${day(26)} 18:00:00`,
            payments: [
                { id: 1, method: 'cash', amount: 1400, status: 'completed' },
            ],
        },
        {
            id: 1045,
            queue_number: 45,
            order_type: 'takeout',
            status: 'completed',
            payment_status: 'paid',
            table_number: null,
            notes: null,
            customer_name: null,
            total_amount: 1300,
            items: [],
            user: { name: 'Demo Cashier' },
            created_at: `${day(26)} 17:00:00`,
            payments: [
                { id: 2, method: 'gcash', amount: 1300, status: 'completed' },
            ],
        },
        {
            id: 1042,
            queue_number: 42,
            order_type: 'dine_in',
            status: 'completed',
            payment_status: 'paid',
            table_number: '3',
            notes: 'Celebration',
            customer_name: 'Demo Guest',
            total_amount: 375,
            items: [],
            user: { name: 'Demo Cashier' },
            created_at: `${day(26)} 11:30:00`,
            payments: [
                { id: 3, method: 'cash', amount: 375, status: 'completed' },
            ],
        },
    ],
    current_page: 1,
    last_page: 1,
    total: 3,
};

export const inventory = [
    {
        id: 1,
        name: 'Pork',
        unit: 'kg',
        current_quantity: 42.5,
        min_quantity: 10,
        cost_per_unit: 280,
        item_type: 'ingredient',
        is_active: true,
    },
    {
        id: 2,
        name: 'Pork Chop',
        unit: 'piece',
        current_quantity: 18,
        min_quantity: 12,
        cost_per_unit: 70,
        item_type: 'food',
        is_active: true,
    },
    {
        id: 3,
        name: 'Charcoal',
        unit: 'sack',
        current_quantity: 3,
        min_quantity: 5,
        cost_per_unit: 420,
        item_type: 'supply',
        is_active: true,
    },
];

export const inventoryTransactions = {
    data: [
        {
            id: 51,
            type: 'stock_in',
            quantity: 20,
            old_quantity: 22.5,
            new_quantity: 42.5,
            reference: null,
            notes: 'Delivery',
            created_at: `${day(26)} 08:00:00`,
            ingredient: { id: 1, name: 'Pork', unit: 'kg' },
            user: { name: 'Demo Cashier' },
        },
        {
            id: 52,
            type: 'stock_out',
            quantity: 5,
            old_quantity: 23,
            new_quantity: 18,
            reference: 'production_9',
            notes: 'Produced Pork Chop',
            created_at: `${day(26)} 09:30:00`,
            ingredient: { id: 2, name: 'Pork Chop', unit: 'piece' },
            user: { name: 'Demo Cashier' },
        },
        {
            id: 53,
            type: 'waste',
            quantity: 1,
            old_quantity: 4,
            new_quantity: 3,
            reference: null,
            notes: 'Damp sack',
            created_at: `${day(26)} 10:00:00`,
            ingredient: { id: 3, name: 'Charcoal', unit: 'sack' },
            user: { name: 'Demo Cashier' },
        },
    ],
    current_page: 1,
    last_page: 1,
    total: 3,
};

export const bills = {
    data: [
        {
            id: 7,
            name: 'Stall rent',
            vendor: 'Bypass Market',
            amount: 5000,
            due_date: day(30),
            status: 'upcoming',
            is_recurring: true,
            frequency: 'monthly',
            notes: null,
            installments: [],
        },
        {
            id: 8,
            name: 'Electricity',
            vendor: 'Local co-op',
            amount: 1500,
            due_date: day(24),
            status: 'overdue',
            is_recurring: true,
            frequency: 'monthly',
            notes: null,
            installments: [],
        },
    ],
    current_page: 1,
    last_page: 1,
    total: 2,
};

const forecastEntry = (
    bill_id: number,
    name: string,
    amount: number,
    due_date: string,
) => ({ bill_id, name, amount, due_date, status: 'upcoming' });

export const billsForecast = {
    period: { start: day(1), end: day(30) },
    total_due: 6500,
    overdue: 1500,
    upcoming: 5000,
    count: 2,
    // The tab reads `entries` for the total and `by_month` for the breakdown.
    entries: [
        forecastEntry(8, 'Electricity', 1500, day(24)),
        forecastEntry(7, 'Stall rent', 5000, day(30)),
        forecastEntry(8, 'Electricity', 1500, '2026-10-24'),
        forecastEntry(7, 'Stall rent', 5000, '2026-10-30'),
    ],
    by_month: {
        '2026-09': [
            forecastEntry(8, 'Electricity', 1500, day(24)),
            forecastEntry(7, 'Stall rent', 5000, day(30)),
        ],
        '2026-10': [
            forecastEntry(8, 'Electricity', 1500, '2026-10-24'),
            forecastEntry(7, 'Stall rent', 5000, '2026-10-30'),
        ],
    },
};

/** The two tabs that live in their own components. */
export const analytics = {
    period: { start: day(1), end: day(26) },
    totals: { orders: 812, sales: 331400, average_order: 408.13 },
    trend: DAYS.map((d) => ({
        date: d.date,
        orders: d.orders,
        sales: d.sales,
    })),
    by_category: [
        { category: 'Meals', total_quantity: 411, total_sales: 71015 },
        { category: 'Extras', total_quantity: 430, total_sales: 13590 },
    ],
    by_hour: Array.from({ length: 24 }, (_, hour) => ({
        hour,
        orders: hour >= 11 && hour <= 20 ? 40 + hour : 4,
    })),
};

export const categories = [
    { id: 1, name: 'Meals' },
    { id: 2, name: 'Ala Carte' },
    { id: 3, name: 'Extras' },
];

export const servingTime = {
    period: { start: day(1), end: day(26) },
    summary: {
        avg_minutes: 8.53,
        min_minutes: 3.1,
        max_minutes: 24.7,
        total_orders: 796,
    },
    daily: DAYS.map((d, i) => ({
        date: d.date,
        avg_minutes: 7.8 + (i % 5) * 0.4,
        min_minutes: 3.1,
        max_minutes: 18 + (i % 4),
        count: d.orders,
    })),
    by_order_type: [
        {
            type: 'dine_in',
            avg_minutes: 9.4,
            min_minutes: 4.2,
            max_minutes: 24.7,
            count: 512,
        },
        {
            type: 'takeout',
            avg_minutes: 7.1,
            min_minutes: 3.1,
            max_minutes: 16.3,
            count: 284,
        },
    ],
    distribution: [
        { bucket: '0-5', count: 188 },
        { bucket: '5-10', count: 401 },
        { bucket: '10-15', count: 142 },
        { bucket: '15+', count: 65 },
    ],
};

export const servingTimeOrders = {
    data: [
        {
            id: 1046,
            queue_number: 46,
            created_at: `${day(26)} 18:00:00`,
            completed_at: `${day(26)} 18:07:00`,
            seconds: 420,
            total_amount: 1400,
        },
        {
            id: 1045,
            queue_number: 45,
            created_at: `${day(26)} 17:00:00`,
            completed_at: `${day(26)} 17:14:00`,
            seconds: 840,
            total_amount: 1300,
        },
    ],
    current_page: 1,
    last_page: 1,
    total: 2,
};

/** Seven days by twenty-four hours, busiest around lunch and dinner. */
const matrix = Array.from({ length: 7 }, (_, dayIndex) =>
    Array.from({ length: 24 }, (_, hour) => {
        const lunch = hour >= 11 && hour <= 13;
        const dinner = hour >= 18 && hour <= 20;
        const weekend = dayIndex === 0 || dayIndex === 6;

        if (!lunch && !dinner) {
            return hour >= 9 && hour <= 21 ? 2 : 0;
        }

        return (dinner ? 9 : 6) + (weekend ? 4 : 0);
    }),
);
const hourTotals = Array.from({ length: 24 }, (_, hour) =>
    matrix.reduce((total, row) => total + row[hour], 0),
);
const dayTotals = matrix.map((row) => row.reduce((total, n) => total + n, 0));

export const heatmap = {
    xAxis: 'hour',
    yAxis: 'day',
    matrix,
    data: matrix.flatMap((row, d) =>
        row.map((total_orders, hour) => ({ day: d, hour, total_orders })),
    ),
    insights: {
        total_orders: hourTotals.reduce((a, b) => a + b, 0),
        peak_slot: { day: 6, hour: 19, total_orders: matrix[6][19] },
        peak_hour: { hour: 19, total_orders: hourTotals[19] },
        peak_day: {
            day: dayTotals.indexOf(Math.max(...dayTotals)),
            total_orders: Math.max(...dayTotals),
        },
        hour_totals: hourTotals,
        day_totals: dayTotals,
    },
};

export const profitLoss = {
    period: { start: day(1), end: day(26) },
    revenue: {
        order_count: 812,
        gross_sales: 335580,
        discounts: 4180,
        net_revenue: 331400,
    },
    cogs: { total: 118900, has_data: true },
    gross_profit: 212500,
    gross_margin: 64.12,
    product_margins: [
        {
            product_id: 34,
            product_name: 'Pork Monster Ribs',
            quantity: 184,
            sales: 34040,
            cost: 12880,
            gross_profit: 21160,
            margin: 62.16,
        },
        {
            product_id: 35,
            product_name: 'Chicken Jerk',
            quantity: 131,
            sales: 21615,
            cost: 8253,
            gross_profit: 13362,
            margin: 61.82,
        },
        {
            product_id: 30,
            product_name: 'Rice',
            quantity: 288,
            sales: 7200,
            cost: 2304,
            gross_profit: 4896,
            margin: 68,
        },
    ],
    income_adjustments: { total: 2400, count: 3, breakdown: [] },
    expenses: { total: 61200, count: 74, breakdown: [] },
    inventory_purchases: {
        total: 84300,
        count: 31,
        included_in_expenses: false,
        breakdown: [],
    },
    inventory_losses: { total: 1850, count: 6, breakdown: [] },
    payroll: { total: 48000, count: 12, breakdown: [] },
    payout_share: { total: 20000, count: 2, breakdown: [] },
    net_profit: 83850,
    net_margin: 25.12,
    include_cogs: true,
    accrual_basis: true,
    unpaid_completed: { total: 1850, count: 2 },
};

export const products = [
    {
        id: 34,
        name: 'Pork Monster Ribs',
        price: 185,
        category: { name: 'Meals' },
    },
    { id: 35, name: 'Chicken Jerk', price: 165, category: { name: 'Meals' } },
    { id: 30, name: 'Rice', price: 25, category: { name: 'Extras' } },
];
