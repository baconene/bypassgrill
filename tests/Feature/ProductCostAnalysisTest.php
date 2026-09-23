<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductCostAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Ingredient $pork;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin', 'web');
        Permission::findOrCreate('update orders', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo('update orders');
        $this->actingAs($user);
        $this->category = Category::create(['name' => 'Grill', 'slug' => 'grill']);
        $this->pork = Ingredient::create([
            'name' => 'Pork', 'unit' => 'kg', 'current_quantity' => 500, 'min_quantity' => 0,
            'cost_per_unit' => 20, 'track_inventory' => true, 'is_active' => true,
        ]);
    }

    private function product(string $name, float $price, float $cost, ?float $recipeQty = null): Product
    {
        $product = Product::create([
            'name' => $name, 'category_id' => $this->category->id,
            'price' => $price, 'cost' => $cost, 'is_active' => true,
        ]);

        if ($recipeQty !== null) {
            Recipe::create(['product_id' => $product->id, 'ingredient_id' => $this->pork->id, 'quantity' => $recipeQty, 'unit' => 'kg']);
        }

        return $product;
    }

    private function sell(Product $product, int $quantity): Order
    {
        $order = app(OrderService::class)->createOrder(['order_type' => 'dine_in', 'items' => [['product_id' => $product->id, 'quantity' => $quantity]]]);
        app(InventoryService::class)->deductForOrder($order->items()->first());
        $order->update(['payment_status' => 'paid', 'status' => 'completed']);
        DB::table('financial_transactions')->insert([
            'type' => 'payment', 'amount' => $product->price * $quantity, 'order_id' => $order->id,
            'user_id' => auth()->id(), 'description' => 'Payment', 'transacted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $order;
    }

    private function pl(): array
    {
        return app(ReportService::class)->getProfitLossReport(Carbon::today()->startOfDay(), Carbon::today()->endOfDay());
    }

    public function test_product_margins_show_sales_cost_and_the_difference(): void
    {
        DB::table('cogs_ledger_settings')->update(['cogs_ledger_start_at' => Carbon::today()->subDay()]);
        $ribs = $this->product('Ribs', 100, 5, 0.5);   // costs 10 per unit from the recipe
        $this->sell($ribs, 3);                          // 300 sales, 30 cost

        $margins = $this->pl()['product_margins'];

        $this->assertCount(1, $margins);
        $this->assertSame('Ribs', $margins[0]['product_name']);
        $this->assertEquals(3, $margins[0]['quantity']);
        $this->assertEquals(300, $margins[0]['sales']);
        $this->assertEquals(30, $margins[0]['cost'], 'Recipe cost, not the stale product cost of 5.');
        $this->assertEquals(270, $margins[0]['gross_profit']);
        $this->assertEquals(90, $margins[0]['margin']);
    }

    public function test_product_costs_add_up_to_the_cogs_line(): void
    {
        DB::table('cogs_ledger_settings')->update(['cogs_ledger_start_at' => Carbon::today()->subDay()]);
        $this->sell($this->product('Ribs', 100, 5, 0.5), 3);
        $this->sell($this->product('Wings', 80, 4, 0.25), 2);
        $this->sell($this->product('Softdrink', 30, 12), 4);  // no recipe, falls back on cost

        $report = $this->pl();
        $summed = array_sum(array_column($report['product_margins'], 'cost'));

        $this->assertEquals(round($report['cogs']['total'], 2), round($summed, 2));
    }

    public function test_a_product_without_a_recipe_is_costed_from_its_stored_cost(): void
    {
        DB::table('cogs_ledger_settings')->update(['cogs_ledger_start_at' => Carbon::today()->subDay()]);
        $this->sell($this->product('Softdrink', 30, 12), 4);

        $margins = $this->pl()['product_margins'];

        $this->assertEquals(120, $margins[0]['sales']);
        $this->assertEquals(48, $margins[0]['cost']);
        $this->assertEquals(72, $margins[0]['gross_profit']);
    }

    public function test_margins_are_empty_when_nothing_was_paid_for(): void
    {
        $this->assertSame([], $this->pl()['product_margins']);
    }

    public function test_the_page_reports_recipe_cost_and_the_drift_from_the_stored_cost(): void
    {
        $this->product('Ribs', 100, 5, 0.5);  // recipe is worth 10, stored cost says 5

        $products = $this->get('/products')->assertOk()->viewData('page')['props']['products'];

        $this->assertEquals(10.0, $products[0]['recipe_cost']);
        $this->assertEquals(5.0, $products[0]['cost']);
        $this->assertEquals(5.0, $products[0]['cost_drift']);
        $this->assertTrue($products[0]['has_recipe']);
    }

    public function test_a_product_without_a_recipe_reports_no_drift(): void
    {
        $this->product('Softdrink', 30, 12);

        $products = $this->get('/products')->assertOk()->viewData('page')['props']['products'];

        $this->assertFalse($products[0]['has_recipe']);
        $this->assertEquals(0.0, $products[0]['cost_drift'], 'Nothing to compare against.');
    }

    public function test_recalculating_costs_updates_only_products_with_recipes(): void
    {
        $ribs = $this->product('Ribs', 100, 5, 0.5);
        $drink = $this->product('Softdrink', 30, 12);

        $this->postJson('/api/v1/products/recalculate-costs')
            ->assertOk()
            ->assertJson(['updated' => 1, 'unchanged' => 0]);

        $this->assertEquals(10.0, (float) $ribs->fresh()->cost);
        $this->assertEquals(12.0, (float) $drink->fresh()->cost, 'A product with no recipe keeps its only cost figure.');
    }

    public function test_recalculating_twice_reports_nothing_left_to_do(): void
    {
        $this->product('Ribs', 100, 5, 0.5);

        $this->postJson('/api/v1/products/recalculate-costs')->assertOk();
        $this->postJson('/api/v1/products/recalculate-costs')
            ->assertOk()
            ->assertJson(['updated' => 0, 'unchanged' => 1]);
    }

    public function test_recalculating_costs_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/products/recalculate-costs')->assertForbidden();
    }
}
