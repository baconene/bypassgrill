# Profit sharing

The `/distribution` page uses the dashboard palette, touch-sized controls, horizontally scrollable navigation, mobile recipient/history cards and retained pie charts. Filters must be recomputed before a payout snapshot can be saved.

## Financial rules

- Unfiltered allocations use Financial net cash movement: payments + income adjustments - all cash expenses - payroll - asset deductions - recorded payouts. Compare the same dates with Financial asset deductions enabled. Inventory purchases remain cash expenses; COGS is reference-only and is not deducted again. Opening balances are excluded from the distribution pool. This cash allocation policy is distinct from the COGS-based profit-and-loss report.
- Both incentive modes reserve their pool from positive net cash movement before calculating ownership dividends. Net-profit percentage incentive rules use this cash distribution base on this page. An incentive pool exceeding available profit is visible but cannot be saved for payout.
- Product/category views are estimates before shared expenses. Clear filters before saving a payout snapshot.
- Dividend and incentive allocations use largest-remainder cent allocation so all recipient amounts reconcile with their pool. Inactive product owners' shares remain with the company.
- New snapshots include member dividends plus incentives, including product owners without equity. Company totals include company incentives. Old snapshots keep their recorded amounts.
- Payouts create cash ledger entries only for shareholder details. Company retention is not disbursed. Database locks reject repeated payouts and payouts from overlapping periods that have already been settled.
- Historical snapshots and ledger entries are not rewritten. Previously recorded incorrect company payouts require reconciliation rather than automatic deletion.

## Validation

`tests/Feature/ProfitSharingTest.php` covers rounding, filtered allocation consistency, payment-date alignment, reference-only COGS, direct Financial endpoint reconciliation, incentive budgets, combined snapshots, retained earnings, duplicate and overlapping payouts, and date/filter validation. Run with the financial-report and deposit-reconciliation suites.
