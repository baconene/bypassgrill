<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\FinancialTransaction;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\PaymentTender;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReservationOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $platter;

    private Ingredient $pork;

    private PaymentTender $gcash;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin', 'web');
        Permission::findOrCreate('process payments', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->givePermissionTo('process payments');

        $category = Category::create(['name' => 'Platters', 'slug' => 'platters']);
        $this->platter = Product::create(['name' => 'Barkada Platter', 'category_id' => $category->id, 'price' => 1500, 'is_active' => true]);
        $this->pork = Ingredient::create(['name' => 'Pork', 'unit' => 'kg', 'current_quantity' => 10, 'min_quantity' => 0, 'cost_per_unit' => 300, 'track_inventory' => true, 'is_active' => true]);
        Recipe::create(['product_id' => $this->platter->id, 'ingredient_id' => $this->pork->id, 'quantity' => 2, 'unit' => 'kg']);
        $this->gcash = PaymentTender::create(['name' => 'GCash', 'is_active' => true]);
    }

    private function reserve(array $overrides = []): array
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/reservations', $overrides + [
            'contact_name' => 'Ana', 'phone' => '09171234567', 'reserved_at' => now()->addDays(2)->setTime(18, 0)->toDateTimeString(),
            'party_size' => 8, 'order_type' => 'dine_in',
            'items' => [['product_id' => $this->platter->id, 'quantity' => 1], ['product_id' => $this->platter->id, 'quantity' => 1]],
        ])->assertCreated()->json();
    }

    public function test_a_planned_order_creates_no_order_stock_use_or_sale_until_paid(): void
    {
        $reservation = $this->reserve();
        $this->assertCount(1, $reservation['items'], 'Same product lines are merged');
        $this->assertSame(2, $reservation['items'][0]['quantity']);
        $this->assertEquals(3000, $reservation['planned_total']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals(10, $this->pork->fresh()->current_quantity);
        $this->assertSame(0, FinancialTransaction::count());

        // Staff can still change the plan
        $this->patchJson("/api/v1/reservations/{$reservation['id']}", ['items' => [['product_id' => $this->platter->id, 'quantity' => 3]]])
            ->assertOk()->assertJsonPath('planned_total', 4500);
        $this->assertDatabaseCount('orders', 0);

        // Paid: the order and the payment are created together
        $paid = $this->postJson("/api/v1/reservations/{$reservation['id']}/checkout", ['payment_tender_id' => $this->gcash->id, 'reference' => 'GC-1'])
            ->assertCreated()->assertJsonPath('payment_status', 'paid')->json();
        $order = Order::findOrFail($paid['order_id']);
        $this->assertSame('dine_in', $order->order_type);
        $this->assertSame('Ana', $order->customer_name);
        $this->assertEquals(4500, $order->total_amount);
        $this->assertStringContainsString("Reservation #{$reservation['id']}", $order->notes);
        $this->assertEquals(4, $this->pork->fresh()->current_quantity);
        $this->assertEquals(4500, FinancialTransaction::where('type', 'payment')->sum('amount'));
        $this->assertSame('confirmed', $paid['reservation']['status']);
        $this->assertSame($order->id, $paid['reservation']['order_id']);

        // Paid once only, and the plan is locked
        $this->postJson("/api/v1/reservations/{$reservation['id']}/checkout", ['payment_tender_id' => $this->gcash->id])->assertConflict();
        $this->patchJson("/api/v1/reservations/{$reservation['id']}", ['items' => []])->assertConflict();
        $this->deleteJson("/api/v1/reservations/{$reservation['id']}")->assertConflict();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_a_failed_checkout_leaves_no_order_behind(): void
    {
        $reservation = $this->reserve(['items' => [['product_id' => $this->platter->id, 'quantity' => 6]]]); // needs 12 kg, 10 in stock
        $this->postJson("/api/v1/reservations/{$reservation['id']}/checkout", ['payment_tender_id' => $this->gcash->id])->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals(10, $this->pork->fresh()->current_quantity);
        $this->assertNull(Reservation::find($reservation['id'])->order_id);

        $empty = $this->reserve(['items' => []]);
        $this->postJson("/api/v1/reservations/{$empty['id']}/checkout", ['payment_tender_id' => $this->gcash->id])->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_type_is_always_required_and_delivery_needs_an_address(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/reservations', ['contact_name' => 'Ben', 'reserved_at' => now()->addDay()->toDateTimeString()])
            ->assertJsonValidationErrors('order_type');
        $this->postJson('/api/v1/reservations', ['contact_name' => 'Ben', 'reserved_at' => now()->addDay()->toDateTimeString(), 'order_type' => 'delivery'])
            ->assertJsonValidationErrors('customer_address');
    }

    public function test_customers_request_a_reservation_with_a_planned_order_from_the_homepage(): void
    {
        $request = [
            'contact_name' => 'Carla', 'phone' => '09181112222', 'reserved_at' => now()->addDays(3)->setTime(19, 0)->toDateTimeString(),
            'party_size' => 6, 'order_type' => 'takeout', 'items' => [['product_id' => $this->platter->id, 'quantity' => 2]],
        ];
        $this->postJson('/api/v1/reservation-requests', array_diff_key($request, ['order_type' => 1]))->assertJsonValidationErrors('order_type');
        $this->postJson('/api/v1/reservation-requests', ['reserved_at' => now()->subDay()->toDateTimeString()] + $request)->assertJsonValidationErrors('reserved_at');
        $this->postJson('/api/v1/reservation-requests', $request + ['website' => 'spam'])->assertStatus(422);

        $id = $this->postJson('/api/v1/reservation-requests', $request)->assertCreated()->assertJsonPath('planned_total', 3000)->json('reference');
        $reservation = Reservation::with('items')->findOrFail($id);
        $this->assertSame(['online', 'pending', 'takeout', null], [$reservation->source, $reservation->status, $reservation->order_type, $reservation->user_id]);
        $this->assertSame(2, $reservation->items->first()->quantity);
        $this->assertDatabaseCount('orders', 0);

        $this->platter->update(['is_active' => false]);
        $this->postJson('/api/v1/reservation-requests', $request)->assertJsonValidationErrors('items');
    }
}
