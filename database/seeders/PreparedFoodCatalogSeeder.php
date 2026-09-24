<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Explicit, one-time replacement; never part of the demo DatabaseSeeder. */
class PreparedFoodCatalogSeeder extends Seeder
{
    public const KEY = 'prepared-food-catalog-2026-09-24';

    private array $inventoryBefore = [];

    public function run(): void
    {
        $products = $this->csv('products_grouped.csv');
        $links = $this->csv('product_food_bom.csv');
        $components = $this->csv('food_ingredient_bom_corrected.csv');
        $legacy = collect($this->csv('legacy_prepared_stock_transition.csv'))->keyBy('Food ID');

        DB::transaction(function () use ($products, $links, $components, $legacy) {
            if (DB::table('inventory_catalog_imports')->where('key', self::KEY)->exists()) {
                $this->command?->info('Catalog already imported; current stock and later edits were left intact.');

                return;
            }
            // The unique key also prevents two concurrent imports from applying twice.
            $this->inventoryBefore = Ingredient::withTrashed()->lockForUpdate()->get()->keyBy('id')->toArray();
            $productsBefore = Product::lockForUpdate()->get()->keyBy('id');
            DB::table('inventory_catalog_imports')->insert([
                'key' => self::KEY,
                'before_snapshot' => json_encode([
                    'ingredients' => array_values($this->inventoryBefore),
                    'products' => $productsBefore->values()->toArray(),
                    'recipes' => Recipe::lockForUpdate()->get()->toArray(),
                ], JSON_THROW_ON_ERROR),
                'summary' => '{}', 'applied_at' => now(),
            ]);

            $foodNames = [];
            foreach ($components as $row) {
                $foodNames[$row['Food ID']] = $row['Food'];
            }
            $foods = [];
            foreach ($foodNames as $code => $name) {
                $transition = $legacy->get($code);
                $item = $transition ? $this->existing((int) $transition['Legacy inventory ID'], $transition['Legacy prepared item']) : null;
                $item ??= Ingredient::withTrashed()->where('name', $name)->first();
                $foods[$code] = $this->stock($item, $name, 'food', $transition['Unit'] ?? 'pcs', (float) ($transition['Current quantity'] ?? 0));
                $foods[$code]->components()->delete();
            }

            $raw = [];
            foreach ($components as $row) {
                $name = $row['Raw ingredient'];
                $rawKey = mb_strtolower($name);
                if (! isset($raw[$rawKey])) {
                    // Some source IDs refer to prepared stock now converted above.
                    // Never make that same row its own raw component.
                    $legacyId = (int) $row['Inventory ID'];
                    $preparedIds = collect($foods)->pluck('id')->all();
                    $item = $legacyId && ! in_array($legacyId, $preparedIds, true)
                        ? $this->existing($legacyId, $row['Inventory item']) : null;
                    if (in_array(mb_strtolower($name), array_map('mb_strtolower', array_values($foodNames)), true)) {
                        $name .= ' (raw)';
                    }
                    $item ??= Ingredient::withTrashed()->where('name', $name)->whereNotIn('id', $preparedIds)->first();
                    $unit = $row['Unit'] ?: ($item?->unit ?? 'unconfirmed');
                    $raw[$rawKey] = $this->stock($item, $name, 'ingredient', $unit, 0);
                }
                Recipe::create([
                    'food_id' => $foods[$row['Food ID']]->id,
                    'ingredient_id' => $raw[$rawKey]->id,
                    // Zero is explicitly incomplete, never a free component.
                    'quantity' => $row['Quantity per food'] === '' ? 0 : (float) $row['Quantity per food'],
                    'unit' => $raw[$rawKey]->unit,
                ]);
            }

            $keep = collect($foods)->merge(array_values($raw))->pluck('id')->all();
            Ingredient::whereNotIn('id', $keep)->update(['is_active' => false]);
            Ingredient::whereNotIn('id', $keep)->delete();

            $mappedProducts = [];
            foreach ($products as $row) {
                $category = Category::firstOrCreate(['name' => $row['Category']], ['slug' => Str::slug($row['Category']), 'is_active' => true]);
                $product = $productsBefore->get((int) $row['Product ID']);
                if ($product && $product->name !== $row['Product']) {
                    throw new RuntimeException('Product ID '.$row['Product ID'].' belongs to '.$product->name.'; resolve this mismatch before importing.');
                }
                $product ??= Product::where('name', $row['Product'])->first();
                $product ??= new Product(['sku' => 'MENU-'.$row['Product ID']]);
                $product->fill(['name' => $row['Product'], 'category_id' => $category->id, 'price' => $row['Selling Price'], 'is_active' => (bool) (int) $row['Active']])->save();
                $product->recipes()->delete();
                $mappedProducts[$row['Product ID']] = $product;
            }
            foreach ($links as $row) {
                if ($row['Food ID'] === '') {
                    continue; // Accessories stay unlinked, as requested: Food only.
                }
                if (! isset($foods[$row['Food ID']], $mappedProducts[$row['Product ID']])) {
                    throw new RuntimeException('Unknown product or Food in product_food_bom.csv.');
                }
                Recipe::create([
                    'product_id' => $mappedProducts[$row['Product ID']]->id,
                    'ingredient_id' => $foods[$row['Food ID']]->id,
                    'quantity' => $row['Food Qty'] === '' ? 0 : (float) $row['Food Qty'],
                    'unit' => $row['Food Unit'] ?: $foods[$row['Food ID']]->unit,
                ]);
            }
            // Retain other products and their history, but do not leave them selling
            // against the replaced inventory catalog.
            Product::whereNotIn('id', collect($mappedProducts)->pluck('id'))->update(['is_active' => false]);

            $summary = ['foods' => count($foods), 'raw_ingredients' => count($raw), 'products' => count($mappedProducts), 'incomplete_recipe_rows' => Recipe::where('quantity', '<=', 0)->count()];
            DB::table('inventory_catalog_imports')->where('key', self::KEY)->update(['summary' => json_encode($summary, JSON_THROW_ON_ERROR)]);
            $this->command?->info(json_encode($summary, JSON_THROW_ON_ERROR));
            $this->command?->warn('Complete zero-quantity recipes and unconfirmed units before preparation or sale. No cash or COGS entries were created.');
        });
    }

    private function existing(int $id, string $expectedName): ?Ingredient
    {
        $item = isset($this->inventoryBefore[$id]) ? Ingredient::withTrashed()->find($id) : null;
        if ($item && $item->name !== $expectedName) {
            throw new RuntimeException('Inventory ID '.$id.' belongs to '.$item->name.', expected '.$expectedName.'. Import aborted without changes.');
        }

        return $item;
    }

    private function stock(?Ingredient $item, string $name, string $type, string $unit, float $quantity): Ingredient
    {
        $item ??= new Ingredient(['cost_per_unit' => 0, 'current_quantity' => 0, 'min_quantity' => 0]);
        $oldQuantity = (float) $item->current_quantity;
        if ($item->exists && $item->unit !== $unit) {
            $item->cost_per_unit = 0; // A cost per kg cannot become a cost per piece.
        }
        $item->fill(['name' => $name, 'item_type' => $type, 'unit' => $unit, 'current_quantity' => $quantity, 'is_active' => true, 'track_inventory' => true]);
        $item->deleted_at = null;
        $item->save();
        if ($oldQuantity !== $quantity) {
            // Catalog reconciliation, not a purchase or an operating gain/loss.
            InventoryTransaction::create(['ingredient_id' => $item->id, 'type' => 'adjustment', 'quantity' => $quantity, 'old_quantity' => $oldQuantity, 'new_quantity' => $quantity, 'reference' => self::KEY, 'notes' => 'CSV catalog replacement; original state retained in inventory_catalog_imports.']);
        }

        return $item;
    }

    private function csv(string $name): array
    {
        $handle = fopen(database_path('seeders/data/menu_2026_09_24/'.$name), 'r');
        if (! $handle) {
            throw new RuntimeException('Cannot read '.$name);
        }
        try {
            $headers = fgetcsv($handle, escape: '');
            $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
            $rows = [];
            while (($values = fgetcsv($handle, escape: '')) !== false) {
                if (count($values) !== count($headers)) {
                    throw new RuntimeException('Malformed row in '.$name);
                }
                $rows[] = array_combine($headers, array_map('trim', $values));
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
