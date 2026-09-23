<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use Database\Seeders\BypassGrillInventorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BypassGrillInventorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_stock_list_with_no_opening_stock(): void
    {
        $this->seed(BypassGrillInventorySeeder::class);

        $this->assertSame(36, Ingredient::count());
        $this->assertSame(19, Ingredient::where('item_type', 'ingredient')->count());
        $this->assertSame(17, Ingredient::where('item_type', 'supply')->count());

        $ribs = Ingredient::firstWhere('name', 'Pork Ribs');
        $this->assertSame('kg', $ribs->unit);
        $this->assertEquals(0, $ribs->current_quantity);
        $this->assertEquals(0, $ribs->min_quantity);
        $this->assertEquals(0, $ribs->cost_per_unit);
        $this->assertTrue($ribs->track_inventory);
        $this->assertTrue($ribs->is_active);

        $this->assertSame('pcs', Ingredient::firstWhere('name', 'Plastic Bag #45')->unit);
        $this->assertSame('bunch', Ingredient::firstWhere('name', 'Spring Onion')->unit);
    }

    public function test_re_running_keeps_stock_and_costs_that_were_entered(): void
    {
        $this->seed(BypassGrillInventorySeeder::class);

        Ingredient::firstWhere('name', 'Pork Ribs')->update([
            'current_quantity' => 12.5,
            'min_quantity' => 3,
            'cost_per_unit' => 280,
            'unit' => 'kilo',
        ]);

        $this->seed(BypassGrillInventorySeeder::class);

        $ribs = Ingredient::firstWhere('name', 'Pork Ribs');
        $this->assertSame(36, Ingredient::count());
        $this->assertEquals(12.5, $ribs->current_quantity);
        $this->assertEquals(3, $ribs->min_quantity);
        $this->assertEquals(280, $ribs->cost_per_unit);
        $this->assertSame('kilo', $ribs->unit);
    }

    public function test_it_restores_an_item_that_was_deleted(): void
    {
        $this->seed(BypassGrillInventorySeeder::class);
        Ingredient::firstWhere('name', 'Tupperware')->delete();

        $this->seed(BypassGrillInventorySeeder::class);

        $this->assertSame(36, Ingredient::count());
        $this->assertNotNull(Ingredient::firstWhere('name', 'Tupperware'));
    }
}
