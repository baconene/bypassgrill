<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\InventoryCostEntry;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use App\Services\FoodProductionService;
use App\Services\OrderService;
use Database\Seeders\PreparedFoodCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PreparedFoodCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_import_creates_food_only_product_links_and_zero_quantity_drafts(): void
    {
        $this->seed(PreparedFoodCatalogSeeder::class);
        $this->assertSame(15, Ingredient::food()->count());
        $this->assertSame(23, Product::count());
        $this->assertSame(50, Recipe::whereNotNull('food_id')->count());
        $this->assertSame(0, Recipe::whereNotNull('food_id')->where('quantity', '>', 0)->count());
        foreach (Recipe::whereNotNull('product_id')->with('ingredient')->get() as $recipe) {
            $this->assertTrue($recipe->ingredient->isFood());
        }
        $this->assertSame(0, Product::where('name', 'Takeout Box')->firstOrFail()->recipes()->count());
        $rice = Ingredient::where('name', 'Steamed Rice')->firstOrFail();
        $rawRice = Ingredient::where('name', 'Uncooked rice')->firstOrFail();
        $this->assertNotEquals($rice->id, $rawRice->id);
        $this->assertEquals(0.040, $rice->current_quantity);
        $this->assertEquals(0, $rawRice->current_quantity);
        $this->assertSame('unconfirmed', Ingredient::where('name', 'Chicken thighs')->firstOrFail()->unit);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('inventory_cost_entries', 0);
    }

    public function test_existing_items_are_reconciled_products_updated_and_history_retained(): void
    {
        $chicken = Ingredient::forceCreate(['id' => 23, 'name' => 'Chicken Jerk', 'unit' => 'pcs', 'current_quantity' => 9, 'cost_per_unit' => 75]);
        $old = Ingredient::forceCreate(['id' => 90, 'name' => 'Old supplies', 'unit' => 'pcs', 'current_quantity' => 12]);
        $history = InventoryCostEntry::create(['kind' => 'purchase', 'source' => 'ingredient', 'ingredient_id' => $old->id, 'ingredient_name' => $old->name, 'quantity' => 12, 'unit_cost' => 1, 'total_cost' => 12, 'recognized_at' => now()]);
        $category = Category::create(['name' => 'Old category', 'slug' => 'old']);
        $product = Product::forceCreate(['id' => 35, 'category_id' => $category->id, 'name' => 'M - Chicken Jerk', 'sku' => 'ORIGINAL-SKU', 'price' => 1, 'cost' => 80, 'image' => 'keep.jpg']);
        Recipe::create(['product_id' => $product->id, 'ingredient_id' => $old->id, 'quantity' => 1, 'unit' => 'pcs']);

        $this->seed(PreparedFoodCatalogSeeder::class);

        $this->assertSoftDeleted($old);
        $this->assertDatabaseHas('inventory_cost_entries', ['id' => $history->id, 'total_cost' => 12]);
        $this->assertEquals(16, $chicken->fresh()->current_quantity);
        $this->assertEquals(75, $chicken->fresh()->cost_per_unit);
        $this->assertTrue($chicken->fresh()->isFood());
        $this->assertEquals(165, $product->fresh()->price);
        $this->assertEquals(80, $product->fresh()->cost);
        $this->assertSame('ORIGINAL-SKU', $product->fresh()->sku);
        $this->assertSame('keep.jpg', $product->fresh()->image);
        $this->assertSame(2, $product->recipes()->count());
        $this->assertDatabaseHas('inventory_transactions', ['ingredient_id' => 23, 'old_quantity' => 9, 'new_quantity' => 16]);
        $snapshot = json_decode(DB::table('inventory_catalog_imports')->value('before_snapshot'), true);
        $this->assertCount(2, $snapshot['ingredients']);
        $this->assertCount(1, $snapshot['recipes']);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_repeated_import_never_resets_stock_or_later_recipe_edits(): void
    {
        $this->seed(PreparedFoodCatalogSeeder::class);
        $food = Ingredient::where('name', 'Chicken Jerk')->firstOrFail();
        $food->update(['current_quantity' => 7]);
        $food->components()->first()->update(['quantity' => 0.25]);
        $movements = InventoryTransaction::count();
        $this->seed(PreparedFoodCatalogSeeder::class);
        $this->assertEquals(7, $food->fresh()->current_quantity);
        $this->assertEquals(0.25, $food->components()->first()->quantity);
        $this->assertSame($movements, InventoryTransaction::count());
        $this->assertDatabaseCount('inventory_catalog_imports', 1);
    }

    public function test_id_mismatch_rolls_back_the_entire_import(): void
    {
        $category = Category::create(['name' => 'Existing', 'slug' => 'existing']);
        Product::forceCreate(['id' => 32, 'category_id' => $category->id, 'name' => 'Unrelated product', 'sku' => 'OTHER', 'price' => 10]);
        try {
            $this->seed(PreparedFoodCatalogSeeder::class);
            $this->fail('An unrelated product must not be overwritten.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Product ID 32', $exception->getMessage());
        }
        $this->assertDatabaseCount('ingredients', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('inventory_catalog_imports', 0);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_incomplete_product_cannot_be_ordered(): void
    {
        $this->actingAs(User::factory()->create());
        $this->seed(PreparedFoodCatalogSeeder::class);
        $meal = Product::where('name', 'M - Chicken Jerk')->firstOrFail();
        $this->assertTrue($meal->load('recipes.ingredient')->stockStatus()['soldOut']);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Complete the recipe quantities');
        app(OrderService::class)->createOrder(['order_type' => 'dine_in', 'items' => [['product_id' => $meal->id, 'quantity' => 1]]]);
    }

    public function test_zero_quantity_components_cannot_produce_free_food(): void
    {
        $this->seed(PreparedFoodCatalogSeeder::class);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Complete all Food recipe quantities');
        app(FoodProductionService::class)->produce(Ingredient::where('name', 'Chicken Jerk')->firstOrFail(), 1);
    }

    public function test_drafts_remain_editable_and_cost_recalculation_preserves_incomplete_product_cost(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('admin', 'web');
        $user = \App\Models\User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);
        $this->seed(PreparedFoodCatalogSeeder::class);
        $food = Ingredient::where('name', 'Chicken Jerk')->firstOrFail();
        $component = $food->components()->first();
        $this->patchJson('/api/v1/inventory/'.$food->id, ['components' => [['ingredient_id' => $component->ingredient_id, 'quantity' => 0, 'unit' => $component->unit]]])->assertOk();
        $meal = Product::where('name', 'M - Chicken Jerk')->firstOrFail();
        $meal->update(['cost' => 88]);
        $this->postJson('/api/v1/products/'.$meal->id.'/calculate-cost')->assertUnprocessable();
        $this->postJson('/api/v1/products/recalculate-costs')->assertOk();
        $this->assertEquals(88, $meal->fresh()->cost);
        $this->assertEquals(0, $food->components()->first()->quantity);
    }
}
