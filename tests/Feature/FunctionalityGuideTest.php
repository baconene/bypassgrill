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
        $this->get('/demo/functionality/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->component('DemoFunctionality')->where('guide', 'dashboard'));
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

    /**
     * The guide list above is written by hand, so it can fall behind the content.
     * Adding the dashboard guide needed a route of its own, and a guide that has
     * content but no route is a dead link on the directory page.
     */
    public function test_every_guide_in_the_content_file_has_a_route(): void
    {
        $source = file_get_contents(resource_path('js/data/functionality.ts'));
        // Hyphens allowed: deposit-control is a guide key as well as a folder.
        preg_match_all("/^        key: '([A-Za-z][A-Za-z-]*)',$/m", $source, $matches);
        $keys = array_unique($matches[1]);

        $this->assertContains('dashboard', $keys);
        $this->assertContains('orders', $keys);
        $this->assertContains('deposit-control', $keys);
        $this->assertContains('POS', $keys);

        // Read guideUrl()'s own list of top-level guides rather than repeating it
        // here, or this test needs editing every time a guide is added and stops
        // being the thing that notices.
        preg_match("/\[([^\]]*)\]\.includes\(key\)/", $source, $topLevel);
        preg_match_all("/'([A-Za-z][A-Za-z-]*)'/", $topLevel[1] ?? '', $found);
        $topLevelKeys = $found[1];
        $this->assertNotEmpty($topLevelKeys, 'guideUrl() no longer lists its top-level guides.');

        foreach ($keys as $key) {
            $url = in_array($key, $topLevelKeys, true)
                ? '/demo/functionality/'.$key
                : '/demo/functionality/POS/'.$key;

            $this->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('DemoFunctionality')->where('guide', $key));
        }
    }

    public function test_every_referenced_screenshot_exists(): void
    {
        $source = file_get_contents(resource_path('js/data/functionality.ts'));
        // Any guide folder, not a named one: the previous pattern only allowed
        // "dashboard/", so the orders screenshots were silently going unchecked.
        preg_match_all("/'((?:[a-z][a-z-]*\/)?[0-9]{2}-[a-z-]+)'/", $source, $matches);
        $names = array_unique($matches[1]);
        $this->assertGreaterThanOrEqual(48, count($names));

        // Each guide keeps its own folder, so every one must be represented.
        foreach (['dashboard/', 'orders/', 'deposit-control/'] as $folder) {
            $this->assertNotEmpty(
                array_filter($names, fn ($name) => str_starts_with($name, $folder)),
                $folder.' screenshots are not being checked.'
            );
        }

        foreach ($names as $name) {
            $path = str_contains($name, '/') ? $name : 'pos/'.$name;
            $this->assertFileExists(public_path('images/demo/'.$path.'.jpg'));
        }
    }
}
