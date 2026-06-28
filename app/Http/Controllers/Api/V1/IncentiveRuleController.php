<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IncentiveRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncentiveRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $this->adminOnly();
        return response()->json(IncentiveRule::orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->adminOnly();
        $data = $this->validateData($request);
        $rule = IncentiveRule::create($data);
        return response()->json($rule, 201);
    }

    public function update(Request $request, IncentiveRule $incentiveRule): JsonResponse
    {
        $this->adminOnly();
        $data = $this->validateData($request);
        $incentiveRule->update($data);
        return response()->json($incentiveRule);
    }

    public function destroy(IncentiveRule $incentiveRule): JsonResponse
    {
        $this->adminOnly();
        $incentiveRule->delete();
        return response()->json(null, 204);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name'                => 'required|string|max:160',
            'pool_type'           => 'required|in:gross_sales_pct,gross_profit_pct,net_profit_pct,fixed_amount,product_sales_pct',
            'rate'                => 'required|numeric|min:0',
            'distribution_method' => 'required|in:by_sales,equal',
            'is_active'           => 'boolean',
            'effective_date'      => 'required|date',
            'expiration_date'     => 'nullable|date|after_or_equal:effective_date',
            'notes'               => 'nullable|string|max:500',
        ]);
    }

    private function adminOnly(): void
    {
        if (! auth()->user()?->hasAnyRole('admin')) abort(403);
    }
}
