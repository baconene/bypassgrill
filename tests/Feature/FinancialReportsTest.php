<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentTender;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinancialReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private PaymentTender $cash;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 22)->setTime(12, 0));
        Role::findOrCreate('admin', 'web');
        Permission::findOrCreate('view reports', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->cash = PaymentTender::create(['name' => 'Cash']);
        $this->category = Category::create(['name' => 'Grill', 'slug' => 'grill']);
    }

    private function entry(string $type, float $amount, string $description = 'Entry', ?string $at = null): FinancialTransaction
    {
        return FinancialTransaction::create([
            'type' => $type, 'amount' => $amount, 'description' => $description,
            'payment_tender_id' => $this->cash->id, 'user_id' => $this->admin->id,
            'transacted_at' => $at ?? now(),
        ]);
    }

    /** An order with one item costing $cost, paid in full (or not) at $at. */
    private function order(float $subtotal, float $discount, float $cost, string $paymentStatus = 'paid', ?string $at = null, string $status = 'completed'): Order
    {
        $order = Order::create([
            'user_id' => $this->admin->id, 'order_type' => 'dine_in', 'status' => $status,
            'subtotal' => $subtotal, 'discount_amount' => $discount, 'tax_amount' => 0,
            'total_amount' => $subtotal - $discount, 'payment_status' => $paymentStatus,
        ]);
        $product = Product::firstOrCreate(['name' => 'Ribs'], ['category_id' => $this->category->id, 'price' => $subtotal, 'cost' => $cost, 'is_active' => true]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1,
            'unit_price' => $subtotal, 'unit_cost' => $cost, 'subtotal' => $subtotal, 'cost_subtotal' => $cost,
        ]);

        if ($paymentStatus === 'paid') {
            FinancialTransaction::create([
                'type' => 'payment', 'amount' => $subtotal - $discount, 'description' => "Payment for Order #{$order->id}",
                'order_id' => $order->id, 'payment_tender_id' => $this->cash->id, 'user_id' => $this->admin->id,
                'transacted_at' => $at ?? now(),
            ]);
        }

        return $order;
    }

    private function seedSeptember(): void
    {
        $this->order(500, 50, 200);                                  // net revenue 450, COGS 200
        $this->entry('expense', 100, 'Charcoal');
        $this->entry('expense', 300, 'Inventory Stock In: Pork');     // inventory purchases
        $this->entry('expense', 80, 'Initial stock: Rice');
        $this->entry('expense', 40, 'Inventory Adjustment: Oil');
        $this->entry('income_adjustment', 30, 'Supplier rebate');
        $this->entry('payroll', 120, 'Salary');
        $this->entry('payout_share', 10, 'Shareholder payout');
        $this->entry('asset_deduction', 25, 'Grill equipment');
    }

    private function profitLoss(array $params)
    {
        return $this->actingAs($this->admin)->getJson('/api/v1/reports/profit-loss?'.http_build_query($params))->assertOk();
    }

    public function test_profit_and_loss_with_cogs_treats_every_inventory_purchase_as_an_asset(): void
    {
        $this->seedSeptember();

        $pl = $this->profitLoss(['start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'include_cogs' => 1])->json();

        $this->assertEquals(500, $pl['revenue']['gross_sales']);
        $this->assertEquals(50, $pl['revenue']['discounts']);
        $this->assertEquals(450, $pl['revenue']['net_revenue']);
        $this->assertEquals(200, $pl['cogs']['total']);
        $this->assertEquals(250, $pl['gross_profit']);
        // Only the charcoal is operating expense; stock in, initial stock and upward
        // adjustments are purchases whose cost reaches profit through COGS.
        $this->assertEquals(100, $pl['expenses']['total']);
        $this->assertEquals(420, $pl['inventory_purchases']['total']);
        $this->assertEquals(3, $pl['inventory_purchases']['count']);
        $this->assertFalse($pl['inventory_purchases']['included_in_expenses']);
        // 250 gross + 30 other income - 100 opex - 120 payroll - 10 payouts
        $this->assertEquals(50, $pl['net_profit']);
    }

    public function test_profit_and_loss_without_cogs_expenses_inventory_purchases(): void
    {
        $this->seedSeptember();

        $pl = $this->profitLoss(['start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'include_cogs' => 0])->json();

        $this->assertEquals(450, $pl['gross_profit']);
        $this->assertEquals(520, $pl['expenses']['total']);
        $this->assertTrue($pl['inventory_purchases']['included_in_expenses']);
        // 450 + 30 - 520 - 120 - 10
        $this->assertEquals(-170, $pl['net_profit']);
    }

    public function test_profit_and_loss_only_counts_the_selected_period_and_paid_orders(): void
    {
        $this->order(1000, 0, 400, 'paid', '2026-08-31 23:30:00');       // previous month
        $this->order(200, 0, 80);                                       // this month
        $this->order(300, 0, 90, 'pending');                            // completed, unpaid
        $this->entry('expense', 60, 'August rent', '2026-08-15 10:00:00');
        $this->entry('expense', 70, 'October rent', '2026-10-01 00:00:00');

        $pl = $this->profitLoss(['start_date' => '2026-09-01', 'end_date' => '2026-09-30'])->json();

        $this->assertEquals(200, $pl['revenue']['net_revenue']);
        $this->assertEquals(1, $pl['revenue']['order_count']);
        $this->assertEquals(80, $pl['cogs']['total']);
        $this->assertEquals(0, $pl['expenses']['total']);
        $this->assertEquals(1, $pl['unpaid_completed']['count']);
        $this->assertEquals(300, $pl['unpaid_completed']['total']);
    }

    public function test_reports_require_permission(): void
    {
        Role::findOrCreate('cashier', 'web');
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        $this->actingAs($cashier)->getJson('/api/v1/reports/profit-loss')->assertForbidden();
        $this->actingAs($cashier)->getJson('/api/v1/financial-transactions/periods?start_date=2026-09-01&end_date=2026-09-22')->assertForbidden();
    }

    public function test_summary_opening_balance_plus_net_equals_closing_balance(): void
    {
        $this->entry('payment', 1000, 'August sales', '2026-08-20 10:00:00');
        $this->entry('expense', 150, 'August gas', '2026-08-25 10:00:00');
        $this->seedSeptember();
        $this->entry('payment', 999, 'Future sale', '2026-09-23 09:00:00');

        $s = $this->actingAs($this->admin)
            ->getJson('/api/v1/financial-transactions/summary?start_date=2026-09-01&end_date=2026-09-22')
            ->assertOk()->json();

        $this->assertEquals(850, $s['opening_balance']);
        // 450 + 30 in; 100 + 300 + 80 + 40 + 120 + 10 + 25 out
        $this->assertEquals(-195, $s['net']);
        $this->assertEquals(655, $s['balance_as_of_end']);
        $this->assertEquals($s['balance_as_of_end'], round($s['opening_balance'] + $s['net'], 2));

        $excluded = $this->actingAs($this->admin)
            ->getJson('/api/v1/financial-transactions/summary?start_date=2026-09-01&end_date=2026-09-22&include_asset_deductions=0')
            ->json();
        $this->assertEquals(-170, $excluded['net']);
        $this->assertEquals($excluded['balance_as_of_end'], round($excluded['opening_balance'] + $excluded['net'], 2));
    }

    public function test_periods_carry_the_balance_forward_by_calendar_month(): void
    {
        $this->entry('payment', 500, 'June', '2026-06-10 10:00:00');
        $this->entry('payment', 700, 'July', '2026-07-10 10:00:00');
        $this->entry('expense', 200, 'July', '2026-07-11 10:00:00');
        $this->entry('payment', 400, 'August', '2026-08-31 23:59:00');
        $this->entry('payment', 300, 'September', '2026-09-01 00:00:00');
        $this->entry('expense', 50, 'September', '2026-09-22 20:00:00');

        $res = $this->actingAs($this->admin)
            ->getJson('/api/v1/financial-transactions/periods?start_date=2026-09-01&end_date=2026-09-22&count=3')
            ->assertOk()->json();

        $this->assertSame('month', $res['granularity']);
        $rows = $res['rows'];
        $this->assertCount(3, $rows);
        $this->assertSame(['2026-07-01', '2026-07-31'], [$rows[0]['start'], $rows[0]['end']]);
        $this->assertSame(['2026-09-01', '2026-09-22'], [$rows[2]['start'], $rows[2]['end']]);
        $this->assertTrue($rows[2]['is_current']);

        $this->assertEquals(500, $rows[0]['opening']);
        $this->assertEquals(500, $rows[0]['net']);
        $this->assertEquals(1000, $rows[1]['opening']);
        $this->assertEquals(400, $rows[1]['money_in']);
        $this->assertEquals(1400, $rows[2]['opening']);
        $this->assertEquals(250, $rows[2]['net']);
        $this->assertEquals(1650, $rows[2]['closing']);

        foreach (array_slice($rows, 1, null, true) as $i => $row) {
            $this->assertEquals($rows[$i - 1]['closing'], $row['opening'], "Row {$i} should open at the previous closing");
        }

        $summary = $this->actingAs($this->admin)
            ->getJson('/api/v1/financial-transactions/summary?start_date=2026-09-01&end_date=2026-09-22')->json();
        $this->assertEquals($summary['balance_as_of_end'], $rows[2]['closing']);
        $this->assertEquals($summary['opening_balance'], $rows[2]['opening']);
    }

    public function test_periods_step_by_the_range_length_for_other_ranges(): void
    {
        $this->entry('payment', 100, 'Two weeks ago', '2026-09-10 10:00:00');
        $this->entry('payment', 40, 'Last week', '2026-09-16 10:00:00');

        $week = $this->actingAs($this->admin)
            ->getJson('/api/v1/financial-transactions/periods?start_date=2026-09-16&end_date=2026-09-22&count=2')
            ->json();
        $this->assertSame('week', $week['granularity']);
        $this->assertSame(['2026-09-09', '2026-09-15'], [$week['rows'][0]['start'], $week['rows'][0]['end']]);
        $this->assertEquals(100, $week['rows'][0]['closing']);
        $this->assertEquals(140, $week['rows'][1]['closing']);

        $day = $this->actingAs($this->admin)
            ->getJson('/api/v1/financial-transactions/periods?start_date=2026-09-16&end_date=2026-09-16&count=2')
            ->json();
        $this->assertSame('day', $day['granularity']);
        $this->assertSame('2026-09-15', $day['rows'][0]['start']);

        // A custom range inside one month steps by its own length, not by months.
        $custom = $this->actingAs($this->admin)
            ->getJson('/api/v1/financial-transactions/periods?start_date=2026-09-01&end_date=2026-09-05&count=2')
            ->json();
        $this->assertSame('period', $custom['granularity']);
        $this->assertSame(['2026-08-27', '2026-08-31'], [$custom['rows'][0]['start'], $custom['rows'][0]['end']]);
    }

    public function test_daily_chart_keeps_inventory_purchases_out_of_expenses(): void
    {
        $this->seedSeptember();

        $days = $this->actingAs($this->admin)->getJson('/api/v1/reports/daily-chart?days=1')->assertOk()->json();

        $this->assertEquals(480, $days[0]['income']);     // 450 payment + 30 income adjustment
        $this->assertEquals(220, $days[0]['expense']);    // 100 charcoal + 120 payroll
    }

    public function test_refund_reverses_the_payment_in_revenue_and_balance(): void
    {
        $order = $this->order(400, 0, 150);
        $payment = Payment::create([
            'order_id' => $order->id, 'user_id' => $this->admin->id, 'amount' => 400,
            'method' => 'Cash', 'payment_tender_id' => $this->cash->id, 'status' => 'completed',
        ]);
        FinancialTransaction::where('order_id', $order->id)->update(['payment_id' => $payment->id]);

        $this->actingAs($this->admin);
        app(PaymentService::class)->refundPayment($payment, ['amount' => 400, 'reason' => 'Wrong order']);

        $pl = $this->profitLoss(['start_date' => '2026-09-01', 'end_date' => '2026-09-30'])->json();
        $this->assertEquals(0, $pl['revenue']['net_revenue']);

        $summary = $this->getJson('/api/v1/financial-transactions/summary?start_date=2026-09-01&end_date=2026-09-30')->json();
        $this->assertEquals(0, $summary['balance_as_of_end']);
        $this->assertEquals(0, $summary['payments']['total']);

        $refundEntry = FinancialTransaction::where('order_id', $order->id)->where('amount', '<', 0)->first();
        $this->assertNotNull($refundEntry);
        $this->assertSame($this->cash->id, $refundEntry->payment_tender_id);
    }
}
