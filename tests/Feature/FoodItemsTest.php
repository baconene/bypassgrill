<?php

namespace Tests\Feature;

use App\Enums\InventoryTransactionType as Movement;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\InventoryCostEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use App\Services\FoodProductionService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FoodItemsTest extends TestCase
{
    use RefreshDatabase;

    private Ingredient $pork;

    private Ingredient $chop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin', 'web');
        Permission::findOrCreate('update orders', 'web');
        Permission::findOrCreate('manage inventory', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo(['update orders', 'manage inventory']);
        $this->actingAs($user);

        // The worked example from FOOD_ITEMS_PLAN.md.
        $this->pork = Ingredient::create([
            'name' => 'Pork', 'item_type' => 'ingredient', 'unit' => 'kg',
            'current_quantity' => 5, 'min_quantity' => 0, 'cost_per_unit' => 280,
            'track_inventory' => true, 'is_active' => true,
        ]);
        $this->chop = Ingredient::create([
            'name' => 'Pork Chop', 'item_type' => 'food', 'unit' => 'piece',
            'current_quantity' => 0, 'min_quantity' => 0, 'cost_per_unit' => 0,
            'track_inventory' => true, 'is_active' => true,
        ]);
        Recipe::create(['food_id' => $this->chop->id, 'ingredient_id' => $this->pork->id, 'quantity' => 0.25, 'unit' => 'kg']);

        $category = Category::create(['name' => 'Grill', 'slug' => 'grill']);
        $this->product = Product::create([
            'name' => 'Pork Chop', 'category_id' => $category->id,
            'price' => 180, 'cost' => 0, 'is_active' => true,
        ]);
        Recipe::create(['product_id' => $this->product->id, 'ingredient_id' => $this->chop->id, 'quantity' => 1, 'unit' => 'piece']);

        DB::table('cogs_ledger_settings')->update(['cogs_ledger_start_at' => Carbon::today()->subDay()]);
    }

    private function produce(float $batch, ?float $yield = null)
    {
        return app(FoodProductionService::class)->produce($this->chop, $batch, $yield);
    }

    /** Tomorrow's delivery: 5kg of pork again, at a new price. */
    private function restock(float $costPerUnit): void
    {
        // Through a fresh instance, or the stale one's unchanged quantity is not
        // dirty and Laravel leaves it out of the update.
        $this->pork->fresh()->update(['current_quantity' => 5, 'cost_per_unit' => $costPerUnit]);
    }

    private function sell(int $quantity = 1): Order
    {
        $order = app(OrderService::class)->createOrder(['order_type' => 'dine_in', 'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]]]);
        app(InventoryService::class)->deductForOrder($order->items()->first());
        $order->update(['payment_status' => 'paid', 'status' => 'completed']);
        DB::table('financial_transactions')->insert([
            'type' => 'payment', 'amount' => 180 * $quantity, 'order_id' => $order->id,
            'user_id' => auth()->id(), 'description' => 'Payment', 'transacted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $order;
    }

    private function pl(): array
    {
        return app(ReportService::class)->getProfitLossReport(Carbon::today()->startOfDay(), Carbon::today()->endOfDay());
    }

    public function test_the_worked_example_end_to_end(): void
    {
        $profitBefore = $this->pl()['net_profit'];

        $this->produce(20);

        // 5kg of pork becomes 20 chops at 70.00 each.
        $this->assertEquals(0, $this->pork->fresh()->current_quantity);
        $this->assertEquals(20, $this->chop->fresh()->current_quantity);
        $this->assertEquals(70, $this->chop->fresh()->cost_per_unit);

        // Making food is a transfer between assets. Profit does not move.
        $this->assertEquals($profitBefore, $this->pl()['net_profit'], 'Producing food must not touch profit.');

        $this->sell();

        $report = $this->pl();
        $this->assertEquals(19, $this->chop->fresh()->current_quantity);
        $this->assertEquals(70, $report['cogs']['total'], 'COGS is the chop, not the pork.');
        $this->assertEquals(180, $report['revenue']['net_revenue']);
        $this->assertEquals(110, $report['gross_profit']);
    }

    public function test_production_consumes_exactly_the_recipe_times_the_batch(): void
    {
        $this->produce(8);

        $this->assertEquals(3, $this->pork->fresh()->current_quantity, '5kg less 8 x 0.25kg.');
    }

    public function test_the_two_legs_of_a_run_net_to_zero(): void
    {
        $this->produce(20);

        $this->assertEquals(0, InventoryCostEntry::whereIn('kind', ['production_input', 'production_output'])->sum('total_cost'));
    }

    public function test_short_yield_raises_the_cost_of_each_unit(): void
    {
        // 1,400.00 of pork spread over 18 chops instead of 20.
        $this->produce(20, 18);

        $this->assertEquals(18, $this->chop->fresh()->current_quantity);
        $this->assertEquals(77.7778, $this->chop->fresh()->cost_per_unit);
    }

    public function test_leftovers_carry_over_and_blend_with_the_next_batch(): void
    {
        $this->produce(20);                      // 20 chops at 70.00
        // A count sets the quantity rather than subtracting: 5 left at close.
        app(InventoryService::class)->recordTransaction($this->chop, 5, Movement::ADJUSTMENT, notes: 'Sold through');
        $this->assertEquals(70, $this->chop->fresh()->cost_per_unit);

        // Pork costs more tomorrow: 5kg at 296.00 makes 20 more chops at 74.00.
        $this->restock(296);
        $this->produce(20);

        $this->assertEquals(25, $this->chop->fresh()->current_quantity);
        $this->assertEquals(73.2, $this->chop->fresh()->cost_per_unit, '5 at 70.00 plus 20 at 74.00.');
    }

    public function test_cogs_uses_the_blended_cost_after_a_carry_over(): void
    {
        $this->produce(20);
        app(InventoryService::class)->recordTransaction($this->chop, 5, Movement::ADJUSTMENT, notes: 'Sold through');
        $this->restock(296);
        $this->produce(20);

        $before = $this->pl()['cogs']['total'];
        $this->sell();

        $this->assertEquals(round($before + 73.2, 2), round($this->pl()['cogs']['total'], 2));
    }

    public function test_a_batch_beyond_component_stock_is_refused_and_names_it(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Not enough Pork');
        $this->produce(21);
    }

    public function test_selling_writes_one_entry_against_the_food(): void
    {
        $this->produce(20);
        $this->sell();

        $entries = InventoryCostEntry::where('kind', 'consumption')->get();
        $this->assertCount(1, $entries);
        $this->assertSame('Pork Chop', $entries[0]->ingredient_name);
        $this->assertSame('food', $entries[0]->source->value);
        $this->assertEquals(70, $entries[0]->total_cost);
    }

    public function test_cancelling_reverses_at_the_original_cost(): void
    {
        $this->produce(20);
        $order = $this->sell();

        // A later batch moves the average; the reversal must not follow it.
        $this->restock(400);
        $this->produce(20);

        app(InventoryService::class)->restoreOrderStock($order->fresh(), 'cancel');

        $this->assertEquals(0, InventoryCostEntry::whereIn('kind', ['consumption', 'consumption_reversal'])->sum('total_cost'));
        $this->assertEquals(40, $this->chop->fresh()->current_quantity);
        $this->assertEqualsWithDelta(85, $this->chop->fresh()->cost_per_unit, 0.0001);
        app(InventoryService::class)->restoreOrderStock($order->fresh(), 'delete');
        $this->assertEquals(40, $this->chop->fresh()->current_quantity, 'A repeated restore must not return stock twice.');
    }

    public function test_edit_restores_food_even_after_tracking_is_disabled(): void
    {
        $this->produce(20);
        $order = $this->sell(2);
        $this->chop->update(['track_inventory' => false]);
        app(InventoryService::class)->restoreOrderStock($order, 'edit');
        $this->assertEquals(20, $this->chop->fresh()->current_quantity);
    }

    public function test_untracked_food_is_not_added_to_stock_on_cancel(): void
    {
        $this->chop->update(['track_inventory' => false, 'cost_per_unit' => 70]);
        $order = $this->sell();
        $this->chop->update(['track_inventory' => true]);
        app(InventoryService::class)->restoreOrderStock($order, 'cancel');
        $this->assertEquals(0, $this->chop->fresh()->current_quantity);
    }

    public function test_undo_unwinds_food_average_and_returns_raw_stock_at_original_cost(): void
    {
        $this->produce(20);
        $this->restock(400);
        $output = $this->produce(20);
        $this->restock(500);

        app(FoodProductionService::class)->undo($output);

        $this->assertEquals(20, $this->chop->fresh()->current_quantity);
        $this->assertEquals(70, $this->chop->fresh()->cost_per_unit);
        $this->assertEquals(10, $this->pork->fresh()->current_quantity);
        $this->assertEquals(450, $this->pork->fresh()->cost_per_unit);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_verifier_checks_production_transfers_and_their_reversals(): void
    {
        $output = $this->produce(20);
        app(FoodProductionService::class)->undo($output);
        $this->artisan('cogs:verify')->expectsOutputToContain('Unbalanced production runs: 0')->assertSuccessful();

        InventoryCostEntry::where('reference', $output->reference)->where('kind', 'production_input')->update(['total_cost' => -1300]);
        $this->artisan('cogs:verify')->expectsOutputToContain('Unbalanced production runs: 1')->assertFailed();
    }

    public function test_inventory_page_exposes_recipes_and_undo_for_production_outputs_only(): void
    {
        Permission::findOrCreate('view inventory', 'web');
        auth()->user()->givePermissionTo('view inventory');
        $output = $this->produce(20);
        $this->get('/inventory')->assertOk()->assertInertia(fn ($page) => $page
            ->component('InventoryManagement')
            ->where('ingredients', fn ($items) => collect($items)->firstWhere('id', $this->chop->id)['components'][0]['ingredient_id'] === $this->pork->id)
            ->where('recentTransactions', function ($transactions) use ($output) {
                $rows = collect($transactions);

                return $rows->firstWhere('id', $output->id)['undo_production'] === true
                    && $rows->where('can_undo', true)->count() === 1;
            }));
    }

    public function test_the_product_is_sold_out_when_the_food_runs_out(): void
    {
        $this->product->load('recipes.ingredient');
        $this->assertTrue($this->product->stockStatus()['soldOut'], 'No chops made yet.');

        $this->produce(20);

        $this->assertTrue($this->product->fresh()->load('recipes.ingredient')->stockStatus()['soldOut'] === false);
    }

    public function test_pork_in_stock_does_not_make_the_product_available(): void
    {
        // Pork is in stock, chops are not. The POS follows the chops.
        $this->assertEquals(5, $this->pork->fresh()->current_quantity);
        $this->assertTrue($this->product->load('recipes.ingredient')->stockStatus()['soldOut']);
    }

    public function test_selling_more_chops_than_exist_is_refused(): void
    {
        $this->produce(2);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Not enough Pork Chop');
        $this->sell(3);
    }

    public function test_a_food_without_components_cannot_be_produced(): void
    {
        $this->chop->components()->delete();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Give Pork Chop its ingredients');
        $this->produce(5);
    }

    public function test_only_a_food_can_be_produced(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Only a Food item can be produced.');
        app(FoodProductionService::class)->produce($this->pork, 1);
    }

    public function test_undoing_a_run_restores_the_pork_and_removes_the_chops(): void
    {
        $output = $this->produce(20);

        app(FoodProductionService::class)->undo($output);

        $this->assertEquals(5, $this->pork->fresh()->current_quantity);
        $this->assertEquals(0, $this->chop->fresh()->current_quantity);
        $this->assertEquals(0, InventoryCostEntry::whereIn('kind', ['production_input', 'production_output'])->sum('total_cost'));
    }

    public function test_a_run_cannot_be_undone_twice(): void
    {
        $output = $this->produce(20);
        app(FoodProductionService::class)->undo($output);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('already been undone');
        app(FoodProductionService::class)->undo($output);
    }

    public function test_a_run_cannot_be_undone_once_the_food_is_sold(): void
    {
        $output = $this->produce(2);
        $this->sell(2);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('already been used');
        app(FoodProductionService::class)->undo($output);
    }

    public function test_production_never_writes_a_financial_transaction(): void
    {
        $this->produce(20);

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_a_food_cannot_be_made_from_another_food(): void
    {
        $sauce = Ingredient::create([
            'name' => 'Sauce', 'item_type' => 'food', 'unit' => 'ml',
            'current_quantity' => 0, 'min_quantity' => 0, 'cost_per_unit' => 0,
            'track_inventory' => true, 'is_active' => true,
        ]);

        $this->patchJson("/api/v1/inventory/{$this->chop->id}", [
            'components' => [['ingredient_id' => $sauce->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonFragment(['message' => 'A Food cannot be made from another Food.']);
    }

    public function test_components_are_saved_when_a_food_is_created(): void
    {
        $this->postJson('/api/v1/inventory', [
            'name' => 'Grilled Chicken', 'item_type' => 'food', 'unit' => 'piece',
            'current_quantity' => 0, 'min_quantity' => 0, 'cost_per_unit' => 0,
            'components' => [['ingredient_id' => $this->pork->id, 'quantity' => 0.3]],
        ])->assertCreated();

        $food = Ingredient::where('name', 'Grilled Chicken')->firstOrFail();
        $this->assertCount(1, $food->components);
        $this->assertEquals(84, $food->load('components.ingredient')->componentCost(), '0.3kg at 280.00.');
    }

    public function test_a_non_food_cannot_be_given_components(): void
    {
        $this->patchJson("/api/v1/inventory/{$this->pork->id}", [
            'components' => [['ingredient_id' => $this->chop->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Only a Food item can have ingredients.']);
    }

    public function test_the_produce_endpoint_requires_admin_or_auditor(): void
    {
        $plain = User::factory()->create();

        $this->actingAs($plain)
            ->postJson("/api/v1/inventory/{$this->chop->id}/produce", ['batch' => 1])
            ->assertForbidden();
    }

    public function test_the_produce_endpoint_makes_the_batch(): void
    {
        $this->postJson("/api/v1/inventory/{$this->chop->id}/produce", ['batch' => 20, 'yield' => 19])
            ->assertCreated();

        $this->assertEquals(19, $this->chop->fresh()->current_quantity);
    }

    public function test_an_ingredient_converted_to_a_food_keeps_its_stock_and_history(): void
    {
        $movementsBefore = $this->pork->transactions()->count();

        $this->patchJson("/api/v1/inventory/{$this->pork->id}", ['item_type' => 'food'])->assertOk();

        $pork = $this->pork->fresh();
        $this->assertTrue($pork->isFood());
        $this->assertEquals(5, $pork->current_quantity);
        $this->assertEquals(280, $pork->cost_per_unit);
        $this->assertSame($movementsBefore, $pork->transactions()->count());
    }
}
