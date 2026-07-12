<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryAdjustmentRequest;
use App\Http\Resources\InventoryResource;
use App\Enums\InventoryTransactionType;
use App\Models\Ingredient;
use App\Repositories\InventoryRepository;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;

class InventoryController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService,
        private InventoryRepository $inventoryRepository
    ) {}

    public function store(\Illuminate\Http\Request $request): JsonResponse
    {
        if (! auth()->user()?->hasAnyRole('admin', 'auditor')) {
            abort(403, 'Unauthorized');
        }

        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'item_type'        => 'nullable|in:ingredient,tool,equipment,supply',
            'unit'             => 'required|string|max:50',
            'current_quantity' => 'required|numeric|min:0',
            'min_quantity'     => 'required|numeric|min:0',
            'cost_per_unit'    => 'nullable|numeric|min:0',
            'track_inventory'  => 'boolean',
        ]);

        $ingredient = Ingredient::create([
            ...$data,
            'item_type'       => $data['item_type'] ?? 'ingredient',
            'is_active'       => true,
            'track_inventory' => $data['track_inventory'] ?? true,
            'cost_per_unit'   => $data['cost_per_unit'] ?? 0,
        ]);

        // Record the cost of initial stock as an expense
        $initialCost = (float) $ingredient->current_quantity * (float) $ingredient->cost_per_unit;
        if ($initialCost > 0) {
            \App\Models\FinancialTransaction::create([
                'type'          => 'expense',
                'amount'        => round($initialCost, 2),
                'description'   => "Initial stock: {$ingredient->name}",
                'user_id'       => auth()->id(),
                'transacted_at' => now(),
            ]);
        }

        return response()->json(new InventoryResource($ingredient), 201);
    }

    public function update(\Illuminate\Http\Request $request, Ingredient $ingredient): JsonResponse
    {
        if (! auth()->user()?->hasAnyRole('admin', 'auditor')) {
            abort(403, 'Unauthorized');
        }

        $data = $request->validate([
            'name'            => 'sometimes|string|max:255',
            'item_type'       => 'sometimes|in:ingredient,tool,equipment,supply',
            'unit'            => 'sometimes|string|max:50',
            'min_quantity'    => 'sometimes|numeric|min:0',
            'cost_per_unit'   => 'sometimes|numeric|min:0',
            'track_inventory' => 'boolean',
            'is_active'       => 'boolean',
        ]);

        $ingredient->update($data);

        return response()->json(new InventoryResource($ingredient->fresh()));
    }

    public function index(): JsonResponse
    {
        $ingredients = $this->inventoryRepository->getAll();

        return response()->json(InventoryResource::collection($ingredients));
    }

    public function lowStock(): JsonResponse
    {
        $ingredients = $this->inventoryRepository->getLowStock();

        return response()->json(InventoryResource::collection($ingredients));
    }

    public function adjust(StoreInventoryAdjustmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $ingredient = Ingredient::findOrFail($data['ingredient_id']);

        $type = InventoryTransactionType::from($data['type']);

        $transaction = $this->inventoryService->recordTransaction(
            $ingredient,
            (float) $data['quantity'],
            $type,
            $data['reference'] ?? null,
            $data['notes'] ?? null,
        );

        return response()->json(['transaction' => $transaction], 201);
    }

    public function transactions(Ingredient $ingredient): JsonResponse
    {
        $transactions = $this->inventoryRepository->getTransactions($ingredient->id);

        return response()->json($transactions);
    }

    public function destroy(Ingredient $ingredient): \Illuminate\Http\Response
    {
        if (! auth()->user()?->hasAnyRole('admin', 'auditor')) {
            abort(403, 'Unauthorized');
        }

        $ingredient->delete();

        return response()->noContent();
    }
}
