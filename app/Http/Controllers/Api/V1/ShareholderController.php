<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shareholder;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShareholderController extends Controller
{
    public function index(): JsonResponse
    {
        $this->adminOnly();

        $members = Shareholder::with('user:id,name')->orderByDesc('ownership_percentage')->get();
        $totalActive = Shareholder::totalOwnership();

        return response()->json([
            'shareholders'        => $members,
            'total_ownership'     => round($totalActive, 2),
            'company_percentage'  => round(max(0, 100 - $totalActive), 2),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->adminOnly();
        $data = $this->validateData($request);

        $this->assertUnder100($data['ownership_percentage'], $data['status']);

        $member = Shareholder::create($data);
        AuditLogger::record('shareholder.created', $member, null, $member->toArray(), "Added shareholder {$member->name}");

        return response()->json($member, 201);
    }

    public function update(Request $request, Shareholder $shareholder): JsonResponse
    {
        $this->adminOnly();
        $data = $this->validateData($request);

        $this->assertUnder100($data['ownership_percentage'], $data['status'], $shareholder->id);

        $old = $shareholder->toArray();
        $shareholder->update($data);
        AuditLogger::record('shareholder.updated', $shareholder, $old, $shareholder->toArray(), "Updated shareholder {$shareholder->name}");

        return response()->json($shareholder);
    }

    public function destroy(Shareholder $shareholder): JsonResponse
    {
        $this->adminOnly();

        $old = $shareholder->toArray();
        AuditLogger::record('shareholder.deleted', $shareholder, $old, null, "Removed shareholder {$shareholder->name}");
        $shareholder->delete();

        return response()->json(['ok' => true]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name'                 => 'required|string|max:120',
            'email'                => 'nullable|email|max:160',
            'user_id'              => 'nullable|exists:users,id',
            'ownership_percentage' => 'required|numeric|min:0|max:100',
            'status'               => 'required|in:active,inactive',
            'notes'                => 'nullable|string|max:500',
        ]);
    }

    private function assertUnder100(float $pct, string $status, ?int $excludeId = null): void
    {
        if ($status !== 'active') {
            return;
        }
        $others = Shareholder::totalOwnership($excludeId);
        if (round($others + $pct, 2) > 100) {
            throw ValidationException::withMessages([
                'ownership_percentage' => "Total active ownership would be " . round($others + $pct, 2) . "% — cannot exceed 100% (currently {$others}% allocated).",
            ]);
        }
    }

    private function adminOnly(): void
    {
        if (! auth()->user()?->hasAnyRole('admin')) {
            abort(403, 'Admin only');
        }
    }
}
