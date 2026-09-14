# Deposit Control

Open **Deposit Control** in the sidebar (`/deposit-control`). Cashiers and admins can start a shift; auditors can review reports. Only the user who starts a shift can close it and save its actual balances.

1. Sync pending payments, then select **Start shift**. The opening financial snapshot is saved, and its breakdown stays hidden while the shift is open.
2. At shift end, record and sync all payments and deductions, then select **Close shift & view breakdown**. The closing snapshot is permanent, and the report appears immediately.
3. Enter the four actual amounts below, then select **Save actual balances & complete report**. Saved counts are final.

Select **Previous snapshots** to open `/deposit-control/history`. Completed snapshots appear in a paginated table with cashier, closing time, drawer cash, shift GCash, expected net, lockbox, total GCash, and both variances. Select **View** to show a saved breakdown below the table. The current-shift page keeps previous reports out of the working area; the newly completed report remains visible immediately after saving.

| Field | What to enter |
| --- | --- |
| Cash in the drawer | This shift's net cash before transferring it into the lockbox. Exclude any opening float. |
| GCash amount for this shift | This shift's net GCash collections after deductions, excluding the prior wallet balance. |
| Manually counted lockbox total | Count the entire lockbox **after adding the drawer cash**. Include funds from prior shifts. |
| Total GCash wallet value | The full GCash wallet balance at closing, including funds from prior shifts. |

The actual fields start blank. Enter zero explicitly for an empty balance. The system never fills actual counts from expected amounts. The lockbox total must include, and therefore cannot be less than, the transferred drawer cash.

## Two separate comparisons

**This shift's net balance** shows payment income, income adjustments, expenses, payroll deductions, asset deductions, and payout shares between the opening and closing snapshots.

- Expected shift net = closing running balance minus opening running balance.
- Actual combined shift net = drawer cash + this shift's GCash amount.
- Shift over/short = actual combined shift net minus expected shift net.

**Overall balance** compares all accumulated funds at closing.

- Expected overall balance = closing running balance.
- Actual overall balance = manually counted lockbox total + total GCash wallet value.
- Overall over/short = actual overall balance minus expected overall balance.

Drawer cash is already inside the lockbox total. Shift GCash is already inside the wallet total. Neither is added to overall funds again.

Example: expected shift net PHP 500, drawer PHP 300, and shift GCash PHP 190 produce a PHP 10 shift shortage. A lockbox total of PHP 1,000 and total GCash value of PHP 520 produce PHP 1,520 overall funds. Against a PHP 1,500 running balance, that is PHP 20 over overall. These are separate comparisons, not two amounts to combine.

The shift comparison relies on entering only this shift's net collections. An opening float or prior wallet funds entered there would inflate the shift actual. Snapshots include all ledger tenders and untagged entries; actual counts cover Cash and GCash. Other tenders remain visible in the expandable system breakdown but are not counted as actual funds in this workflow. Backdated entries and edits between snapshots affect the shift difference. Overnight shifts use the snapshot difference, not a calendar-day total.

One shared POS shift may be open or awaiting counts at a time. Sales are not blocked during closing, so coordinate counting and synchronize offline payments on every device. Later ledger edits do not change saved snapshots. No automatic balancing transaction is posted.

## Automatic one-time cleanup on deployment

`database/migrations/2026_09_14_000002_clean_up_legacy_deposit_controls.php` is the deployment cleanup script. It runs automatically with the deployment's normal migration command:

```sh
php artisan migrate --force
```

It permanently deletes **legacy Deposit Control records**, both completed and unfinished, from `deposit_controls`. It does not delete orders, payments, financial transactions, users, or tender definitions. New opening snapshots carry `version: 2`; those records are preserved even if the cleanup migration is rerun. Laravel records the migration as completed so later deployments do not repeat it. Rolling back cannot restore deleted records.

Run migrations before reopening the updated application to cashiers. The repository has CI checks but no production deployment workflow; the deployment process must invoke `php artisan migrate --force` for automatic cleanup. Uploading code alone does not execute it. Build updated assets with `npm run build` after installing project dependencies.

## Verification

```sh
php artisan test --filter=DepositReconciliationTest
php artisan test --filter=DepositControlTest
```

Tests cover separate shift/overall variances, decimal arithmetic, frozen snapshots, required counts, ownership, overnight shifts, and cleanup repeatability. The existing migration `2026_06_28_000001_add_product_sales_pct_to_incentive_rules.php` contains MySQL-specific SQL; the default SQLite feature-test setup needs that pre-existing compatibility issue resolved before all migrations can run.
