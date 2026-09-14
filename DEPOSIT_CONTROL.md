# Deposit Control

Open **Deposit Control** in the sidebar (`/deposit-control`). Cashiers and admins can start a shift; auditors can review snapshots and completed counts. Only the user who starts a shift can close it and submit counts.

1. Sync offline transactions, then select **Start shift & snapshot**.
2. At shift end, record and sync all transactions, then select **Capture closing snapshot**.
3. Enter the lockbox amount, select its tender, and enter each tender's balance **outside the lockbox**. Enter zero explicitly for empty tenders. Review and submit the counts.

For example, a PHP 1,000 lockbox, PHP 320 cash drawer, and PHP 200 GCash wallet produce PHP 1,520 actual overall funds. The lockbox is included in the selected tender exactly once.

Snapshots permanently save the running balance, calendar-day net balance, payment income, expense, income adjustments, payroll deductions, asset deductions, payout shares, and cumulative balances by tender. Calendar days use the application's Asia/Manila timezone. Amounts follow the financial ledger's sign conventions and include all deductions. Untagged transactions remain visible and contribute to the overall system balance.

Reconciliation formulas:

- Actual overall funds = lockbox + all tender balances outside the lockbox.
- Overall variance = actual overall funds − closing running balance.
- Tender variance = actual tender funds including its lockbox allocation − closing system tender balance.
- System shift change = closing running balance − opening running balance.

Positive variance means over; negative means short. System shift change includes backdated entries and edits between snapshots, and works across midnight. A shift-only physical variance cannot be established without an opening physical count, so it is not presented as a measured shortage. This release records discrepancies; it does not post automatic balancing adjustments.

There is one active shift for the shared POS ledger, including shifts awaiting final counts. Closing snapshots and submitted counts cannot be replaced. Later ledger edits do not alter saved snapshots. Sales are not blocked by this feature: coordinate the snapshot with the end of cashier activity. Offline transactions on other devices must also be synchronized first.

## Setup and verification

Install project dependencies (`composer install`, `npm ci`), run `php artisan migrate`, and build assets with `npm run build` using the project's supported PHP and Node versions. The migration adds `deposit_controls` and does not change ledger entries.

Run `php artisan test --filter=DepositControlTest` for workflow, authorization, validation, immutable snapshots, lockbox allocation, and overnight reconciliation coverage. The existing migration `2026_06_28_000001_add_product_sales_pct_to_incentive_rules.php` contains MySQL-specific SQL; the default SQLite test setup may need that pre-existing compatibility issue resolved before feature tests can run.
