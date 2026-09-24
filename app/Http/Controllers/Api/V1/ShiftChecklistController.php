<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ShiftChecklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftChecklistController extends Controller
{
    public function __construct(private ShiftChecklist $checklist) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->checklist->for($request->user()));
    }

    /** Ticks or unticks one of the steps the system cannot prove on its own. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'step' => 'required|string|in:'.implode(',', ShiftChecklist::MANUAL_STEPS),
        ]);

        $this->checklist->toggle($request->user(), $data['step']);

        return response()->json($this->checklist->for($request->user()));
    }
}
