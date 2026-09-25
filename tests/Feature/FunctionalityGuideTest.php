<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FunctionalityGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_and_pos_presentations_are_public_read_only_pages(): void
    {
        $this->get('/demo/functionality')->assertOk()->assertInertia(fn (Assert $page) => $page->component('DemoFunctionality'));
        foreach (['POS', 'cancelOrder', 'pendingPayment', 'modifyOrder', 'gcash', 'receipt'] as $guide) {
            $url = $guide === 'POS' ? '/demo/functionality/POS' : '/demo/functionality/POS/'.$guide;
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('DemoFunctionality')->where('guide', $guide));
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->get('/pos')->assertRedirect('/login');
    }

    public function test_unknown_guides_do_not_render_a_misleading_empty_page(): void
    {
        $this->get('/demo/functionality/POS/unknown')->assertNotFound();
    }

    public function test_every_referenced_screenshot_exists(): void
    {
        $source = file_get_contents(resource_path('js/data/functionality.ts'));
        preg_match_all("/'([0-9]{2}-[a-z-]+)'/", $source, $matches);
        $this->assertGreaterThanOrEqual(21, count(array_unique($matches[1])));
        foreach (array_unique($matches[1]) as $name) {
            $this->assertFileExists(public_path('images/demo/pos/'.$name.'.jpg'));
        }
    }
}
