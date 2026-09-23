<?php

namespace Tests\Feature;

use App\Models\FinancialTransaction;
use App\Models\PaymentTender;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinancialLedgerSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin', 'web');
        Permission::findOrCreate('view reports', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->givePermissionTo('view reports');
        $this->actingAs($this->admin);
    }

    private function entry(string $description, float $amount = 100, string $type = 'expense', ?int $tenderId = null): FinancialTransaction
    {
        return FinancialTransaction::create([
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
            'payment_tender_id' => $tenderId,
            'user_id' => $this->admin->id,
            'transacted_at' => now(),
        ]);
    }

    private function ledger(array $params = [])
    {
        return $this->getJson('/api/v1/financial-transactions?'.http_build_query($params));
    }

    public function test_search_finds_a_match_that_is_not_on_the_first_page(): void
    {
        // 45 rows of noise, then the one we want. It lands well past page 1 of 20.
        foreach (range(1, 45) as $i) {
            $this->entry("Routine entry {$i}");
        }
        $this->entry('Cash drawer short');

        $response = $this->ledger(['search' => 'short'])->assertOk();

        $this->assertSame(1, $response->json('total'), 'The whole period is searched, not one page.');
        $this->assertSame('Cash drawer short', $response->json('data.0.description'));
    }

    public function test_a_narrow_search_collapses_the_ledger_to_a_single_page(): void
    {
        foreach (range(1, 45) as $i) {
            $this->entry("Routine entry {$i}");
        }
        $this->entry('Cash drawer short');

        $this->assertSame(3, $this->ledger()->json('last_page'), 'Unfiltered, the ledger pages.');
        $this->assertSame(1, $this->ledger(['search' => 'short'])->json('last_page'));
    }

    public function test_search_matches_the_tender_the_staff_and_the_type(): void
    {
        $tender = PaymentTender::create(['name' => 'GCash', 'is_active' => true]);
        $this->entry('Nothing notable', 100, 'expense', $tender->id);
        $this->entry('Also nothing', 100);

        $this->assertSame(1, $this->ledger(['search' => 'GCash'])->json('total'));
        $this->assertSame(2, $this->ledger(['search' => $this->admin->name])->json('total'));
        $this->assertSame(2, $this->ledger(['search' => 'expense'])->json('total'));
    }

    public function test_a_search_with_no_matches_returns_an_empty_first_page(): void
    {
        $this->entry('Groceries');

        $response = $this->ledger(['search' => 'nothing like this'])->assertOk();

        $this->assertSame(0, $response->json('total'));
        $this->assertSame([], $response->json('data'));
    }

    public function test_wildcards_in_the_search_are_taken_literally(): void
    {
        $this->entry('Groceries');
        $this->entry('100% markup');
        $this->entry('Single_item charge');

        // A bare % or _ would otherwise match every row.
        $this->assertSame(1, $this->ledger(['search' => '%'])->json('total'));
        $this->assertSame(1, $this->ledger(['search' => '_'])->json('total'));
    }

    public function test_the_escape_character_itself_can_be_searched_for(): void
    {
        $this->entry('Urgent! restock');
        $this->entry('Routine restock');

        $this->assertSame(1, $this->ledger(['search' => '!'])->json('total'));
        $this->assertSame(1, $this->ledger(['search' => 'Urgent!'])->json('total'));
    }

    public function test_search_combines_with_the_type_filter(): void
    {
        $this->entry('Short delivery', 100, 'expense');
        $this->entry('Short payment', 100, 'payment');

        $response = $this->ledger(['search' => 'short', 'type' => 'payment'])->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('Short payment', $response->json('data.0.description'));
    }

    public function test_sorting_by_amount_spans_every_page_not_just_the_first(): void
    {
        foreach (range(1, 25) as $i) {
            $this->entry("Entry {$i}", $i * 10);
        }

        $highest = $this->ledger(['sort' => 'amount', 'direction' => 'desc'])->assertOk();
        $lowest = $this->ledger(['sort' => 'amount', 'direction' => 'asc'])->assertOk();

        $this->assertEquals(250, $highest->json('data.0.amount'));
        $this->assertEquals(10, $lowest->json('data.0.amount'));
    }

    public function test_an_unknown_sort_column_is_ignored(): void
    {
        $this->entry('Groceries');

        $this->ledger(['sort' => 'amount); drop table users;--', 'direction' => 'desc'])->assertOk();
        $this->assertDatabaseCount('financial_transactions', 1);
    }
}
