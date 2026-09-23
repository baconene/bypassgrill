# COGS Ledger Plan

Status: **Phases 0-3 implemented; the cutover is armed but not switched on** (2026-09-23)

## Switching the ledger on

The code is in place and `cogs_ledger_start_at` is still null, so reports keep
using the recorded order-item costs. Nothing changes until you run:

```
php artisan cogs:verify --from=2026-09-23     # review first
php artisan cogs:cutover --at=YYYY-MM-DD      # hand COGS to the ledger
php artisan cogs:cutover --undo               # change your mind
```

The cutover refuses any date before the shadow ledger started, because orders
from before that have no entries and their COGS would read as zero.

## Current rollout

- Soft-delete ingredients; retain stock history, including restores of archived ingredients.
- Order edits restore the old items and rebuild through OrderService in one transaction. Failed edits roll back both stock and cost entries.
- New stock movements and order consumption write signed inventory_cost_entries with ingredient cost snapshots and explicit order/item links. Reversals link uniquely to their original entries.
- Tracked and untracked recipes are costed; products without recipes use Product.cost. Stock In accepts a purchase unit cost and updates the weighted average.
- Initial stock now has a stock transaction and linked cost/cash history. Valuation uses quantity multiplied by cost.
- Duplicate order processing is prevented; historical orders are not automatically deducted again. The backfill command uses atomic per-order transactions.
- Read-only verification: php artisan cogs:verify --from=2026-09-23 --to=2026-09-30

### What the report cutover changed

The `include_cogs` toggle is gone from the Reports page and the API. There is
one profit-and-loss view now: stock is an asset when bought and a cost when
used. COGS comes from the ledger for orders created on or after
`cogs_ledger_start_at`, and from the recorded item costs for orders before it,
so a period spanning the cutover adds both. A new **Inventory losses** line
deducts waste and stock missing at a count, on the date it went missing.

Count *gains* are deliberately not deducted. The goal states that profit
changes only when stock is used, and acceptance criterion 1 requires that
adding inventory in any way leaves profit at exactly zero; counting stock up is
a way of adding it. Gains are still recorded and shown in the Inventory reports
tab, they simply never manufacture profit. This resolves a conflict with the
P&L formula below, which lists `count_gain` in the losses sum.

Profit sharing is untouched, per the updated business decision. It allocates
cash, so it asks `ReportService` for the cash basis directly: inventory
purchases stay inside operating expenses there and no losses are deducted.
The two views are meant to differ, so acceptance criterion 2 no longer holds
as written.

Legacy `Inventory Stock In:`, `Initial stock:` and `Inventory Adjustment:`
expense rows are still excluded from operating expenses by description and
shown as a memo, so no historical rows had to be retyped. Reclassifying them
to a dedicated type remains available as cleanup.

### Updated business decision

The later user instructions take precedence over the original plan: **Profit Sharing stays Profit-only and allocates the closing cash balance, including prior months**. Do not switch it to COGS-based P&L. P&L and cash distribution are distinct views; retain clear labels when implementing Phase 3.


## Goal

Adding, correcting, or counting inventory must never change profit. Profit changes only when stock is **used** (sold, wasted, or lost in a count). Cash reports still show what was spent, in the tender that paid it.

## Principle

| Table | Records | Answers |
| --- | --- | --- |
| `financial_transactions` | Cash movement | "How much money is in the drawer / GCash / lockbox?" |
| `inventory_transactions` | Quantity movement | "How much stock do we have?" |
| **`inventory_cost_entries`** (new) | Cost movement | "What did the stock we used cost?" |

Buying stock is cash out plus an asset in, with no profit effect. Using stock is a cost with no cash movement. Today both are written into `financial_transactions` as `expense`, and reports try to separate them again by matching description text.

## Current problems this fixes

1. `Initial stock: X` and `Inventory Adjustment: X` expenses are not caught by the `Inventory Stock In%` filter, so they are counted as operating expenses and again as COGS ([ReportService.php](app/Services/ReportService.php), [ReportController.php](app/Http/Controllers/Api/V1/ReportController.php) charts).
2. Inventory expenses have no tender. They lower the deposit snapshot's running balance and cause false shift variances when the purchase was not paid from the drawer.
3. A wrong Stock In cannot be undone by adjusting down. The expense stays.
4. COGS comes from `Product.cost`, which only updates when someone clicks "calculate cost". It does not follow ingredient costs.
5. Profit distribution uses P&L with COGS off, while the dashboard uses COGS on. They show different profit for the same period.
6. Editing an order ([OrderController::update](app/Http/Controllers/Api/V1/OrderController.php)) rebuilds items without `unit_cost`, which sets that order's COGS to 0, and never adjusts stock.
7. Deleting an ingredient cascade-deletes its whole `inventory_transactions` history (no soft deletes).
8. `getInventoryValuation()` returns `current_quantity` as the valuation instead of quantity × cost.
9. `ProcessOrderJob` would deduct stock a second time if it were ever dispatched. It is currently unused.

## Data model

### New table: `inventory_cost_entries`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint | |
| `kind` | enum | `purchase`, `purchase_reversal`, `consumption`, `consumption_reversal`, `waste`, `count_loss`, `count_gain` |
| `source` | enum | `ingredient` (tracked stock), `untracked_ingredient` (recipe cost, no stock movement), `product_fallback` (product has no recipe; uses `Product.cost`) |
| `inventory_transaction_id` | FK nullable, `nullOnDelete` | The stock movement this cost belongs to. Null for untracked and fallback rows. |
| `ingredient_id` | FK nullable, `nullOnDelete` | |
| `ingredient_name` | string nullable | Copy kept so history survives renames and deletes |
| `order_id` | FK nullable, `nullOnDelete` | Replaces the `'order_123'` reference string |
| `order_item_id` | FK nullable, `nullOnDelete` | |
| `quantity` | decimal(12,3) | **Signed.** Reversals are negative. |
| `unit_cost` | decimal(12,4) | Cost per unit at the moment of movement |
| `total_cost` | decimal(12,2) | **Signed.** `SUM()` gives the net figure with no CASE logic. |
| `financial_transaction_id` | FK nullable, `nullOnDelete` | Purchases only: the cash-out entry |
| `user_id` | FK nullable | |
| `recognized_at` | datetime | Decides the reporting period for waste and count rows |
| `timestamps` | | |

Indexes: `(kind, recognized_at)`, `order_id`, `ingredient_id`, `inventory_transaction_id`.

### Changes to existing tables

- `ingredients`: add `deleted_at` (soft deletes) so history is never wiped.
- `financial_transactions`: new type value `inventory_purchase`. It counts as cash out, but it is never an expense in profit.
- `order_items.unit_cost` / `cost_subtotal`: kept, but written **from the ledger** (sum of the item's consumption rows) instead of `Product.cost`. This keeps the order detail page and [SalesAggregateService](app/Services/Distribution/SalesAggregateService.php) working unchanged.

## Costing rules

**Weighted-average cost.** On a Stock In with a purchase price:

```
new_cost = (max(old_qty, 0) × old_cost + in_qty × in_unit_cost) / (max(old_qty, 0) + in_qty)
```

The result is written back to `ingredients.cost_per_unit`. Every later consumption uses that value. The Stock In form gets a **unit cost** field, which defaults to the current `cost_per_unit`.

**COGS per order item**, per recipe line:

| Recipe line | Entry written |
| --- | --- |
| Tracked ingredient | `consumption` / `ingredient`, linked to the stock-out row |
| Untracked ingredient (salt, oil, etc.) | `consumption` / `untracked_ingredient`, no stock movement |
| Product has no recipe at all | One `consumption` / `product_fallback` row at `Product.cost` |

## Flows

| Action | Inventory transaction | Cost entry | Cash ledger (`financial_transactions`) |
| --- | --- | --- | --- |
| New item with starting stock | `stock_in` | `purchase` | `inventory_purchase` in the chosen tender, or none if opening stock was already owned |
| Stock In | `stock_in` | `purchase` (weighted average updated) | `inventory_purchase` in the chosen tender, or none if not paid from business funds |
| Undo a wrong Stock In | `stock_out` (reason: correction) | `purchase_reversal` (negative) | Reversing entry on the linked cash entry |
| Order item added | `stock_out` per tracked ingredient | `consumption` rows | none |
| Order cancelled / deleted | `stock_in` (restore) | `consumption_reversal` (negative, at the **original** unit cost) | none |
| Order edited | Restore old items, then deduct new items | Reversal + new consumption | none |
| Waste | `waste` | `waste` | none |
| Count: adjustment down | `adjustment` | `count_loss` | none |
| Count: adjustment up | `adjustment` | `count_gain` (negative cost) | none. **A count is not a purchase.** Use Stock In for purchases. |
| Change `cost_per_unit` manually | none | none (affects future consumption only) | none |

Every flow runs inside one DB transaction with the stock movement.

## Reports after the change

**P&L** ([ReportService::getProfitLossReport](app/Services/ReportService.php)), a single mode with the `includeCogs` toggle removed:

```
Net revenue           (payments, unchanged)
− COGS                SUM(total_cost) of consumption + consumption_reversal rows for orders paid in the period
= Gross profit
+ Income adjustments
− Operating expenses  type = 'expense' only (no description filters)
− Inventory losses    SUM(total_cost) of waste + count_loss + count_gain, by recognized_at
− Payroll
− Payout shares
= Net profit
```

A separate memo section lists inventory purchases for the period, for reference only. They are never subtracted.

| Consumer | Change |
| --- | --- |
| Dashboard | Uses the new P&L (already calls it) |
| Daily / monthly charts | Expense = `expense` + `payroll`. Remove the `COGS:%` and `Inventory Stock In%` LIKE filters. |
| Profit distribution | Uses the new P&L instead of `includeCogs = false`. **This changes distribution results** (see decisions). |
| Ledger, summary, deposit snapshot | `inventory_purchase` counts as cash out in its tender. Add it to every explicit type list (`total_out` CASEs, `DepositReconciliation` breakdown, validation rules, frontend labels). |
| Inventory valuation | quantity × `cost_per_unit`, plus a total |
| New: COGS report | By product, by ingredient, by source; shows how many orders fell back to `product_fallback` |

## Cutover

- New setting `cogs_ledger_start_at`, the timestamp when the ledger went live.
- P&L COGS for orders created **before** the cutover: the old `order_items.cost_subtotal`. After: the ledger. A period spanning the cutover adds both.
- Existing expense rows described as `Inventory Stock In: %`, `Initial stock: %` or `Inventory Adjustment: %` are migrated to type `inventory_purchase` (reversible, matched by description). This removes the historical double counting.
- Saved `distribution_snapshots` stay as they are. They are records of what was paid out.

## Phases

### Phase 0: Fixes that stand on their own

- Soft deletes on `Ingredient`. Inventory pages hide deleted items, and history keeps them.
- `OrderController::update`: restore stock, then rebuild items through `OrderService::addOrderItem` so cost and stock are correct.
- Remove the unused `ProcessOrderJob` (or make it idempotent).
- Fix `getInventoryValuation()`.

### Phase 1: Ledger in shadow mode

- Migration, `InventoryCostEntry` model, `InventoryCostKind` / `InventoryCostSource` enums.
- `InventoryService`: write cost entries in `recordTransaction`, `deductForOrder` and `restoreOrderStock`; weighted-average update; write `order_items.unit_cost` / `cost_subtotal` from the ledger.
- Replace `'order_' . $id` reference lookups with `order_id` / `order_item_id` columns on `inventory_transactions` (keep `reference` for display).
- Reports still use the old logic. Run both for about a week and compare with the verify command.

### Phase 2: Purchases in the cash ledger

- New `inventory_purchase` FT type, linked from the cost entry.
- Stock In form: unit cost, tender (Cash / GCash / other), "not paid from business funds" option.
- "Undo Stock In" action on the transaction history row.
- Adjustment dialog: labeled as a **count**, with no expense.
- Stop writing `expense` rows from inventory code ([InventoryService.php](app/Services/InventoryService.php), [InventoryController::store](app/Http/Controllers/Api/V1/InventoryController.php)).

### Phase 3: Switch reports

- New P&L, charts, dashboard, profit distribution; remove the `include_cogs` toggle from `ReportsPage.vue`.
- Add the `inventory_purchase` type to the financial page, deposit control and the documentation page.
- Run the historical expense migration and set `cogs_ledger_start_at`.

### Phase 4: Cleanup

- Remove description LIKE filters and `2026_06_20_000001_delete_cogs_financial_transactions`-era assumptions.
- Retire `ft:purge-cancel-expenses` (no longer possible).
- Make `BackfillInventoryDeductions` write cost entries.
- Update `SYSTEM_DOCUMENTATION.md` and the in-app documentation.

## Verification

**`php artisan cogs:verify {--from} {--to}`**
- Every order-linked `stock_out` has a matching `consumption` row, and every restore has a reversal.
- Every `inventory_purchase` FT has exactly one `purchase` cost entry.
- Per paid order: `SUM(order_items.cost_subtotal)` = ledger COGS.
- No `expense` FT rows written by inventory code after the cutover.

**Feature tests** (`tests/Feature/CogsLedgerTest.php`)
- Stock In of any amount: net profit for the period is unchanged.
- Selling 2 of a product: COGS = recipe quantity × average cost × 2, and gross profit drops by exactly that.
- Cancel and delete: COGS returns to 0 at the original cost, even if `cost_per_unit` changed in between.
- Order edit: COGS and stock match the new items.
- Waste and count loss appear under inventory losses in their own period.
- Weighted-average math, including a starting quantity of zero and a negative one.
- Stock In paid from Cash changes the deposit shift's expected net. Stock In marked "not paid from business funds" does not.
- A product with no recipe falls back to `Product.cost`.

## Acceptance criteria

1. Adding inventory in any way changes P&L net profit by exactly 0.
2. The dashboard, P&L page and profit distribution show the same net profit for the same period.
3. The deposit shift variance changes only when a purchase is paid from a counted tender.
4. Every COGS figure can be traced to rows in `inventory_cost_entries`.

## Decisions (defaults in bold)

1. COGS source: **ingredient-based, falling back to `Product.cost` when a product has no recipe**. Untracked recipe ingredients are **included** at `cost_per_unit`.
2. Costing method: **weighted average**. FIFO is not planned.
3. History: **cutover date, no restating past COGS**. The old inventory expense rows **are** retyped, which changes past "COGS off" figures.
4. Profit distribution: **retain closing cash balance, including previous months**, as subsequently requested. The original P&L-switch proposal is superseded.
5. Owner-paid purchases: **recorded as a cost entry with no cash entry**. Alternative: record them as an owner contribution plus a purchase.
6. COGS timing: **recognized with the order's payment**, the same as revenue. Cost entries are written when stock is deducted (item added to the order).
