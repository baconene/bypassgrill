<?php

namespace Tests\Feature;

use App\Enums\InventoryTransactionType as Movement;
use App\Jobs\ProcessOrderJob;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\InventoryCostEntry;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CogsLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Ingredient $ingredient;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin', 'web');
        Permission::findOrCreate('update orders', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo('update orders');
        $this->actingAs($user);
        $category = Category::create(['name' => 'Grill', 'slug' => 'grill']);
        $this->product = Product::create(['name' => 'Ribs', 'category_id' => $category->id, 'price' => 100, 'cost' => 5, 'is_active' => true]);
        $this->ingredient = Ingredient::create(['name' => 'Pork', 'unit' => 'kg', 'current_quantity' => 10, 'min_quantity' => 0, 'cost_per_unit' => 20, 'track_inventory' => true, 'is_active' => true]);
        Recipe::create(['product_id' => $this->product->id, 'ingredient_id' => $this->ingredient->id, 'quantity' => 0.5, 'unit' => 'kg']);
    }

    private function order(int $quantity = 2): Order
    {
        return app(OrderService::class)->createOrder(['order_type' => 'dine_in', 'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]]]);
    }

    public function test_recipe_consumption_is_recorded_once_without_changing_report_costs_in_shadow_mode(): void
    {
        $order = $this->order();
        $item = $order->items()->first();
        app(InventoryService::class)->deductForOrder($item);
        $this->assertEquals(9, $this->ingredient->fresh()->current_quantity);
        $this->assertEquals(20, InventoryCostEntry::where('kind', 'consumption')->sum('total_cost'));
        $this->assertEquals(10, $item->fresh()->cost_subtotal);
        $this->assertDatabaseCount('inventory_cost_entries', 1);
        $this->assertNotNull(InventoryTransaction::first()->order_item_id);
        $job = new ProcessOrderJob($order);
        $job->handle(app(InventoryService::class));
        $job->handle(app(InventoryService::class));
        $this->assertEquals(9, $this->ingredient->fresh()->current_quantity);
        $this->assertDatabaseCount('inventory_cost_entries', 1);
        $this->artisan('cogs:verify')->assertSuccessful();
    }

    public function test_cancellation_restores_original_cost_once_even_after_deletion_and_price_change(): void
    {
        $order = $this->order();
        $this->ingredient->update(['cost_per_unit' => 99]);
        $this->ingredient->delete();
        app(OrderService::class)->cancelOrder($order, 'test');
        app(OrderService::class)->cancelOrder($order, 'repeat');
        $this->assertEquals(10, Ingredient::withTrashed()->find($this->ingredient->id)->current_quantity);
        $this->assertEquals(0, InventoryCostEntry::sum('total_cost'));
        $this->assertDatabaseCount('inventory_cost_entries', 2);
        $this->assertEquals(20, InventoryCostEntry::where('kind', 'consumption_reversal')->first()->unit_cost);
        $this->assertNotNull(InventoryTransaction::first()->ingredient);
    }

    public function test_order_edit_reverses_old_items_and_consumes_new_items_atomically(): void
    {
        $order = $this->order();
        $this->putJson('/api/v1/orders/'.$order->id, ['items' => [['product_id' => $this->product->id, 'quantity' => 4]]])->assertOk();
        $this->assertEquals(8, $this->ingredient->fresh()->current_quantity);
        $this->assertEquals(40, InventoryCostEntry::where('order_id', $order->id)->sum('total_cost'));
        $this->assertEquals(20, $order->items()->sum('cost_subtotal'));
        $this->putJson('/api/v1/orders/'.$order->id, ['items' => [['product_id' => $this->product->id, 'quantity' => 100]]])->assertStatus(422);
        $this->assertEquals(8, $this->ingredient->fresh()->current_quantity);
        $this->assertEquals(4, $order->items()->first()->quantity);
        app(OrderService::class)->deleteOrder($order->fresh());
        $this->assertEquals(10, $this->ingredient->fresh()->current_quantity);
        $this->assertEquals(0, InventoryCostEntry::sum('total_cost'));
    }

    public function test_untracked_recipe_costs_and_no_recipe_fallback(): void
    {
        $this->ingredient->update(['track_inventory' => false]);
        $this->order();
        $this->assertEquals(10, $this->ingredient->fresh()->current_quantity);
        $this->assertEquals('untracked_ingredient', InventoryCostEntry::first()->source->value);
        Recipe::query()->delete();
        $this->order();
        $this->assertEquals(10, InventoryCostEntry::where('source', 'product_fallback')->sum('total_cost'));
    }

    public function test_stock_in_weighted_average_and_signed_count_and_waste_entries(): void
    {
        $service = app(InventoryService::class);
        $service->recordTransaction($this->ingredient, 10, Movement::STOCK_IN, unitCost: 40);
        $this->assertEquals(30, $this->ingredient->fresh()->cost_per_unit);
        $this->assertEquals(400, InventoryCostEntry::where('kind', 'purchase')->sum('total_cost'));
        $service->recordTransaction($this->ingredient, 2, Movement::WASTE);
        $service->recordTransaction($this->ingredient, 20, Movement::ADJUSTMENT);
        $service->recordTransaction($this->ingredient, 0, Movement::ADJUSTMENT);
        $this->assertEquals(60, InventoryCostEntry::where('kind', 'waste')->sum('total_cost'));
        $this->assertEquals(-60, InventoryCostEntry::where('kind', 'count_gain')->sum('total_cost'));
        $this->assertEquals(600, InventoryCostEntry::where('kind', 'count_loss')->sum('total_cost'));
        foreach ([0, -2] as $quantity) {
            $this->ingredient->update(['current_quantity' => $quantity]);
            $service->recordTransaction($this->ingredient, 2, Movement::STOCK_IN, unitCost: 11);
            $this->assertEquals(11, $this->ingredient->fresh()->cost_per_unit);
        }
    }

    public function test_insufficient_stock_rolls_back_every_recipe_line(): void
    {
        $other = Ingredient::create(['name' => 'Sauce', 'unit' => 'kg', 'current_quantity' => 0, 'cost_per_unit' => 2, 'track_inventory' => true]);
        Recipe::create(['product_id' => $this->product->id, 'ingredient_id' => $other->id, 'quantity' => 1, 'unit' => 'kg']);
        try {
            $this->order();
            $this->fail('Insufficient stock should reject the order.');
        } catch (HttpException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }
        $this->assertEquals(10, $this->ingredient->fresh()->current_quantity);
        $this->assertDatabaseCount('inventory_cost_entries', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_valuation_uses_quantity_times_cost(): void
    {
        $this->assertEquals(200, app(ReportService::class)->getInventoryValuation()->first()->valuation);
    }

    public function test_initial_stock_is_atomic_and_does_not_write_a_cash_entry(): void
    {
        $this->postJson('/api/v1/inventory', ['name' => 'Rice', 'unit' => 'kg', 'current_quantity' => 3, 'min_quantity' => 1, 'cost_per_unit' => 12])->assertCreated();
        $entry = InventoryCostEntry::where('kind', 'purchase')->firstOrFail();
        $this->assertEquals(36, $entry->total_cost);
        $this->assertNull($entry->financial_transaction_id);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertNotNull($entry->inventory_transaction_id);
        $this->assertEquals(3, Ingredient::where('name', 'Rice')->first()->current_quantity);
    }

    public function test_legacy_order_restores_only_once_and_can_be_edited_into_the_ledger(): void
    {
        $order = Order::create(['user_id' => auth()->id(), 'order_type' => 'dine_in', 'status' => 'pending', 'payment_status' => 'pending']);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 100, 'subtotal' => 200]);
        InventoryTransaction::create(['ingredient_id' => $this->ingredient->id, 'user_id' => auth()->id(), 'type' => 'stock_out', 'quantity' => 1, 'old_quantity' => 10, 'new_quantity' => 9, 'reference' => 'order_'.$order->id]);
        $this->ingredient->update(['current_quantity' => 9]);
        (new ProcessOrderJob($order))->handle(app(InventoryService::class));
        $this->assertEquals(9, $this->ingredient->fresh()->current_quantity);
        $this->putJson('/api/v1/orders/'.$order->id, ['items' => [['product_id' => $this->product->id, 'quantity' => 4]]])->assertOk();
        $this->assertEquals(8, $this->ingredient->fresh()->current_quantity);
        app(OrderService::class)->cancelOrder($order->fresh(), 'test');
        $this->assertEquals(10, $this->ingredient->fresh()->current_quantity);
    }

    public function test_verifier_fails_when_a_linked_stock_movement_has_no_cost_entry(): void
    {
        $this->order();
        InventoryCostEntry::query()->delete();
        $this->artisan('cogs:verify')->assertFailed();
        $this->artisan('cogs:verify --from=2026-09-30 --to=2026-09-01')->assertFailed();
    }
}
