<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class MobileAppController extends Controller
{
    private const REPO = 'baconene/bypassgrill';
    private const BRANCH = 'feature/android-pos-app';
    private const ARTIFACT = 'bypass-grill-pos-release-apk';

    public function show(): Response
    {
        return Inertia::render('settings/MobileApp', ['buildsUrl' => $this->buildsUrl()]);
    }

    public function download(): RedirectResponse
    {
        $artifact = Cache::remember('android-pos-download-artifact', now()->addMinutes(5), function () {
            try {
                $response = Http::acceptJson()->connectTimeout(2)->timeout(4)
                    ->get('https://api.github.com/repos/'.self::REPO.'/actions/artifacts', [
                        'name' => self::ARTIFACT,
                        'per_page' => 50,
                    ]);
            } catch (ConnectionException) {
                return null;
            }

            if (! $response->successful()) {
                return null;
            }

            return collect($response->json('artifacts', []))
                ->filter(fn ($artifact) => ($artifact['name'] ?? null) === self::ARTIFACT
                    && ($artifact['expired'] ?? true) === false
                    && data_get($artifact, 'workflow_run.head_branch') === self::BRANCH
                    && is_numeric(data_get($artifact, 'workflow_run.id'))
                    && is_numeric($artifact['id'] ?? null))
                ->sortByDesc('created_at')
                ->first();
        });

        if (! $artifact) {
            return redirect()->away($this->buildsUrl());
        }

        return redirect()->away(sprintf(
            'https://github.com/%s/actions/runs/%d/artifacts/%d',
            self::REPO,
            $artifact['workflow_run']['id'],
            $artifact['id'],
        ));
    }

    private function buildsUrl(): string
    {
        return 'https://github.com/'.self::REPO.'/actions/workflows/android-apk.yml?query='.rawurlencode('branch:'.self::BRANCH.' is:success');
    }
}
