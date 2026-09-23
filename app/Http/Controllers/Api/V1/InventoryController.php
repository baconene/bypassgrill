<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InventoryTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryAdjustmentRequest;
use App\Http\Resources\InventoryResource;
use App\Models\Ingredient;
use App\Models\InventoryTransaction;
use App\Repositories\InventoryRepository;
use App\Services\FoodProductionService;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            'item_type' => ['nullable', Rule::in(Ingredient::TYPES)],
            'unit' => 'required|string|max:50',
            'current_quantity' => 'required|numeric|min:0',
            'min_quantity' => 'required|numeric|min:0',
            'cost_per_unit' => 'nullable|numeric|min:0',
            'track_inventory' => 'boolean',
            ...$this->componentRules(),
        ]);

        $ingredient = DB::transaction(function () use ($data) {
            $quantity = (float) $data['current_quantity'];
            $components = $data['components'] ?? [];
            unset($data['components']);

            $ingredient = Ingredient::create([
                ...$data, 'current_quantity' => 0,
                'item_type' => $data['item_type'] ?? 'ingredient', 'is_active' => true,
                'track_inventory' => $data['track_inventory'] ?? true,
                'cost_per_unit' => $data['cost_per_unit'] ?? 0,
            ]);
            $this->syncComponents($ingredient, $components);

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
            'item_type' => ['sometimes', Rule::in(Ingredient::TYPES)],
            'unit' => 'sometimes|string|max:50',
            'min_quantity' => 'sometimes|numeric|min:0',
            'cost_per_unit' => 'sometimes|numeric|min:0',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
            ...$this->componentRules(),
        ]);

        DB::transaction(function () use ($ingredient, $data) {
            $components = $data['components'] ?? null;
            unset($data['components']);
            $ingredient->update($data);

            // Absent means "leave them alone"; an empty array means "remove them all".
            if ($components !== null) {
                $this->syncComponents($ingredient->fresh(), $components);
            }
        });

        return response()->json(new InventoryResource($ingredient->fresh()));
    }

    /** @return array<string, mixed> */
    private function componentRules(): array
    {
        return [
            'components' => 'sometimes|array',
            'components.*.ingredient_id' => 'required|integer|distinct|exists:ingredients,id',
            'components.*.quantity' => 'required|numeric|gt:0',
            'components.*.unit' => 'nullable|string|max:50',
        ];
    }

    /**
     * Replace a Food's component list. Only a Food has one, and a Food cannot be made
     * from another Food in this version: that would need cycle detection and recursive
     * costing, which is real work for a case that may never come up.
     */
    private function syncComponents(Ingredient $food, array $components): void
    {
        if (! $food->isFood()) {
            abort_if($components !== [], 422, 'Only a Food item can have ingredients.');
            $food->components()->delete();

            return;
        }

        $chosen = Ingredient::whereIn('id', array_column($components, 'ingredient_id'))->get()->keyBy('id');

        foreach ($components as $component) {
            $item = $chosen->get($component['ingredient_id']);
            abort_unless($item, 422, 'One of the ingredients no longer exists.');
            abort_if($item->isFood(), 422, 'A Food cannot be made from another Food.');
            abort_if($item->id === $food->id, 422, 'A Food cannot be made from itself.');
        }

        $food->components()->delete();

        foreach ($components as $component) {
            $food->components()->create([
                'ingredient_id' => $component['ingredient_id'],
                'quantity' => $component['quantity'],
                'unit' => $component['unit'] ?? $chosen[$component['ingredient_id']]->unit,
            ]);
        }
    }

    public function produce(Request $request, Ingredient $ingredient, FoodProductionService $production): JsonResponse
    {
        if (! auth()->user()?->hasAnyRole('admin', 'auditor')) {
            abort(403, 'Unauthorized');
        }

        $data = $request->validate([
            'batch' => 'required|numeric|gt:0|max:9999999',
            'yield' => 'nullable|numeric|gt:0|max:9999999',
            'notes' => 'nullable|string|max:500',
        ]);

        $transaction = $production->produce(
            $ingredient,
            (float) $data['batch'],
            isset($data['yield']) ? (float) $data['yield'] : null,
            $data['notes'] ?? null,
        );

        return response()->json([
            'transaction' => $transaction,
            'ingredient' => new InventoryResource($ingredient->fresh()),
        ], 201);
    }

    public function undoProduction(InventoryTransaction $transaction, FoodProductionService $production): JsonResponse
    {
        if (! auth()->user()?->hasAnyRole('admin', 'auditor')) {
            abort(403, 'Unauthorized');
        }

        $production->undo($transaction);

        return response()->json(['undone' => true]);
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
