<?php

namespace Tests\Feature;

use App\Enums\InventoryTransactionType as Movement;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\InventoryCostEntry;
use App\Models\Order;
use App\Models\OrderItem;
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

class CogsCutoverTest extends TestCase
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
        // Product.cost is deliberately stale: the ledger must not fall back to it.
        $this->product = Product::create(['name' => 'Ribs', 'category_id' => $category->id, 'price' => 100, 'cost' => 99, 'is_active' => true]);
        $this->ingredient = Ingredient::create(['name' => 'Pork', 'unit' => 'kg', 'current_quantity' => 100, 'min_quantity' => 0, 'cost_per_unit' => 20, 'track_inventory' => true, 'is_active' => true]);
        Recipe::create(['product_id' => $this->product->id, 'ingredient_id' => $this->ingredient->id, 'quantity' => 0.5, 'unit' => 'kg']);
    }

    private function cutover(?string $date): void
    {
        DB::table('cogs_ledger_settings')->update(['cogs_ledger_start_at' => $date]);
    }

    private function soldOrder(int $quantity = 2): Order
    {
        $order = app(OrderService::class)->createOrder(['order_type' => 'dine_in', 'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]]]);
        app(InventoryService::class)->deductForOrder($order->items()->first());
        $order->update(['payment_status' => 'paid', 'status' => 'completed']);
        DB::table('financial_transactions')->insert([
            'type' => 'payment', 'amount' => 100 * $quantity, 'order_id' => $order->id, 'user_id' => auth()->id(),
            'description' => 'Payment', 'transacted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $order;
    }

    private function pl(): array
    {
        return app(ReportService::class)->getProfitLossReport(Carbon::today()->startOfDay(), Carbon::today()->endOfDay());
    }

    public function test_shadow_mode_still_reports_the_recorded_item_costs(): void
    {
        $this->cutover(null);
        $order = $this->soldOrder();

        // Ledger says 2 x 0.5kg x 20 = 20; the item still carries the old product cost.
        $this->assertEquals(20, InventoryCostEntry::where('order_id', $order->id)->sum('total_cost'));
        $this->assertEquals((float) OrderItem::where('order_id', $order->id)->sum('cost_subtotal'), $this->pl()['cogs']['total']);
    }

    public function test_after_the_cutover_cogs_comes_from_the_ledger(): void
    {
        $this->cutover(Carbon::today()->subDay()->toDateTimeString());
        $order = $this->soldOrder();

        $this->assertEquals(20, $this->pl()['cogs']['total'], 'Recipe cost, not the stale product cost.');
        $this->assertNotEquals((float) OrderItem::where('order_id', $order->id)->sum('cost_subtotal'), 20.0);
    }

    public function test_a_period_spanning_the_cutover_adds_both_sides(): void
    {
        $old = $this->soldOrder();
        Order::whereKey($old->id)->update(['created_at' => Carbon::today()->subDays(5)]);
        $legacyCost = (float) OrderItem::where('order_id', $old->id)->sum('cost_subtotal');

        $this->cutover(Carbon::today()->startOfDay()->toDateTimeString());
        $this->soldOrder();

        // The old order keeps its recorded cost; the new one is costed from the ledger.
        $this->assertEquals(round($legacyCost + 20, 2), $this->pl()['cogs']['total']);
    }

    public function test_waste_is_deducted_as_an_inventory_loss(): void
    {
        $this->cutover(Carbon::today()->startOfDay()->toDateTimeString());
        $before = $this->pl()['net_profit'];

        app(InventoryService::class)->recordTransaction($this->ingredient, 3, Movement::WASTE, notes: 'Spoiled');

        $this->assertEquals(60, $this->pl()['inventory_losses']['total']);
        $this->assertEquals(round($before - 60, 2), round($this->pl()['net_profit'], 2));
    }

    public function test_buying_stock_leaves_net_profit_exactly_unchanged(): void
    {
        $this->cutover(Carbon::today()->startOfDay()->toDateTimeString());
        $this->soldOrder();
        $before = $this->pl()['net_profit'];

        app(InventoryService::class)->recordTransaction($this->ingredient, 50, Movement::STOCK_IN, notes: 'Delivery', unitCost: 25);

        $this->assertEquals($before, $this->pl()['net_profit'], 'Acceptance criterion 1.');
    }

    public function test_counting_stock_up_leaves_net_profit_exactly_unchanged(): void
    {
        $this->cutover(Carbon::today()->startOfDay()->toDateTimeString());
        $before = $this->pl()['net_profit'];

        app(InventoryService::class)->recordTransaction($this->ingredient, 130, Movement::ADJUSTMENT, notes: 'Count');

        $this->assertEquals(0, $this->pl()['inventory_losses']['total'], 'Finding stock is not income.');
        $this->assertEquals($before, $this->pl()['net_profit']);
    }

    public function test_counting_stock_down_is_a_loss(): void
    {
        $this->cutover(Carbon::today()->startOfDay()->toDateTimeString());
        $before = $this->pl()['net_profit'];

        app(InventoryService::class)->recordTransaction($this->ingredient, 95, Movement::ADJUSTMENT, notes: 'Count');

        $this->assertEquals(100, $this->pl()['inventory_losses']['total']);
        $this->assertEquals(round($before - 100, 2), round($this->pl()['net_profit'], 2));
    }

    public function test_cancelling_an_order_returns_its_cogs_to_zero(): void
    {
        $this->cutover(Carbon::today()->subDay()->toDateTimeString());
        $order = $this->soldOrder();
        $this->assertEquals(20, $this->pl()['cogs']['total']);

        app(InventoryService::class)->restoreOrderStock($order->fresh(), 'cancel');

        $this->assertEquals(0, $this->pl()['cogs']['total'], 'Reversal is signed, so the pair nets out.');
    }

    public function test_the_cutover_command_refuses_to_predate_the_shadow_ledger(): void
    {
        DB::table('cogs_ledger_settings')->update(['shadow_started_at' => Carbon::today()->toDateTimeString()]);

        $this->artisan('cogs:cutover', ['--at' => Carbon::today()->subDays(3)->toDateString()])
            ->assertExitCode(1);

        $this->assertNull(DB::table('cogs_ledger_settings')->value('cogs_ledger_start_at'));
    }

    public function test_the_cutover_command_can_be_undone(): void
    {
        $this->cutover(Carbon::today()->toDateTimeString());

        $this->artisan('cogs:cutover', ['--undo' => true])->assertExitCode(0);

        $this->assertNull(DB::table('cogs_ledger_settings')->value('cogs_ledger_start_at'));
    }
}
