<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\PageSection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PageContentController extends Controller
{
    public function index(): Response
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        return Inertia::render('settings/PageContent', [
            'sections' => PageSection::orderBy('position')->orderBy('display_order')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $data = $request->validate([
            'label'         => 'required|string|max:100',
            'content'       => 'nullable|string',
            'position'      => 'required|in:before_products,after_products',
            'is_active'     => 'boolean',
            'display_order' => 'integer|min:0',
        ]);

        // Auto-generate a unique key from the label
        $base = Str::slug($data['label'], '_');
        $key  = $base;
        $i    = 2;
        while (PageSection::where('key', $key)->exists()) {
            $key = $base . '_' . $i++;
        }

        PageSection::create([...$data, 'key' => $key]);

        return back()->with('success', 'Section created.');
    }

    public function update(Request $request, PageSection $section): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $data = $request->validate([
            'label'         => 'required|string|max:100',
            'content'       => 'nullable|string',
            'position'      => 'required|in:before_products,after_products',
            'is_active'     => 'boolean',
            'display_order' => 'integer|min:0',
        ]);

        $section->update($data);

        return back()->with('success', 'Section saved.');
    }

    public function reorder(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $request->validate([
            'order'    => 'required|array',
            'order.*'  => 'integer|exists:page_sections,id',
        ]);

        foreach ($request->order as $index => $id) {
            PageSection::where('id', $id)->update(['display_order' => $index]);
        }

        return back()->with('success', 'Order saved.');
    }

    public function toggle(PageSection $section): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $section->update(['is_active' => ! $section->is_active]);

        return back()->with('success', $section->is_active ? 'Section activated.' : 'Section hidden.');
    }

    public function destroy(PageSection $section): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $section->delete();

        return back()->with('success', 'Section deleted.');
    }
}
