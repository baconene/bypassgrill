<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DistributionSnapshot;
use App\Models\FinancialTransaction;
use App\Models\IncentiveRule;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTender;
use App\Models\Product;
use App\Models\ProductOwnership;
use App\Models\Shareholder;
use App\Models\User;
use App\Services\Distribution\MoneyAllocation;
use App\Services\Distribution\ProfitDistributionService;
use App\Services\Distribution\ShareDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfitSharingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);

        return $user;
    }

    public function test_small_allocations_conserve_every_cent(): void
    {
        $this->assertEquals([0.01, 0.01, 0.0], MoneyAllocation::split(0.02, [1, 1, 1]));
        foreach ([0.01, 0.02, 1.01, 12345.67] as $amount) {
            $this->assertEquals(round($amount * 100), round(array_sum(MoneyAllocation::split($amount, [33.33, 33.33, 33.34])) * 100));
        }
    }

    public function test_shareholders_and_company_balance_and_filtered_amount_is_stable(): void
    {
        $a = Shareholder::create(['name' => 'A', 'ownership_percentage' => 33.33, 'status' => 'active']);
        Shareholder::create(['name' => 'B', 'ownership_percentage' => 33.33, 'status' => 'active']);
        $service = app(ShareDistributionService::class);
        $all = $service->allocate(0.02);
        $this->assertEquals(0.02, $all['members_total'] + $all['company_amount']);
        $this->assertEquals($all['members'][0]['amount'], $service->allocate(0.02, $a->id)['members'][0]['amount']);
    }

    public function test_payout_keeps_company_cash_and_cannot_be_repeated(): void
    {
        $user = $this->admin();
        $cash = PaymentTender::create(['name' => 'Cash']);
        $member = Shareholder::create(['name' => 'Partner', 'ownership_percentage' => 60, 'status' => 'active']);
        $snapshot = DistributionSnapshot::create([
            'period_start' => '2026-09-01', 'period_end' => '2026-09-22', 'distribution_basis' => 'profit',
            'distributable_amount' => 100, 'members_amount' => 60, 'company_amount' => 40, 'created_by' => $user->id,
        ]);
        $snapshot->details()->create(['recipient_type' => 'shareholder', 'shareholder_id' => $member->id, 'recipient_name' => 'Partner', 'percentage' => 60, 'amount' => 60]);
        $snapshot->details()->create(['recipient_type' => 'company', 'recipient_name' => 'Company', 'percentage' => 40, 'amount' => 40]);
        $url = "/api/v1/distribution/snapshots/{$snapshot->id}/payout";
        $this->postJson($url, ['tender_id' => $cash->id])->assertOk();
        $this->postJson($url, ['tender_id' => $cash->id])->assertStatus(422);
        $this->assertEquals(60, FinancialTransaction::where('type', 'payout_share')->sum('amount'));
        $this->assertEquals(1, $snapshot->payoutTransactions()->count());
        $copy = $snapshot->replicate();
        $copy->paid_at = null;
        $copy->paid_by = null;
        $copy->save();
        $this->postJson("/api/v1/distribution/snapshots/{$copy->id}/payout", ['tender_id' => $cash->id])->assertStatus(422);
    }

    public function test_invalid_dates_and_filtered_snapshots_are_rejected(): void
    {
        $this->admin();
        $this->getJson('/api/v1/distribution/preview?start_date=2026-09-22&end_date=2026-09-01')->assertStatus(422);
        $this->postJson('/api/v1/distribution/snapshots', ['product_id' => 1])->assertStatus(422);
    }

    public function test_snapshot_includes_incentive_only_members_and_balances(): void
    {
        $this->admin();
        $member = Shareholder::create(['name' => 'Product owner', 'ownership_percentage' => 0, 'status' => 'active']);
        $result = [
            'can_snapshot' => true, 'range' => ['start' => '2026-09-01', 'end' => '2026-09-22'], 'basis' => 'sales',
            'metrics' => ['gross_sales' => 200, 'refunds' => 0, 'cogs' => 50],
            'financial_summary' => ['expenses' => 20, 'payroll' => 30],
            'members' => [], 'members_total' => 0, 'company_amount' => 80, 'company_percentage' => 100,
            'incentive' => ['company_retained' => 5, 'by_shareholder' => [['shareholder_id' => $member->id, 'name' => $member->name, 'incentive_amount' => 15]]],
        ];
        $snapshot = app(ProfitDistributionService::class)->snapshot($result);
        $this->assertEquals(100, $snapshot->distributable_amount);
        $this->assertEquals(15, $snapshot->members_amount);
        $this->assertEquals(85, $snapshot->company_amount);
        $this->assertEquals(100, $snapshot->details->sum('amount'));
        $this->assertEquals(50, $snapshot->expenses_amount);
    }

    public function test_distribution_uses_cash_movement_and_reserves_incentives(): void
    {
        $user = $this->admin();
        $category = Category::create(['name' => 'Grill', 'slug' => 'grill']);
        $product = Product::create(['name' => 'Ribs', 'category_id' => $category->id, 'price' => 100, 'is_active' => true]);
        $member = Shareholder::create(['name' => 'Partner', 'ownership_percentage' => 50, 'status' => 'active']);
        ProductOwnership::create(['product_id' => $product->id, 'shareholder_id' => $member->id, 'ownership_percentage' => 100]);
        $order = Order::create(['user_id' => $user->id, 'order_type' => 'dine_in', 'status' => 'completed', 'payment_status' => 'paid', 'subtotal' => 100, 'discount_amount' => 10, 'tax_amount' => 0, 'total_amount' => 90]);
        $order->forceFill(['created_at' => '2026-08-31 12:00:00'])->save();
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100, 'unit_cost' => 30, 'cost_subtotal' => 30]);
        FinancialTransaction::create(['type' => 'payment', 'amount' => 90, 'order_id' => $order->id, 'user_id' => $user->id, 'description' => 'Payment', 'transacted_at' => '2026-09-01 12:00:00']);
        IncentiveRule::create(['name' => 'Pool', 'pool_type' => 'net_profit_pct', 'rate' => 10, 'distribution_method' => 'sales_contribution', 'is_active' => true, 'effective_date' => '2026-01-01']);
        $result = app(ProfitDistributionService::class)->compute('profit', '2026-09-01', '2026-09-22');
        $this->assertEquals(90, $result['base_amount']);
        $this->assertEquals(30, $result['financial_summary']['cogs']);
        $this->assertEquals(90, $result['financial_summary']['net_sales']);
        $this->assertEquals(9, $result['incentive_pool']);
        $this->assertEquals(81, $result['distributable']);
        $this->assertEquals(9, $result['incentive']['by_shareholder'][0]['incentive_amount']);
        $this->assertEquals(90, $result['members_total'] + $result['company_amount'] + $result['incentive_pool']);
        $this->assertTrue($result['can_snapshot']);
        IncentiveRule::query()->update(['rate' => 200]);
        ProfitDistributionService::bumpCacheVersion();
        $result = app(ProfitDistributionService::class)->compute('profit', '2026-09-01', '2026-09-22');
        $this->assertFalse($result['can_snapshot']);
        $this->assertTrue($result['over_budget']);
    }

    public function test_distribution_matches_financial_net_including_inventory_assets_refunds_and_payouts(): void
    {
        $user = $this->admin();
        Permission::findOrCreate('view reports', 'web');
        $user->givePermissionTo('view reports');
        foreach ([
            ['payment', 143885, 'Sales'],
            ['income_adjustment', 2899.63, 'Other income'],
            ['expense', 115953.39, 'Expenses'],
            ['payroll', 31595, 'Payroll'],
            ['payout_share', 10919.08, 'Recorded payouts'],
        ] as [$type, $amount, $description]) {
            FinancialTransaction::create(['type' => $type, 'amount' => $amount, 'description' => $description, 'user_id' => $user->id, 'transacted_at' => '2026-09-10 12:00:00']);
        }
        $service = app(ProfitDistributionService::class);
        $this->assertEquals(-11682.84, $service->compute('profit', '2026-09-01', '2026-09-22')['base_amount']);
        foreach ([['expense', 300, 'Inventory Stock In: Pork'], ['asset_deduction', 100, 'Equipment'], ['payment', -50, 'Refund']] as [$type, $amount, $description]) {
            FinancialTransaction::create(['type' => $type, 'amount' => $amount, 'description' => $description, 'user_id' => $user->id, 'transacted_at' => '2026-09-10 12:00:00']);
        }
        FinancialTransaction::create(['type' => 'income_adjustment', 'amount' => 44854.75, 'description' => 'Earlier income', 'user_id' => $user->id, 'transacted_at' => '2026-08-31 12:00:00']);
        $financial = $this->getJson('/api/v1/financial-transactions/summary?start_date=2026-09-01&end_date=2026-09-22&include_asset_deductions=1')->assertOk()->json();
        foreach (['sales', 'profit'] as $basis) {
            $result = $service->compute($basis, '2026-09-01', '2026-09-22');
            $this->assertEquals(round($financial['net'], 2), $result['base_amount']);
            $this->assertEquals(116253.39, $result['financial_summary']['expenses']);
            $this->assertEquals(100, $result['financial_summary']['asset_deductions']);
            $this->assertEquals(0, $result['distributable']);
        }
        $this->assertEquals(44854.75, $financial['opening_balance']);
    }
}
