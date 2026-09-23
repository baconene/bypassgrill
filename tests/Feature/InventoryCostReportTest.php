<?php

namespace Tests\Feature;

use App\Enums\InventoryTransactionType;
use App\Models\Ingredient;
use App\Models\InventoryCostEntry;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryCostReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);
    }

    public function test_stock_receipts_and_counts_leave_cash_and_profit_unchanged(): void
    {
        $this->admin();
        $ingredient = Ingredient::create(['name' => 'Pork', 'unit' => 'kg', 'current_quantity' => 0, 'cost_per_unit' => 10]);
        $service = app(InventoryService::class);
        $service->recordTransaction($ingredient, 10, InventoryTransactionType::STOCK_IN, unitCost: 20);
        $service->recordTransaction($ingredient, 15, InventoryTransactionType::ADJUSTMENT);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertEquals(200, InventoryCostEntry::where('kind', 'purchase')->sum('total_cost'));
        foreach ([true, false] as $cogs) {
            $report = app(ReportService::class)->getProfitLossReport(Carbon::now()->startOfDay(), Carbon::now()->endOfDay(), $cogs);
            $this->assertEquals(0, $report['net_profit']);
        }
    }

    public function test_report_totals_cover_all_pages_and_filter_by_date_item_and_kind(): void
    {
        $this->admin();
        $ingredient = Ingredient::create(['name' => 'Pork', 'unit' => 'kg', 'cost_per_unit' => 10]);
        for ($i = 0; $i < 30; $i++) {
            InventoryCostEntry::create(['kind' => 'purchase', 'source' => 'ingredient', 'ingredient_id' => $ingredient->id, 'ingredient_name' => 'Pork', 'quantity' => 1, 'unit_cost' => 10, 'total_cost' => 10, 'recognized_at' => '2026-09-23 12:00:00']);
        }
        InventoryCostEntry::create(['kind' => 'count_gain', 'source' => 'ingredient', 'ingredient_id' => $ingredient->id, 'ingredient_name' => 'Pork', 'quantity' => -1, 'unit_cost' => 10, 'total_cost' => -10, 'recognized_at' => '2026-09-23 12:00:00']);
        InventoryCostEntry::create(['kind' => 'purchase', 'source' => 'ingredient', 'quantity' => 1, 'unit_cost' => 99, 'total_cost' => 99, 'recognized_at' => '2026-08-01 12:00:00']);
        $ingredient->delete();
        $url = '/api/v1/inventory-cost-report?start_date=2026-09-01&end_date=2026-09-30';
        $this->getJson($url)->assertOk()->assertJsonPath('purchases', 300)->assertJsonPath('losses', -10)->assertJsonPath('entries.total', 31)->assertJsonCount(25, 'entries.data');
        $this->getJson($url.'&ingredient_id='.$ingredient->id.'&kind=purchase&page=2')->assertOk()->assertJsonPath('purchases', 300)->assertJsonPath('losses', 0)->assertJsonCount(5, 'entries.data');
        $this->getJson('/api/v1/inventory-cost-report?start_date=2026-10-01&end_date=2026-10-02')->assertOk()->assertJsonPath('entries.total', 0);
        $this->getJson('/api/v1/inventory-cost-report?start_date=2026-10-01&end_date=2026-09-01')->assertStatus(422);
    }

    public function test_reports_are_restricted_to_admin_and_auditor(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/v1/inventory-cost-report?start_date=2026-09-01&end_date=2026-09-30')->assertForbidden();
    }
}
