<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsMobileAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_redirects_to_the_newest_available_pos_artifact(): void
    {
        $artifact = static fn (int $id, string $createdAt, string $branch = 'feature/android-pos-app', bool $expired = false) => [
            'id' => $id,
            'name' => 'bypass-grill-pos-release-apk',
            'created_at' => $createdAt,
            'expired' => $expired,
            'workflow_run' => ['id' => 10, 'head_branch' => $branch],
        ];

        Http::fake([
            'api.github.com/repos/baconene/bypassgrill/actions/artifacts*' => Http::response([
                'artifacts' => [
                    $artifact(1, '2026-11-20T00:00:00Z'),
                    $artifact(2, '2026-11-21T00:00:00Z'),
                    $artifact(3, '2026-11-22T00:00:00Z', expired: true),
                    $artifact(4, '2026-11-23T00:00:00Z', branch: 'main'),
                ],
            ]),
        ]);

        $this->actingAs(User::factory()->create());
        $this->get('/settings/mobile-app/download')
            ->assertRedirect('https://github.com/baconene/bypassgrill/actions/runs/10/artifacts/2');
    }

    public function test_download_uses_the_build_history_when_github_is_unavailable(): void
    {
        Http::fake(['api.github.com/*' => Http::response([], 503)]);

        $this->actingAs(User::factory()->create());
        $this->get('/settings/mobile-app/download')
            ->assertRedirect('https://github.com/baconene/bypassgrill/actions/workflows/android-apk.yml?query=branch%3Afeature%2Fandroid-pos-app%20is%3Asuccess');
    }

    public function test_download_requires_a_pos_login(): void
    {
        $this->get('/settings/mobile-app/download')->assertRedirect('/login');
    }
}
