# Profit sharing

The `/distribution` page uses the dashboard palette, touch-sized controls, horizontally scrollable navigation, mobile recipient/history cards and retained pie charts. Filters must be recomputed before a payout snapshot can be saved.

## Financial rules

- Unfiltered available profit uses the same payment ledger, payment dates and COGS-enabled report as the financial dashboard. That existing report deducts recorded payouts from available profit.
- Both incentive modes reserve their pool before calculating ownership dividends. An incentive pool exceeding available profit is visible but cannot be saved for payout.
- Product/category views are estimates before shared expenses. Clear filters before saving a payout snapshot.
- Dividend and incentive allocations use largest-remainder cent allocation so all recipient amounts reconcile with their pool. Inactive product owners' shares remain with the company.
- New snapshots include member dividends plus incentives, including product owners without equity. Company totals include company incentives. Old snapshots keep their recorded amounts.
- Payouts create cash ledger entries only for shareholder details. Company retention is not disbursed. Database locks reject repeated payouts and payouts from overlapping periods that have already been settled.
- Historical snapshots and ledger entries are not rewritten. Previously recorded incorrect company payouts require reconciliation rather than automatic deletion.

## Validation

`tests/Feature/ProfitSharingTest.php` covers rounding, filtered allocation consistency, payment-date/COGS alignment, incentive budgets, combined snapshots, retained earnings, duplicate and overlapping payouts, and date/filter validation. Run with the financial-report and deposit-reconciliation suites.
