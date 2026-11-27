<?php

namespace Tests\Feature;

use App\Models\FinancialTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesDayInsightsTest extends TestCase
{
    use RefreshDatabase;

    private function signedInAdmin(): string
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        return $this->postJson('/api/v1/mobile-pos/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertOk()->json('token');
    }

    public function test_forecast_crosses_midnight_and_reuses_the_provider_response(): void
    {
        $now = CarbonImmutable::parse('2026-11-27 22:30', 'Asia/Manila');
        $this->travelTo($now);
        Http::fake(['weather.visualcrossing.com/*' => Http::response([
            'currentConditions' => ['datetimeEpoch' => $now->timestamp, 'temp' => 27.5],
            'days' => [
                ['hours' => [['datetimeEpoch' => $now->startOfHour()->timestamp, 'temp' => 28],
                    ['datetimeEpoch' => $now->startOfHour()->addHour()->timestamp, 'temp' => 27]]],
                ['hours' => array_map(fn ($h) => [
                    'datetimeEpoch' => $now->startOfHour()->addHours($h)->timestamp, 'temp' => 26, 'precipprob' => 15,
                ], range(2, 13))],
            ],
        ])]);

        $url = '/api/v1/mobile-pos/weather';
        $this->getJson($url)->assertUnauthorized();
        $this->withToken($this->signedInAdmin())->getJson($url)->assertOk()
            ->assertJsonPath('current.temp_c', 27.5)->assertJsonCount(12, 'hours')
            ->assertJsonPath('hours.0.timestamp', $now->startOfHour()->addHour()->timestamp)
            ->assertJsonPath('hours.11.timestamp', $now->startOfHour()->addHours(12)->timestamp);
        $this->getJson($url)->assertOk();
        $this->actingAs(User::firstOrFail())->getJson('/api/v1/weather')->assertOk()
            ->assertJsonPath('current.temp_c', 27.5)->assertJsonCount(12, 'hours');
        Http::assertSentCount(1);
    }

    public function test_payday_compares_completed_hours_and_excludes_forecast_only_weather(): void
    {
        $now = CarbonImmutable::parse('2026-11-15 18:30', 'Asia/Manila');
        $this->travelTo($now);
        $token = $this->signedInAdmin();
        $user = User::firstOrFail();
        $payment = function ($time, $amount, $type = 'payment') use ($user) {
            FinancialTransaction::create([
                'type' => $type, 'amount' => $amount, 'description' => 'fixture', 'user_id' => $user->id,
                'transacted_at' => $time,
            ]);
        };
        $payment($now->setTime(15, 15), 100);
        $payment($now->setTime(16, 0), 150);
        $payment($now->setTime(17, 30), 50);
        $payment($now->setTime(18, 10), 999);
        $payment($now->setTime(17, 15), 500, 'expense');
        $payment($now->subWeeks(1)->setTime(16, 30), 100);
        $payment($now->subWeeks(1)->setTime(20, 0), 900);
        $payment($now->subWeeks(2)->setTime(17, 30), 200);
        $payment($now->subWeeks(3)->setTime(18, 5), 600);
        $payment($now->subWeeks(11)->setTime(15, 30), 999); // August 30, another scheduled payday.
        Http::fake(['weather.visualcrossing.com/*' => Http::response(['days' => [['hours' => [
            ['datetimeEpoch' => $now->setTime(15, 0)->timestamp, 'temp' => 27, 'precip' => 2, 'source' => 'obs'],
            ['datetimeEpoch' => $now->setTime(16, 0)->timestamp, 'temp' => 28, 'precip' => 0, 'source' => 'obs'],
            ['datetimeEpoch' => $now->setTime(17, 0)->timestamp, 'temp' => 28, 'precip' => 0, 'source' => 'fcst'],
        ]]]])]);

        $url = '/api/v1/mobile-pos/reports/sales-insights';
        $this->withToken($token)->getJson($url.'?date=2026-11-15')->assertOk()
            ->assertJsonPath('through', '18:00')->assertJsonPath('paid_sales', 300)
            ->assertJsonPath('payments', 3)->assertJsonPath('payday.is_payday', true)
            ->assertJsonPath('payday.baseline_days', 3)->assertJsonPath('payday.baseline_sales', 100)
            ->assertJsonPath('payday.change_pct', 200)
            ->assertJsonCount(3, 'weather.hours')->assertJsonPath('weather.wet_avg', 100)
            ->assertJsonPath('weather.dry_avg', 150)->assertJsonPath('weather.hours.2.rain_mm', null);
        $this->getJson($url.'?date=2026-11-16')->assertUnprocessable();
        $webUrl = '/api/v1/reports/sales-insights?date=2026-11-15';
        $this->actingAs($user)->getJson($webUrl)->assertOk()
            ->assertJsonPath('payday.change_pct', 200)->assertJsonPath('weather.wet_avg', 100);
        $this->actingAs(User::factory()->create())->getJson($webUrl)->assertForbidden();
    }
}
