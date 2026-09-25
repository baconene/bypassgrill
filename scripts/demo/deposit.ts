// Fictional examples for the actual DepositControlPage component; no live records.
//
// Stateful on purpose. The capture script drives the real flow — start, close,
// count, submit — rather than posing each screen, so the screenshots show the
// transitions a cashier actually sees.
//
// The figures are self-consistent with DepositReconciliation: the shift moves
// 8,750.00 and the counted money comes to 8,700.00, so the report ends 50.00
// short. A variance is more instructive than a clean balance, because a short
// count is the thing a cashier has to recognise.
export const DEMO_USER_ID = 7;
const totals = (
    payment: number,
    expense: number,
    payroll: number,
): Record<string, number> => ({
    payment,
    income_adjustment: 0,
    expense,
    payroll,
    asset_deduction: 0,
    payout_share: 0,
});
const openingSnapshot = {
    captured_at: '2026-09-26 10:00:00',
    business_date: '2026-09-26',
    running_balance: 8000,
    opening_cash: 3000,
    cumulative_totals: totals(120000, 15000, 30000),
    balance_by_tender: [
        { id: 1, name: 'Cash', balance: 5400 },
        { id: 2, name: 'GCash', balance: 2600 },
    ],
};
const closingSnapshot = {
    captured_at: '2026-09-26 21:30:00',
    business_date: '2026-09-26',
    // 12,450.00 taken, 1,200.00 spent, 2,500.00 of salary released: +8,750.00.
    running_balance: 16750,
    cumulative_totals: totals(132450, 16200, 32500),
    balance_by_tender: [
        { id: 1, name: 'Cash', balance: 13350 },
        { id: 2, name: 'GCash', balance: 3400 },
    ],
};
const priorShift = {
    id: 17,
    user_id: DEMO_USER_ID,
    user: { name: 'Demo Cashier' },
    opened_at: '2026-09-25 10:00:00',
    closed_at: '2026-09-25 21:15:00',
    opening_snapshot: {
        ...openingSnapshot,
        captured_at: '2026-09-25 10:00:00',
    },
    closing_snapshot: {
        ...closingSnapshot,
        captured_at: '2026-09-25 21:15:00',
        running_balance: 8000,
    },
    reconciliation: {
        version: 2,
        drawer_cash: 4800,
        shift_gcash: 2600,
        lockbox_total: 5400,
        total_gcash: 2600,
        shift_actual: 7400,
        shift_net: 7400,
        shift_variance: 0,
        overall_actual: 8000,
        overall_expected: 8000,
        overall_variance: 0,
        shift_breakdown: {
            payment: 10400,
            income_adjustment: 0,
            expense: 1000,
            payroll: 2000,
            asset_deduction: 0,
            payout_share: 0,
        },
        notes: null,
    },
};
type Shift = typeof priorShift;
let active: Shift | null = null;
let history: Shift[] = [priorShift];
let nextId = 18;
const cents = (value: unknown) => Math.round(Number(value ?? 0) * 100);

export const depositState = () => ({
    active,
    history: { data: history, current_page: 1, last_page: 1 },
});

export function startShift(openingCash: unknown): Shift {
    active = {
        id: nextId++,
        user_id: DEMO_USER_ID,
        user: { name: 'Demo Cashier' },
        opened_at: '2026-09-26 10:00:00',
        closed_at: null,
        opening_snapshot: {
            ...openingSnapshot,
            opening_cash: Number(openingCash ?? 0),
        },
        closing_snapshot: null,
        reconciliation: null,
    };

    return active;
}

export function closeShift(): Shift {
    active = {
        ...(active as Shift),
        closed_at: '2026-09-26 21:30:00',
        closing_snapshot: closingSnapshot,
    };

    return active;
}

/** Mirrors DepositReconciliation so the sample report adds up the same way. */
export function reconcileShift(counts: Record<string, unknown>): Shift {
    const shift = active as Shift;
    const drawer = cents(counts.drawer_cash);
    const shiftGcash = cents(counts.shift_gcash);
    const lockbox = cents(counts.lockbox_total);
    const totalGcash = cents(counts.total_gcash);
    const shiftNet =
        cents(shift.closing_snapshot?.running_balance) -
        cents(shift.opening_snapshot.running_balance);
    const breakdown: Record<string, number> = {};

    for (const key of [
        'payment',
        'income_adjustment',
        'expense',
        'payroll',
        'asset_deduction',
        'payout_share',
    ]) {
        breakdown[key] =
            (cents(shift.closing_snapshot?.cumulative_totals[key]) -
                cents(shift.opening_snapshot.cumulative_totals[key])) /
            100;
    }

    const done = {
        ...shift,
        reconciliation: {
            version: 2,
            drawer_cash: drawer / 100,
            shift_gcash: shiftGcash / 100,
            lockbox_total: lockbox / 100,
            total_gcash: totalGcash / 100,
            shift_actual: (drawer + shiftGcash) / 100,
            shift_net: shiftNet / 100,
            shift_variance: (drawer + shiftGcash - shiftNet) / 100,
            overall_actual: (lockbox + totalGcash) / 100,
            overall_expected:
                cents(shift.closing_snapshot?.running_balance) / 100,
            overall_variance:
                (lockbox +
                    totalGcash -
                    cents(shift.closing_snapshot?.running_balance)) /
                100,
            shift_breakdown: breakdown,
            notes: (counts.notes as string) || null,
        },
    };
    history = [done, ...history];
    active = null;

    return done;
}

/** Back to no open shift, so a capture run can start from the beginning. */
export function resetDeposit(): void {
    active = null;
    history = [priorShift];
    nextId = 18;
}
