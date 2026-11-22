<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\FinancialTransaction;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\PaymentTender;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosCheckoutIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        Permission::findOrCreate('process payments', 'web');
        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('admin');
        $this->cashier->givePermissionTo('process payments');
        $this->actingAs($this->cashier);
    }

    public function test_the_same_checkout_can_be_replayed_on_web_and_mobile_without_consuming_stock_twice(): void
    {
        $category = Category::create(['name' => 'Grill', 'slug' => 'grill']);
        $product = Product::create(['name' => 'Ribs', 'category_id' => $category->id, 'price' => 100, 'is_active' => true]);
        $pork = Ingredient::create(['name' => 'Pork', 'unit' => 'kg', 'current_quantity' => 2, 'min_quantity' => 0, 'cost_per_unit' => 20, 'track_inventory' => true, 'is_active' => true]);
        Recipe::create(['product_id' => $product->id, 'ingredient_id' => $pork->id, 'quantity' => 1, 'unit' => 'kg']);
        $payload = [
            'client_id' => 'same-checkout',
            'order_type' => 'takeout',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        $orderId = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');
        $this->postJson('/api/v1/orders', $payload)->assertOk()->assertJsonPath('id', $orderId);
        $this->withToken($this->mobileToken())->postJson('/api/v1/mobile-pos/sync', ['orders' => [$payload]])
            ->assertOk()
            ->assertJsonPath('orders.0.order_id', $orderId)
            ->assertJsonPath('orders.0.status', 'already_synced');

        $this->assertDatabaseCount('orders', 1);
        $this->assertEquals(1, $pork->fresh()->current_quantity);
        $this->assertSame(1, FinancialTransaction::where('type', 'order')->count());

        // An intentionally identical order represents a new checkout.
        $payload['client_id'] = 'another-checkout';
        $this->postJson('/api/v1/orders', $payload)->assertCreated();
        $this->assertDatabaseCount('orders', 2);
        $this->assertEquals(0, $pork->fresh()->current_quantity);
    }

    public function test_a_payment_replay_returns_the_original_payment_and_rejects_a_second_collection(): void
    {
        $payload = $this->paymentData() + ['client_id' => 'payment-once', 'amount' => 200, 'reference' => 'TEST-POS'];
        $id = $this->postJson('/api/v1/payments', $payload)->assertCreated()->json('id');

        $this->withToken($this->mobileToken())->postJson('/api/v1/mobile-pos/payments', $payload)
            ->assertOk()->assertJsonPath('payment_id', $id)->assertJsonPath('status', 'already_synced');
        $this->postJson('/api/v1/payments', array_replace($payload, ['reference' => 'different']))
            ->assertConflict();
        $this->postJson('/api/v1/payments', array_replace($payload, ['client_id' => 'second-terminal']))
            ->assertConflict();
        $this->postJson('/api/v1/payments', array_diff_key($payload, ['client_id' => true]))
            ->assertConflict();

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, FinancialTransaction::where('type', 'payment')->count());
    }

    public function test_partial_payments_can_settle_the_balance_without_overpaying(): void
    {
        $payload = $this->paymentData();
        $orderId = $payload['order_id'];
        $partial = $payload + ['client_id' => 'partial', 'amount' => 75];
        $this->postJson('/api/v1/payments', $partial)->assertCreated();
        $this->postJson('/api/v1/payments', $partial)->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'payment_status' => 'pending']);
        $this->postJson('/api/v1/payments', $payload + ['client_id' => 'other-payment', 'amount' => 125.01])
            ->assertConflict();
        $this->postJson('/api/v1/payments', $payload + ['client_id' => 'balance', 'amount' => 125])
            ->assertCreated();

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'payment_status' => 'paid']);
        $this->assertDatabaseCount('payments', 2);
        $this->assertEquals(200, FinancialTransaction::where('type', 'payment')->sum('amount'));
    }

    private function paymentData(): array
    {
        $order = Order::create([
            'user_id' => $this->cashier->id,
            'order_type' => 'takeout',
            'status' => 'pending',
            'payment_status' => 'pending',
            'subtotal' => 200,
            'total_amount' => 200,
        ]);

        return [
            'order_id' => $order->id,
            'payment_tender_id' => PaymentTender::create(['name' => 'Cash'])->id,
        ];
    }

    private function mobileToken(): string
    {
        $token = 'test-mobile-pos-token';
        DB::table('mobile_pos_tokens')->insert([
            'user_id' => $this->cashier->id,
            'token_hash' => hash('sha256', $token),
        ]);

        return $token;
    }
}
