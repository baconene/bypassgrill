<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdvertisementController extends Controller
{
    public function index(): Response
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        return Inertia::render('settings/Advertisements', [
            'advertisements' => Advertisement::orderBy('display_order')->orderBy('created_at')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $data = $request->validate([
            'type'          => 'required|in:banner,promo,announcement',
            'title'         => 'required|string|max:255',
            'body'          => 'nullable|string|max:1000',
            'badge_text'    => 'nullable|string|max:50',
            'bg_color'      => 'required|in:orange,red,green,blue,yellow',
            'is_active'     => 'boolean',
            'display_order' => 'integer|min:0',
            'starts_at'     => 'nullable|date',
            'ends_at'       => 'nullable|date|after_or_equal:starts_at',
        ]);

        Advertisement::create($data);

        return back()->with('success', 'Advertisement created.');
    }

    public function update(Request $request, Advertisement $advertisement): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $data = $request->validate([
            'type'          => 'required|in:banner,promo,announcement',
            'title'         => 'required|string|max:255',
            'body'          => 'nullable|string|max:1000',
            'badge_text'    => 'nullable|string|max:50',
            'bg_color'      => 'required|in:orange,red,green,blue,yellow',
            'is_active'     => 'boolean',
            'display_order' => 'integer|min:0',
            'starts_at'     => 'nullable|date',
            'ends_at'       => 'nullable|date|after_or_equal:starts_at',
        ]);

        $advertisement->update($data);

        return back()->with('success', 'Advertisement updated.');
    }

    public function destroy(Advertisement $advertisement): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $advertisement->delete();

        return back()->with('success', 'Advertisement deleted.');
    }

    public function toggle(Advertisement $advertisement): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $advertisement->update(['is_active' => ! $advertisement->is_active]);

        return back()->with('success', $advertisement->is_active ? 'Advertisement activated.' : 'Advertisement deactivated.');
    }
}
