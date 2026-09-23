<?php

namespace Tests\Feature;

use App\Enums\InventoryTransactionType as Movement;
use App\Models\Ingredient;
use App\Models\InventoryCostEntry;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UndoStockInTest extends TestCase
{
    use RefreshDatabase;

    private Ingredient $ingredient;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin', 'web');
        Permission::findOrCreate('manage inventory', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo('manage inventory');
        $this->actingAs($user);
        $this->ingredient = Ingredient::create([
            'name' => 'Pork', 'unit' => 'kg', 'current_quantity' => 10, 'min_quantity' => 0,
            'cost_per_unit' => 20, 'track_inventory' => true, 'is_active' => true,
        ]);
    }

    private function stockIn(float $quantity, float $unitCost)
    {
        return app(InventoryService::class)->recordTransaction(
            $this->ingredient, $quantity, Movement::STOCK_IN, null, 'Receipt', unitCost: $unitCost,
        );
    }

    public function test_undo_removes_the_stock_and_restores_the_previous_average_cost(): void
    {
        // 10kg @ 20 + 10kg @ 30 = 20kg @ 25
        $receipt = $this->stockIn(10, 30);
        $this->assertEquals(20, $this->ingredient->fresh()->current_quantity);
        $this->assertEquals(25, $this->ingredient->fresh()->cost_per_unit);

        app(InventoryService::class)->undoStockIn($receipt);

        $this->ingredient->refresh();
        $this->assertEquals(10, $this->ingredient->current_quantity);
        $this->assertEquals(20, $this->ingredient->cost_per_unit, 'Average cost returns to the pre-receipt value.');
    }

    public function test_undo_writes_a_signed_reversal_that_nets_the_purchase_to_zero(): void
    {
        $receipt = $this->stockIn(10, 30);
        $this->assertEquals(300, InventoryCostEntry::where('kind', 'purchase')->sum('total_cost'));

        app(InventoryService::class)->undoStockIn($receipt);

        $reversal = InventoryCostEntry::where('kind', 'purchase_reversal')->sole();
        $this->assertEquals(-300, (float) $reversal->total_cost);
        $this->assertEquals(-10, (float) $reversal->quantity);
        $this->assertEquals(0, InventoryCostEntry::whereIn('kind', ['purchase', 'purchase_reversal'])->sum('total_cost'));
    }

    public function test_a_stock_in_cannot_be_undone_twice(): void
    {
        $receipt = $this->stockIn(10, 30);
        app(InventoryService::class)->undoStockIn($receipt);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('already been undone');
        app(InventoryService::class)->undoStockIn($receipt);
    }

    public function test_undo_is_refused_once_the_stock_has_been_used(): void
    {
        $receipt = $this->stockIn(10, 30);
        app(InventoryService::class)->recordTransaction($this->ingredient, 15, Movement::STOCK_OUT, null, 'Sold');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('already been used');
        app(InventoryService::class)->undoStockIn($receipt);
    }

    public function test_undo_never_writes_a_financial_transaction(): void
    {
        $receipt = $this->stockIn(10, 30);
        app(InventoryService::class)->undoStockIn($receipt);

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_a_stock_out_cannot_be_undone(): void
    {
        $out = app(InventoryService::class)->recordTransaction($this->ingredient, 2, Movement::STOCK_OUT, null, 'Removed');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Only a Stock In can be undone.');
        app(InventoryService::class)->undoStockIn($out);
    }

    public function test_undo_endpoint_requires_admin_or_auditor(): void
    {
        $receipt = $this->stockIn(10, 30);
        $plain = User::factory()->create();
        $plain->givePermissionTo('manage inventory');

        $this->actingAs($plain)
            ->postJson("/api/v1/inventory/transactions/{$receipt->id}/undo")
            ->assertForbidden();
    }

    public function test_undo_endpoint_reverses_the_receipt(): void
    {
        $receipt = $this->stockIn(10, 30);

        $this->postJson("/api/v1/inventory/transactions/{$receipt->id}/undo")->assertCreated();

        $this->ingredient->refresh();
        $this->assertEquals(10, $this->ingredient->current_quantity);
        $this->assertEquals(20, $this->ingredient->cost_per_unit);
    }

    public function test_undoing_the_last_stock_leaves_the_cost_untouched(): void
    {
        $empty = Ingredient::create([
            'name' => 'Salt', 'unit' => 'kg', 'current_quantity' => 0, 'min_quantity' => 0,
            'cost_per_unit' => 0, 'track_inventory' => true, 'is_active' => true,
        ]);
        $receipt = app(InventoryService::class)->recordTransaction($empty, 5, Movement::STOCK_IN, null, 'Receipt', unitCost: 12);
        $this->assertEquals(12, $empty->fresh()->cost_per_unit);

        app(InventoryService::class)->undoStockIn($receipt);

        $empty->refresh();
        $this->assertEquals(0, $empty->current_quantity);
        $this->assertEquals(12, $empty->cost_per_unit, 'With no stock left there is nothing to re-average.');
    }
}
