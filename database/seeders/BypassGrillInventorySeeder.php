<?php

namespace Database\Seeders;

use App\Models\Ingredient;
use Illuminate\Database\Seeder;

/**
 * The real Bypass Grill stock list: ingredients and supplies, with no opening
 * stock or cost. Quantities and costs are entered in Inventory afterwards, so
 * this only ever creates what is missing. Existing items keep their stock,
 * cost, unit and minimum untouched, which makes the seeder safe to re-run.
 *
 * php artisan db:seed --class=BypassGrillInventorySeeder
 */
class BypassGrillInventorySeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: string}> item type, name, unit */
    private const ITEMS = [
        ['ingredient', 'Pork Ribs', 'kg'],
        ['ingredient', 'Liempo', 'kg'],
        ['ingredient', 'CLQ', 'kg'],
        ['ingredient', 'Chicken Fillet', 'kg'],
        ['ingredient', 'Hungarian Sausage', 'pcs'],
        ['ingredient', 'Cornstarch', 'kg'],
        ['ingredient', 'Rice', 'kg'],
        ['ingredient', 'Black Pepper (Paminta)', 'g'],
        ['ingredient', 'Chili Flakes', 'g'],
        ['ingredient', 'Soy Sauce (Toyo)', 'liters'],
        ['ingredient', 'Butter', 'g'],
        ['ingredient', 'Milk', 'liters'],
        ['ingredient', 'Canton Noodles', 'pack'],
        ['ingredient', 'Bell Pepper', 'pcs'],
        ['ingredient', 'Spring Onion', 'bunch'],
        ['ingredient', 'Garlic', 'kg'],
        ['ingredient', 'Greens', 'bunch'],
        ['ingredient', 'Coke', 'bottle'],
        ['ingredient', 'Ice (Yelo)', 'bag'],
        ['supply', '150ml Cup', 'pcs'],
        ['supply', 'Paper Plate', 'pcs'],
        ['supply', 'Spoon', 'pcs'],
        ['supply', 'Fork', 'pcs'],
        ['supply', 'Aluminum Foil', 'roll'],
        ['supply', 'Plastic Bag #45', 'pcs'],
        ['supply', 'Plastic Bag #10', 'pcs'],
        ['supply', 'Small Plastic Bag', 'pcs'],
        ['supply', 'Plastic Bag', 'pcs'],
        ['supply', 'Tupperware', 'pcs'],
        ['supply', 'Stickers', 'pcs'],
        ['supply', 'Disposable Gloves', 'pcs'],
        ['supply', 'Trash Bag', 'pcs'],
        ['supply', 'Duct Tape', 'roll'],
        ['supply', 'Tape', 'roll'],
        ['supply', 'Fly Paper', 'pcs'],
        ['supply', 'Sticky Mouse Trap', 'pcs'],
    ];

    public function run(): void
    {
        $created = 0;
        $restored = 0;
        $kept = 0;

        foreach (self::ITEMS as [$type, $name, $unit]) {
            // Names are unique and items are soft-deleted, so look past the
            // deleted ones instead of failing on a name that is already taken.
            $item = Ingredient::withTrashed()->firstWhere('name', $name);

            if ($item) {
                if ($item->trashed()) {
                    $item->restore();
                    $restored++;
                } else {
                    $kept++;
                }

                continue;
            }

            Ingredient::create([
                'name' => $name,
                'item_type' => $type,
                'unit' => $unit,
                'current_quantity' => 0,
                'min_quantity' => 0,
                'cost_per_unit' => 0,
                'track_inventory' => true,
                'is_active' => true,
            ]);
            $created++;
        }

        $this->command?->info("Inventory: {$created} created, {$restored} restored, {$kept} already there.");
    }
}
