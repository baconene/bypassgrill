# Food Items Plan

Status: **Proposed, not started** (2026-09-24)

## What you asked for

- A new item type, **Food**.
- Food has ingredients, and those ingredients give it a cost.
- The POS checks **Food** for availability instead of raw ingredients.
- A product can be built from Ingredients **or** Food.
- Building a Food's ingredients works like building a product's recipe, reusing that screen.

## The goal in one line

Stop asking "do we have pork?" when the real question is "do we have grilled
pork left?". Raw stock answers the first; only prepped food answers the second.

## The decision that shapes everything else

**Food is a new `item_type` on `ingredients`, not a new table.**

A Food is a thing you hold a quantity of, that has a unit, a cost per unit, a
minimum level, stock movements and a cost ledger. `ingredients` already is that
table. Giving Food its own table would mean a second copy of stock movements, a
second copy of the cost ledger, a second valuation report and a second set of
joins in COGS.

The payoff is large and worth stating plainly, because it is the argument for
this shape over any other:

| Already works, with no change | Why |
| --- | --- |
| POS sold-out and low-stock badges | [Product::stockStatus](app/Models/Product.php) walks `recipes → ingredient` and checks quantity. A Food *is* an ingredient row, so a product built from Food is judged on the Food's quantity |
| COGS on sale | [InventoryCostService::consume](app/Services/InventoryCostService.php) walks the same recipe rows and writes a consumption entry per tracked component |
| Stock movements and history | `inventory_transactions` keys on `ingredient_id` |
| The cost ledger | `inventory_cost_entries` keys on `ingredient_id` |
| Weighted-average costing | [InventoryService::recordTransaction](app/Services/InventoryService.php) already does it on Stock In |
| Inventory valuation, the Inventory reports tab, waste, counts, Undo Stock In | All keyed on the same table |
| Soft deletes and history retention | Already on `Ingredient` |

Nothing in that column is free work avoided by luck. It is the test of whether
the model is right: if Food needed all of it rewritten, Food would not be an
inventory item.

## What is genuinely new

Only two things:

1. **A Food owns a recipe.** Today a recipe belongs to a product.
2. **A production run.** Turning 6kg of pork into 30 servings of grilled pork is
   a movement the system has never had.

Everything else in this plan follows from those two.

## Data model

### `ingredients`

- `item_type` enum gains **`food`**. Current values: `ingredient`, `tool`,
  `equipment`, `supply` ([migration](database/migrations/2026_05_20_093214_add_item_type_to_ingredients_table.php)).
- No other column changes. A Food uses `unit` (serving, piece, portion),
  `current_quantity`, `min_quantity`, `cost_per_unit`, `track_inventory`.

### `recipes` — give the row an owner

Today: `product_id`, `ingredient_id`, `quantity`, `unit`.

Add `food_id` (nullable, FK to `ingredients`), make `product_id` nullable, and
constrain **exactly one** of the two to be set.

| Row shape | Means |
| --- | --- |
| `product_id` set | A product's recipe line, exactly as today |
| `food_id` set | A Food's component line |

`recipes.ingredient_id` may now point at a Food. That is how a product uses
Food, and it needs no new column: the self-reference runs through `ingredients`.

**Why extend `recipes` rather than add `food_components`.** A separate table is
tidier on paper, but you asked to reuse the product recipe builder, and reuse is
easiest when both owners write the same row shape. The existing
`$product->recipes` relation is unaffected because it already scopes on
`product_id`. The alternative is recorded under Decisions.

### No new ledger table

Production is recorded with the existing `inventory_cost_entries` and two new
`kind` values. See Costing.

## Flows

| Action | Inventory transactions | Cost entries | P&L effect |
| --- | --- | --- | --- |
| Create a Food with components | none | none | none |
| **Produce** 30 servings | `stock_out` per component, `stock_in` on the Food | `production_input` per component (negative), `production_output` on the Food (positive) | **zero, by construction** |
| Count a Food up or down | `adjustment` | `count_gain` / `count_loss` | Loss only, as today |
| Prepped food spoils | `waste` | `waste` | Inventory loss |
| Sell a product built from Food | `stock_out` on the Food | `consumption`, source `food` | COGS |
| Cancel or edit that order | `stock_in` restore | `consumption_reversal` | COGS reverses |
| Undo a production run | reverse both legs | reversing pair | zero |

## Costing, and the trap in it

**A production run must not touch profit.** It is a transfer between two assets:
value leaves the ingredients and arrives in the Food. Profit moves only when the
Food is *sold*.

Get this wrong and COGS double-counts: once when the ingredients are consumed to
make the food, and again when the food is consumed to make the sale.

The guard is structural rather than a rule someone has to remember:

- New kinds `production_input` and `production_output` are in **neither** the
  COGS bucket (`consumption`, `consumption_reversal`) nor the losses bucket
  (`waste`, `count_loss`) in [ReportService::getProfitLossReport](app/Services/ReportService.php).
  Being absent from both lists, they cannot reach profit.
- The two legs are equal and opposite, so they net to zero in any total that
  does sweep them up.

**Cost per produced unit** = total component cost ÷ **actual** yield. Actual,
not planned: if a 30-serving batch yields 28, each serving costs more, and that
is a real fact about the kitchen worth seeing.

That figure is the `unitCost` on the Food's Stock In leg, so the existing
weighted-average code in `recordTransaction` blends it with whatever stock of
that Food is already on hand. No new costing maths.

**Product cost** is unchanged: recipe quantity × component `cost_per_unit`,
where the component may now be a Food. The recipe-cost drift shown on the
Products page keeps working and becomes more useful, since a Food's cost moves
every time a batch is produced at a different price.

## POS availability

No code change. A product built from Food is sold out when the Food is at zero,
because `stockStatus()` already checks whatever the recipe lists.

The behaviour you want falls out of the data rather than a rule: if a product's
recipe names the Food and not the raw pork, raw pork cannot mark it sold out.
Products that still list raw ingredients keep behaving exactly as they do now,
so nothing has to be converted on a deadline.

One consequence to accept deliberately: **a Food at zero is sold out even if the
ingredients to make more are sitting right there.** That is the point — it
reflects the kitchen, where unprepped pork is not a dish. Producing a batch
clears it.

## UI

### Inventory: adding a Food

The Add Item sheet gains a type of **Food**, and choosing it reveals a
components builder that is the product recipe builder: rows of ingredient +
quantity + unit, a live cost total, and the same `Ingredient` options list.
Extract it from [ProductManagement.vue](resources/js/pages/ProductManagement.vue)
into a shared component rather than copying it; the two must not drift.

The Food row on the inventory table shows its per-unit cost from components and
the same **Out of date** tag the Products page uses when stored cost has drifted
from component cost.

### Inventory: producing a batch

A **Produce** action on a Food, which is the one genuinely new screen:

- Batch size, defaulting to 1.
- The components it will consume, with quantities and a total cost, live.
- Actual yield, defaulting to batch size.
- Resulting cost per unit, and what it does to the weighted average.
- Refusal, with the short component named, when stock cannot cover the batch —
  the same check and message style as `consume()`.

### Products

The ingredient picker widens to include Foods. Group the list under
**Food** and **Ingredients** headings so it stays obvious which is which, and
show the Food's unit as it does today.

## Phases

### Phase 1: Food exists

Enum value, validation, item type in both UIs, the components builder extracted
and shared, cost from components. No production yet; quantity is set by count.
Useful on its own: the POS can already be driven off a counted Food.

### Phase 2: Production

`production_input` / `production_output` kinds, the Produce screen, yield and
weighted average, the shortage check. Undo a production run, matching Undo Stock
In.

### Phase 3: Products use Food

Widen the picker, group it, convert a first product by hand and watch a full
sale through the ledger before converting more.

### Phase 4: Reporting

`InventoryCostSource` gains `food`, so the Inventory reports tab and the P&L
product table can separate prepped food from raw stock. A production report:
batches, yield variance, cost per unit over time.

## Decisions (defaults in bold)

1. **Food is an `item_type` on `ingredients`.** Alternative: its own table.
   Rejected above.
2. **A Food's components are recipe rows with `food_id` set.** Alternative: a
   `food_components` table — cleaner separation, but the builder and the
   cost-rollup code would exist twice.
3. **Food cannot contain Food, in v1.** Enforced server-side. Allowing it means
   cycle detection and recursive cost and availability, which is real work for a
   case you may not need. This is the single most likely thing to revisit, so
   the check belongs in one place, named, ready to be relaxed.
4. **Production is cost-neutral; only selling the Food is COGS.**
5. **Cost per unit uses actual yield, not planned.**
6. **Existing products are left alone.** Converting to Food is opt-in, per
   product, whenever you choose.
7. **A Food is counted like any other stock.** Production is the good path;
   a count adjustment stays available for when someone forgets to record one.

## Questions I could not answer from the code

These change the work materially, and guessing would be worse than asking.

1. **Does a Food ever get bought ready-made?** Buying pre-marinated pork would
   mean a Food needs a plain Stock In with a purchase price as well as
   production. Easy to allow, but only if it happens.
2. **Does prepped food carry over to the next day, or is it written off?** If it
   is written off nightly, an end-of-day write-off action is worth building in
   Phase 2, and spoilage becomes a number you will want reported.
3. **Is a Food ever sold directly, without a product wrapping it?** The plan
   assumes not: a product always sits in front.
4. **Do you want a Food to be sellable while stock is negative?** Today stock
   cannot go negative and an order is refused. Prepped food is where a manager
   most often wants to override.

## Verification

**Tests** (`tests/Feature/FoodItemsTest.php`)

- Producing a batch changes net profit by exactly **0**, at every stage of the
  ledger cutover.
- Producing 30 servings consumes exactly 30 × each component quantity.
- Yield below plan raises cost per unit; the weighted average blends correctly
  against existing stock.
- A batch that outruns component stock is refused and names the short component.
- Selling a product built from Food writes one consumption entry against the
  Food, at the Food's cost, not the components'.
- Cancelling that order reverses it at the original cost.
- A product built from Food reads as sold out when the Food hits zero, even with
  components in stock.
- Sum of `production_input` + `production_output` for a run is 0.
- A Food cannot be added as a component of a Food.
- Undoing a production run restores components and removes the Food.

**Command**: extend `cogs:verify` so every `production_output` has matching
inputs netting to zero.

## Acceptance criteria

1. Producing food changes P&L net profit by exactly 0.
2. A product built from Food goes out of stock when the Food does, not when a
   raw ingredient does.
3. Every peso of a Food's cost traces to the production run that created it, and
   every peso of COGS traces to a sale of it.
4. The components builder on the Food screen and the recipe builder on the
   product screen are the same component.
