// Fictional examples for the actual FinancialPage component; no live records.
//
// One ordinary trading day, chosen so every panel has something to show: money
// in from payments, money out as expenses and payroll, both tenders in use, and
// a balance that carries forward. The ledger holds more than one page so the
// pager and the search are real rather than decorative.
const TENDERS = [
    { id: 1, name: 'Cash', is_active: true },
    { id: 2, name: 'GCash', is_active: true },
];
export const tenders = TENDERS;

const money = (
    type: string,
    amount: number,
    description: string,
    hour: number,
    tenderId: number | null,
) => ({
    type,
    amount,
    description,
    notes: null as string | null,
    transacted_at: `2026-09-26 ${String(hour).padStart(2, '0')}:00:00`,
    payment_tender_id: tenderId,
    order_id: null as number | null,
    user: { name: 'Demo Cashier' },
    tender: tenderId ? TENDERS.find((t) => t.id === tenderId) : undefined,
    order: null,
});

// 12,450.00 in, 3,700.00 out. Kept in step with the deposit-control sample so a
// reader moving between the two guides sees the same day.
const rows = [
    money('expense', 800, 'Market run — pork and charcoal', 9, 1),
    money('expense', 400, 'Ice and gas', 10, 1),
    money('payment', 1850, 'Order #1039 payment', 11, 1),
    money('payment', 2200, 'Order #1040 payment', 12, 2),
    money('payment', 1500, 'Order #1041 payment', 13, 1),
    money('payment', 375, 'Order #1042 payment', 14, 1),
    money('payment', 2100, 'Order #1043 payment', 15, 2),
    money('payment', 1725, 'Order #1044 payment', 16, 1),
    money('payment', 1300, 'Order #1045 payment', 17, 2),
    money('payment', 1400, 'Order #1046 payment', 18, 1),
    money('payroll', 2500, 'Salary released — Demo Cook', 19, 1),
    money('income_adjustment', 0, 'placeholder', 20, 1),
];
// The adjustment is the one entry a reader is invited to create, so it starts
// absent and the fixture's own POST adds it.
rows.pop();

const OPENING = 8000;
let ledger = rows.map((row, index) => ({ ...row, id: 900 + index }));
let nextId = 950;

const signOf = (type: string) =>
    type === 'payment' || type === 'income_adjustment' ? 1 : -1;

/** Running balance, oldest first, exactly as the real endpoint reports it. */
const withBalances = (list: typeof ledger) => {
    const ordered = [...list].sort((a, b) =>
        a.transacted_at < b.transacted_at ? -1 : 1,
    );
    let balance = OPENING;
    const balances = new Map<number, number>();

    for (const row of ordered) {
        balance =
            Math.round((balance + signOf(row.type) * row.amount) * 100) / 100;
        balances.set(row.id, balance);
    }

    return [...ordered].reverse().map((row) => ({
        ...row,
        financial_balance: balances.get(row.id) ?? null,
    }));
};

const sum = (type: string) =>
    ledger.filter((r) => r.type === type).reduce((t, r) => t + r.amount, 0);
const countOf = (type: string) => ledger.filter((r) => r.type === type).length;
const group = (tenderId: number) =>
    ledger.filter((r) => r.payment_tender_id === tenderId);

export function financialSummary() {
    const net =
        sum('payment') +
        sum('income_adjustment') -
        sum('expense') -
        sum('payroll');

    return {
        period: { start: '2026-09-26', end: '2026-09-26' },
        payments: { total: sum('payment'), count: countOf('payment') },
        expenses: { total: sum('expense'), count: countOf('expense') },
        income_adjustments: {
            total: sum('income_adjustment'),
            count: countOf('income_adjustment'),
        },
        payroll: { total: sum('payroll'), count: countOf('payroll') },
        asset_deductions: { total: 0, count: 0 },
        payout_shares: { total: 0, count: 0 },
        net,
        opening_balance: OPENING,
        balance_as_of_end: OPENING + net,
        balance_by_tender: TENDERS.map((t) => ({
            tender: t.name,
            balance: group(t.id).reduce(
                (b, r) => b + signOf(r.type) * r.amount,
                0,
            ),
            count: group(t.id).length,
        })),
        by_tender: TENDERS.map((t) => ({
            tender: t.name,
            total: group(t.id).reduce((b, r) => b + r.amount, 0),
            count: group(t.id).length,
        })),
        net_by_tender: TENDERS.map((t) => ({
            tender: t.name,
            total_in: group(t.id)
                .filter((r) => signOf(r.type) > 0)
                .reduce((b, r) => b + r.amount, 0),
            total_out: group(t.id)
                .filter((r) => signOf(r.type) < 0)
                .reduce((b, r) => b + r.amount, 0),
            net: group(t.id).reduce((b, r) => b + signOf(r.type) * r.amount, 0),
            count: group(t.id).length,
        })),
        include_asset_deductions: true,
    };
}

/** Server-side search, type and tender filters, sorting and paging, as the real one does. */
export function financialList(params: Record<string, unknown> = {}) {
    const perPage = 8;
    const term = String(params.search ?? '')
        .trim()
        .toLowerCase();
    let list = withBalances(ledger);

    if (params.type) {
        list = list.filter((r) => r.type === params.type);
    }

    if (params.payment_tender_id) {
        list = list.filter(
            (r) => r.payment_tender_id === Number(params.payment_tender_id),
        );
    }

    if (term) {
        list = list.filter(
            (r) =>
                r.description.toLowerCase().includes(term) ||
                r.type.toLowerCase().includes(term) ||
                (r.tender?.name ?? '').toLowerCase().includes(term) ||
                (r.user?.name ?? '').toLowerCase().includes(term),
        );
    }

    const key = String(params.sort ?? 'transacted_at') as
        | 'transacted_at'
        | 'amount'
        | 'type'
        | 'description';
    const dir = params.direction === 'asc' ? 1 : -1;
    list.sort((a, b) => (a[key] > b[key] ? dir : a[key] < b[key] ? -dir : 0));

    const page = Number(params.page ?? 1);
    const total = list.length;

    return {
        data: list.slice((page - 1) * perPage, page * perPage),
        current_page: page,
        last_page: Math.max(1, Math.ceil(total / perPage)),
        total,
        per_page: perPage,
    };
}

export function recordEntry(payload: Record<string, any>) {
    const entry = {
        ...money(
            payload.type,
            Number(payload.amount),
            payload.description,
            21,
            payload.payment_tender_id ?? null,
        ),
        id: nextId++,
        notes: payload.notes ?? null,
    };
    ledger = [...ledger, entry];

    return entry;
}

export function deleteEntry(id: number) {
    ledger = ledger.filter((row) => row.id !== id);

    return { success: true };
}

export const billsSummary = {
    total_due: 6500,
    overdue: 1500,
    upcoming: 5000,
    count: 3,
    period: { start: '2026-09-26', end: '2026-09-26' },
};

/** Thirty days of takings for the trend chart, shaped rather than random. */
export const dailyTotals = Array.from({ length: 30 }, (_, index) => {
    const date = new Date('2026-08-28T00:00:00');
    date.setDate(date.getDate() + index);
    const weekend = [0, 6].includes(date.getDay());
    const income = weekend ? 15200 + index * 40 : 11400 + index * 30;
    const expense = weekend ? 4100 : 3300;

    return {
        date: date.toISOString().slice(0, 10),
        income,
        expense,
        balance: OPENING + (income - expense),
    };
});

export const periodHistory = {
    granularity: 'day' as const,
    period_days: 1,
    rows: Array.from({ length: 6 }, (_, index) => {
        const date = new Date('2026-09-21T00:00:00');
        date.setDate(date.getDate() + index);
        const day = date.toISOString().slice(0, 10);
        const moneyIn = 11800 + index * 220;
        const moneyOut = 3400 + index * 60;

        return {
            start: day,
            end: day,
            opening: OPENING,
            money_in: moneyIn,
            money_out: moneyOut,
            net: moneyIn - moneyOut,
            closing: OPENING + (moneyIn - moneyOut),
            count: 11,
            is_current: index === 5,
        };
    }),
};

/** Back to the starting day, so a capture run can repeat. */
export function resetFinancial(): void {
    ledger = rows.map((row, index) => ({ ...row, id: 900 + index }));
    nextId = 950;
}
