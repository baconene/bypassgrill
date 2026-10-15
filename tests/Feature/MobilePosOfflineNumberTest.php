<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobilePosOfflineNumberTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->token = Str::random(80);
        DB::table('mobile_pos_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', $this->token), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function pos(string $method, string $uri, array $data = [])
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)->json($method, '/api/v1/mobile-pos'.$uri, $data);
    }

    public function test_number_pool_hands_out_non_overlapping_blocks_that_restart_daily(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 09:00', 'Asia/Manila'));
        $this->pos('POST', '/number-pool', ['size' => 50])->assertOk()->assertJson(['date' => '2026-10-16', 'start' => 1, 'end' => 50]);
        $this->pos('POST', '/number-pool', ['size' => 50])->assertOk()->assertJson(['start' => 51, 'end' => 100]);

        Carbon::setTestNow(Carbon::parse('2026-10-17 00:05', 'Asia/Manila'));
        $this->pos('POST', '/number-pool', ['size' => 50])->assertOk()->assertJson(['date' => '2026-10-17', 'start' => 1, 'end' => 50]);
    }

    public function test_number_pool_requires_a_mobile_token(): void
    {
        $this->postJson('/api/v1/mobile-pos/number-pool')->assertUnauthorized();
    }

    public function test_sync_keeps_the_offline_number_printed_on_the_receipt(): void
    {
        $category = Category::create(['name' => 'Grill', 'slug' => 'grill']);
        $product = Product::create(['name' => 'Takeout Box', 'category_id' => $category->id, 'price' => 5, 'is_active' => true]);

        $res = $this->pos('POST', '/sync', ['orders' => [[
            'client_id' => (string) Str::uuid(),
            'order_type' => 'dine_in',
            'offline_number' => 'O-012',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]]])->assertOk()->assertJsonPath('orders.0.status', 'synced');

        $order = Order::findOrFail($res->json('orders.0.order_id'));
        $this->assertSame('O-012', $order->offline_number);
        $this->assertSame($order->public_token, $res->json('orders.0.public_token'));
    }
}
