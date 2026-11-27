<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class HolidaySettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/Holidays', [
            'holidays' => Holiday::orderBy('date')->get()->map(fn (Holiday $holiday) => [
                'id' => $holiday->id,
                'date' => $holiday->date->toDateString(),
                'name' => $holiday->name,
                'type' => $holiday->type,
                'confirmed' => $holiday->confirmed,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Holiday::create($this->validated($request) + ['confirmed' => true]);

        return back()->with('success', 'Holiday added.');
    }

    public function update(Request $request, Holiday $holiday): RedirectResponse
    {
        $holiday->update($this->validated($request, $holiday) + $request->validate(['confirmed' => ['required', 'boolean']]));

        return back()->with('success', 'Holiday updated.');
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $holiday->delete();

        return back()->with('success', 'Holiday removed.');
    }

    private function validated(Request $request, ?Holiday $holiday = null): array
    {
        return $request->validate([
            'date' => ['required', 'date_format:Y-m-d', Rule::unique('holidays', 'date')->ignore($holiday)],
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(Holiday::TYPES)],
        ], ['date.unique' => 'There is already a holiday on that date.']);
    }
}
