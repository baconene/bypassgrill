<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InventoryTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryAdjustmentRequest;
use App\Http\Resources\InventoryResource;
use App\Models\Ingredient;
use App\Models\InventoryTransaction;
use App\Repositories\InventoryRepository;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService,
        private InventoryRepository $inventoryRepository
    ) {}

    public function store(Request $request): JsonResponse
    {
        if (! auth()->user()?->hasAnyRole('admin', 'auditor')) {
            abort(403, 'Unauthorized');
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'item_type' => 'nullable|in:ingredient,tool,equipment,supply',
            'unit' => 'required|string|max:50',
            'current_quantity' => 'required|numeric|min:0',
            'min_quantity' => 'required|numeric|min:0',
            'cost_per_unit' => 'nullable|numeric|min:0',
            'track_inventory' => 'boolean',
        ]);

        $ingredient = DB::transaction(function () use ($data) {
            $quantity = (float) $data['current_quantity'];
            $ingredient = Ingredient::create([
                ...$data, 'current_quantity' => 0,
                'item_type' => $data['item_type'] ?? 'ingredient', 'is_active' => true,
                'track_inventory' => $data['track_inventory'] ?? true,
                'cost_per_unit' => $data['cost_per_unit'] ?? 0,
            ]);
            if ($quantity > 0) {
                $this->inventoryService->recordTransaction($ingredient, $quantity, InventoryTransactionType::STOCK_IN, notes: 'Opening stock');
            }

            return $ingredient->fresh();
        });

        return response()->json(new InventoryResource($ingredient), 201);
    }

    public function update(Request $request, Ingredient $ingredient): JsonResponse
    {
        if (! auth()->user()?->hasAnyRole('admin', 'auditor')) {
            abort(403, 'Unauthorized');
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'item_type' => 'sometimes|in:ingredient,tool,equipment,supply',
            'unit' => 'sometimes|string|max:50',
            'min_quantity' => 'sometimes|numeric|min:0',
            'cost_per_unit' => 'sometimes|numeric|min:0',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
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
            unitCost: isset($data['unit_cost']) ? (float) $data['unit_cost'] : null,
        );

        return response()->json(['transaction' => $transaction], 201);
    }

    public function undo(InventoryTransaction $transaction): JsonResponse
    {
        if (! auth()->user()?->hasAnyRole('admin', 'auditor')) {
            abort(403, 'Unauthorized');
        }

        return response()->json([
            'transaction' => $this->inventoryService->undoStockIn($transaction),
        ], 201);
    }

    public function transactions(Ingredient $ingredient): JsonResponse
    {
        $transactions = $this->inventoryRepository->getTransactions($ingredient->id);

        return response()->json($transactions);
    }

    public function destroy(Ingredient $ingredient): Response
    {
        if (! auth()->user()?->hasAnyRole('admin', 'auditor')) {
            abort(403, 'Unauthorized');
        }

        $ingredient->delete();

        return response()->noContent();
    }
}
