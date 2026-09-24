<?php

namespace App\Services;

use App\Enums\InventoryCostKind;
use App\Enums\InventoryCostSource;
use App\Enums\InventoryTransactionType;
use App\Models\Ingredient;
use App\Models\InventoryCostEntry;
use App\Models\InventoryTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Turning ingredients into food.
 *
 * A production run is a transfer between two assets, never a cost: value leaves the
 * components and arrives in the food. Profit moves later, once, when the food is sold.
 * Recording it as a cost here would make COGS count the same dish twice.
 *
 * The guard is structural rather than a rule to remember. production_input and
 * production_output appear in neither the COGS bucket nor the losses bucket in
 * ReportService, so they cannot reach profit, and the two legs are equal and opposite
 * so they net to zero in any total that does sweep them up.
 */
class FoodProductionService
{
    /**
     * @param  float  $batch  how many units were planned
     * @param  float|null  $yield  how many were actually produced; defaults to the batch
     */
    public function produce(Ingredient $food, float $batch, ?float $yield = null, ?string $notes = null): InventoryTransaction
    {
        $yield ??= $batch;

        return DB::transaction(function () use ($food, $batch, $yield, $notes) {
            abort_if($batch <= 0, 422, 'Enter how many units this batch makes.');
            abort_if($yield <= 0, 422, 'A batch has to yield at least one unit.');

            $food = Ingredient::withTrashed()->whereKey($food->id)->lockForUpdate()->firstOrFail();
            abort_unless($food->isFood(), 422, 'Only a Food item can be produced.');
            abort_if($food->trashed() || ! $food->is_active, 422, 'Archived Food cannot be produced.');

            $components = $food->components()->with('ingredient')->orderBy('ingredient_id')->get();
            abort_if($components->isEmpty(), 422, 'Give '.$food->name.' its ingredients before producing it.');

            // Lock every component before checking any, so two batches cannot both pass
            // the check and then overdraw the same stock.
            $ingredients = Ingredient::whereIn('id', $components->pluck('ingredient_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $required = [];

            foreach ($components->groupBy('ingredient_id') as $id => $lines) {
                $ingredient = $ingredients->get($id);
                abort_unless($ingredient, 422, 'An ingredient of this Food was removed. Fix its recipe first.');
                abort_if($ingredient->isFood(), 422, 'A Food cannot be made from another Food.');

                $need = round((float) $lines->sum('quantity') * $batch, 3);
                abort_if($need <= 0, 422, 'Component quantities have to be greater than zero.');

                if ($ingredient->track_inventory && (float) $ingredient->current_quantity < $need) {
                    abort(422, sprintf(
                        'Not enough %s: %s %s needed, %s %s on hand.',
                        $ingredient->name,
                        rtrim(rtrim(number_format($need, 3, '.', ''), '0'), '.'),
                        $ingredient->unit,
                        rtrim(rtrim(number_format((float) $ingredient->current_quantity, 3, '.', ''), '0'), '.'),
                        $ingredient->unit,
                    ));
                }

                $required[$id] = $need;
            }

            $inventory = app(InventoryService::class);
            $consumed = [];
            $totalCost = 0.0;

            foreach ($required as $id => $need) {
                $ingredient = $ingredients[$id];
                $unitCost = (float) $ingredient->cost_per_unit;
                $lineCost = round($need * $unitCost, 2);
                $totalCost = round($totalCost + $lineCost, 2);

                $consumed[] = [
                    'ingredient' => $ingredient,
                    'quantity' => $need,
                    'unit_cost' => $unitCost,
                    'total_cost' => $lineCost,
                    'transaction' => $ingredient->track_inventory
                        ? $inventory->recordTransaction(
                            $ingredient, $need, InventoryTransactionType::STOCK_OUT,
                            null, 'Produced '.$food->name, recordCost: false,
                        )
                        : null,
                ];
            }

            // Actual yield, not the batch: a batch of 20 that makes 18 costs more each,
            // and that is a fact about the kitchen worth seeing.
            $unitCost = round($totalCost / $yield, 4);

            $output = $inventory->recordTransaction(
                $food, $yield, InventoryTransactionType::STOCK_IN,
                null, $notes ?: 'Produced '.$yield.' '.$food->unit, recordCost: false, unitCost: $unitCost,
            );

            $reference = 'production_'.$output->id;
            $output->update(['reference' => $reference]);

            InventoryCostEntry::create([
                'kind' => InventoryCostKind::PRODUCTION_OUTPUT->value,
                'source' => InventoryCostSource::FOOD->value,
                'inventory_transaction_id' => $output->id,
                'ingredient_id' => $food->id, 'ingredient_name' => $food->name,
                'reference' => $reference,
                'quantity' => $yield, 'unit_cost' => $unitCost, 'total_cost' => $totalCost,
                'user_id' => Auth::id(), 'recognized_at' => now(),
            ]);

            foreach ($consumed as $line) {
                $line['transaction']?->update(['reference' => $reference]);

                InventoryCostEntry::create([
                    'kind' => InventoryCostKind::PRODUCTION_INPUT->value,
                    'source' => InventoryCostSource::INGREDIENT->value,
                    'inventory_transaction_id' => $line['transaction']?->id,
                    'ingredient_id' => $line['ingredient']->id,
                    'ingredient_name' => $line['ingredient']->name,
                    'reference' => $reference,
                    'quantity' => -$line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'total_cost' => -$line['total_cost'],
                    'user_id' => Auth::id(), 'recognized_at' => now(),
                ]);
            }

            return $output->fresh();
        }, 3);
    }

    /**
     * Take back a run that should not have happened. The food goes out again and the
     * components come back at the cost they left at, so the pair still nets to zero.
     * Food already sold or eaten cannot be unmade.
     */
    public function undo(InventoryTransaction $output): void
    {
        DB::transaction(function () use ($output) {
            $output = InventoryTransaction::whereKey($output->id)->lockForUpdate()->firstOrFail();
            $reference = $output->reference;
            abort_unless($reference && str_starts_with($reference, 'production_'), 422, 'That movement is not a production run.');

            $entries = InventoryCostEntry::where('reference', $reference)
                ->whereIn('kind', [InventoryCostKind::PRODUCTION_OUTPUT->value, InventoryCostKind::PRODUCTION_INPUT->value])
                ->orderBy('id')->lockForUpdate()->get();

            abort_if($entries->isEmpty(), 422, 'This production run has no ledger entries.');
            abort_if(
                InventoryCostEntry::whereIn('reversal_of_id', $entries->pluck('id'))->exists(),
                422,
                'This production run has already been undone.'
            );

            $inventory = app(InventoryService::class);

            foreach ($entries as $entry) {
                $item = Ingredient::withTrashed()->whereKey($entry->ingredient_id)->lockForUpdate()->firstOrFail();
                $quantity = abs((float) $entry->quantity);
                $isOutput = $entry->kind === InventoryCostKind::PRODUCTION_OUTPUT;

                if ($isOutput) {
                    abort_if(
                        (float) $item->current_quantity < $quantity,
                        422,
                        'Only '.rtrim(rtrim(number_format((float) $item->current_quantity, 3, '.', ''), '0'), '.')
                            .' '.$item->unit.' of '.$item->name.' is left, so this batch has already been used.'
                    );
                }

                $remaining = round((float) $item->current_quantity - $quantity, 3);
                $remainingValue = (float) $item->current_quantity * (float) $item->cost_per_unit - (float) $entry->total_cost;
                abort_if($isOutput && $remaining > 0 && $remainingValue < -0.01, 422, 'This batch value has already been used. Record a count instead.');

                // Reverse original movements, not today's tracking preference. Returned
                // ingredients enter at their original cost; remove the output's value
                // from the blended Food cost rather than leaving its average behind.
                $tx = $entry->inventory_transaction_id !== null
                    ? $inventory->recordTransaction(
                        $item, $quantity,
                        $isOutput ? InventoryTransactionType::STOCK_OUT : InventoryTransactionType::STOCK_IN,
                        $reference.'_undo', 'Undo production run', recordCost: false,
                        unitCost: $isOutput ? null : (float) $entry->unit_cost,
                    )
                    : null;

                if ($isOutput && $tx && $remaining > 0) {
                    $item->update(['cost_per_unit' => max(0, round($remainingValue / $remaining, 4))]);
                }

                InventoryCostEntry::create([
                    'kind' => $entry->kind->value, 'source' => $entry->source->value,
                    'inventory_transaction_id' => $tx?->id,
                    'ingredient_id' => $entry->ingredient_id, 'ingredient_name' => $entry->ingredient_name,
                    'reference' => $reference.'_undo',
                    'quantity' => -(float) $entry->quantity,
                    'unit_cost' => $entry->unit_cost,
                    'total_cost' => -(float) $entry->total_cost,
                    'reversal_of_id' => $entry->id,
                    'user_id' => Auth::id(), 'recognized_at' => now(),
                ]);
            }
        }, 3);
    }
}
