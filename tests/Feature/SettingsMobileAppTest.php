<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsMobileAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_redirects_to_the_installable_apk(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/settings/mobile-app/download')
            ->assertRedirect('https://github.com/baconene/bypassgrill/releases/download/android-pos/bypass-grill-pos.apk');
    }

    public function test_download_requires_a_pos_login(): void
    {
        $this->get('/settings/mobile-app/download')->assertRedirect('/login');
    }
}
