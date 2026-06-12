<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductOwnership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductOwnershipController extends Controller
{
    /** List all active products with their current ownership assignments. */
    public function index(): JsonResponse
    {
        $this->adminOnly();

        $products = Product::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        $ownerships = ProductOwnership::with('shareholder:id,name')
            ->get()
            ->groupBy('product_id');

        $data = $products->map(function ($p) use ($ownerships) {
            $owners = ($ownerships->get($p->id) ?? collect())->map(fn ($o) => [
                'shareholder_id'      => $o->shareholder_id,
                'name'                => $o->shareholder->name,
                'ownership_percentage'=> (float) $o->ownership_percentage,
            ])->values()->all();

            return [
                'product_id'       => $p->id,
                'product_name'     => $p->name,
                'owners'           => $owners,
                'total_percentage' => round(array_sum(array_column($owners, 'ownership_percentage')), 2),
            ];
        });

        return response()->json($data);
    }

    /** Set (replace) all owners for a product. Pass empty owners array to clear. */
    public function update(Request $request, int $productId): JsonResponse
    {
        $this->adminOnly();

        $data = $request->validate([
            'owners'                        => 'present|array',
            'owners.*.shareholder_id'       => 'required|exists:shareholders,id',
            'owners.*.ownership_percentage' => 'required|numeric|min:0.01|max:100',
        ]);

        if (! empty($data['owners'])) {
            $total = array_sum(array_column($data['owners'], 'ownership_percentage'));
            if (abs($total - 100) > 0.01) {
                return response()->json(['message' => 'Ownership percentages must total 100%.'], 422);
            }
        }

        DB::transaction(function () use ($productId, $data) {
            ProductOwnership::where('product_id', $productId)->delete();
            foreach ($data['owners'] as $owner) {
                ProductOwnership::create([
                    'product_id'           => $productId,
                    'shareholder_id'       => $owner['shareholder_id'],
                    'ownership_percentage' => $owner['ownership_percentage'],
                ]);
            }
        });

        $owners = ProductOwnership::with('shareholder:id,name')
            ->where('product_id', $productId)
            ->get()
            ->map(fn ($o) => [
                'shareholder_id'      => $o->shareholder_id,
                'name'                => $o->shareholder->name,
                'ownership_percentage'=> (float) $o->ownership_percentage,
            ])->values()->all();

        return response()->json([
            'product_id'       => $productId,
            'owners'           => $owners,
            'total_percentage' => round(array_sum(array_column($owners, 'ownership_percentage')), 2),
        ]);
    }

    /** Clear all owners for a product (returns it to company-owned). */
    public function destroy(int $productId): JsonResponse
    {
        $this->adminOnly();
        ProductOwnership::where('product_id', $productId)->delete();
        return response()->json(null, 204);
    }

    private function adminOnly(): void
    {
        if (! auth()->user()?->hasAnyRole('admin')) abort(403);
    }
}
