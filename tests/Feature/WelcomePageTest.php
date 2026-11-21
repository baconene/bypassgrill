<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WelcomePageTest extends TestCase
{
    use RefreshDatabase;

    private string $cutout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cutout = public_path('images/menu/cutouts/test-dish.webp');
    }

    protected function tearDown(): void
    {
        File::delete($this->cutout);
        File::delete(public_path('images/menu/3d/test-dish.glb'));
        parent::tearDown();
    }

    public function test_homepage_lists_dish_cutouts_dropped_into_the_cutouts_folder(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Welcome')
            ->missing('cutouts.test-dish'));

        File::put($this->cutout, 'image');

        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->where('cutouts.test-dish', '/images/menu/cutouts/test-dish.webp'));
    }

    public function test_homepage_lists_3d_models_dropped_into_the_models_folder(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->missing('models.test-dish'));

        File::put(public_path('images/menu/3d/test-dish.glb'), 'glTF');

        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->where('models.test-dish', '/images/menu/3d/test-dish.glb'));
    }
}
