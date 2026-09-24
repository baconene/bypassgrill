# Prepared Food catalog replacement

Source: the four CSVs supplied on 2026-09-24, retained verbatim. CSV notes are
reference data; the import follows the user's request to link products only to Food.

Run after deploying the code and taking the normal database backup:

```sh
php artisan migrate --force
php artisan db:seed --class=PreparedFoodCatalogSeeder --force
```

This is an explicit replacement seeder, not part of the demo `DatabaseSeeder`.
The migration only creates the audit table; deploying alone does not replace stock.

## Result

- 15 Food items, 26 distinct raw ingredients, 50 Food component rows.
- 23 products updated by their supplied ID/name, with 28 Food-only recipe rows.
- Matching products retain their IDs, SKU, photos, descriptions, modifiers and
  historical order links. Names, categories, prices and active flags come from the CSV.
- Plate and Spork / Takeout Box remain accessory products without inventory links:
  no packaging/supply links are invented, because the requested links are Food only.
- Prepared quantities come from `legacy_prepared_stock_transition.csv`: Chicken
  Jerk 16 pcs, Mixed Fresh Fruits 4 pcs, Steamed Rice 0.040 pcs; the others are zero.
- Raw stock starts at zero. Other inventory is archived rather than hard-deleted.
  Products absent from the new menu are deactivated, preserving their history.
- Compatible existing unit costs are retained. New items or changed stock units
  have zero cost until entered; there is no assumed kg-to-piece conversion.
- Inventory adjustments record reconciled quantities without posting purchases,
  expenses, COGS, payroll or cash movements. Historical ledger entries are retained.

## Incomplete recipes (confirmed by the user)

All missing quantities are **zero**, meaning incomplete, not cost-free. They can
be saved and edited, but production and affected product orders are refused until
every required quantity is positive. Cost recalculation skips incomplete products.
Known positive portions, including two skewers per meal, are imported unchanged.

Unspecified raw units use `unconfirmed` until measured. Review quantities, units
and costs in Inventory before production. In particular, the CSV's raw Rice `pcs`
and the 0.040 prepared Rice balance warrant checking; the seeder does not guess
whether these represent kilograms or portions.

Prepared Rice / Fresh Fruits have separate raw records. Raw longganisa and bottled
water receive a `(raw)` suffix to distinguish them from identically named Food.
Proposed recipe notes and unresolved ingredient choices remain in the source CSVs.

## Repeatability and audit

`inventory_catalog_imports` stores the pre-import inventory, products and recipes,
plus a result summary under `prepared-food-catalog-2026-09-24`. All changes commit
in one transaction. A conflicting source ID/name aborts and rolls everything back.
IDs from the source are matched only against records present before importing,
so newly generated database IDs cannot accidentally match later CSV references.

Repeating the seeder after success does nothing: later sales, stock receipts,
recipe corrections and product edits are never reset. Do not delete the marker
to reapply this replacement; make subsequent corrections through the application.
