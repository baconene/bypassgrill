<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\Recipe;
use App\Repositories\ProductRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function __construct(private ProductRepository $productRepository) {}

    public function index(): JsonResponse
    {
        return response()->json(ProductResource::collection($this->productRepository->getActive()));
    }

    public function byCategory(int $categoryId): JsonResponse
    {
        return response()->json(ProductResource::collection($this->productRepository->getByCategoryId($categoryId)));
    }

    public function search(): JsonResponse
    {
        $query = request()->input('q');
        if (empty($query)) return response()->json([]);
        return response()->json(ProductResource::collection($this->productRepository->search($query)));
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(new ProductResource($product->load('category', 'modifiers')));
    }

    public function store(Request $request): JsonResponse
    {
        $this->adminOnly();

        $data = $request->validate([
            'category_id'             => 'required|exists:categories,id',
            'name'                    => 'required|string|max:255',
            'sku'                     => 'nullable|string|max:100|unique:products,sku',
            'description'             => 'nullable|string',
            'price'                   => 'required|numeric|min:0',
            'cost'                    => 'nullable|numeric|min:0',
            'is_active'               => 'boolean',
            'display_order'           => 'nullable|integer|min:0',
            'image'                   => 'nullable|image|mimes:jpeg,png,webp|max:2048',
            'recipes'                 => 'nullable|array',
            'recipes.*.ingredient_id' => 'required|exists:ingredients,id',
            'recipes.*.quantity'      => 'required|numeric|min:0',
            'recipes.*.unit'          => 'nullable|string|max:50',
        ]);

        $data['sku'] = $data['sku'] ?: null;

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $product = Product::create(Arr::except($data, ['recipes']));

        foreach ($data['recipes'] ?? [] as $row) {
            Recipe::create([
                'product_id'    => $product->id,
                'ingredient_id' => $row['ingredient_id'],
                'quantity'      => $row['quantity'],
                'unit'          => $row['unit'] ?? null,
            ]);
        }

        return response()->json(
            new ProductResource($product->load('category', 'modifiers', 'recipes.ingredient')),
            201
        );
    }

    // Accepts both PUT (JSON) and POST (FormData / file upload)
    public function update(Request $request, Product $product): JsonResponse
    {
        $this->adminOnly();

        $data = $request->validate([
            'category_id'             => 'sometimes|exists:categories,id',
            'name'                    => 'sometimes|string|max:255',
            'sku'                     => 'nullable|string|max:100|unique:products,sku,' . $product->id,
            'description'             => 'nullable|string',
            'price'                   => 'sometimes|numeric|min:0',
            'cost'                    => 'nullable|numeric|min:0',
            'is_active'               => 'boolean',
            'display_order'           => 'nullable|integer|min:0',
            'image'                   => 'nullable|image|mimes:jpeg,png,webp|max:2048',
            'recipes'                 => 'nullable|array',
            'recipes.*.ingredient_id' => 'required|exists:ingredients,id',
            'recipes.*.quantity'      => 'required|numeric|min:0',
            'recipes.*.unit'          => 'nullable|string|max:50',
        ]);

        $data['sku'] = $data['sku'] ?: null;

        if ($request->hasFile('image')) {
            if ($product->image) Storage::disk('public')->delete($product->image);
            $data['image'] = $request->file('image')->store('products', 'public');
        } elseif ($request->input('remove_image')) {
            if ($product->image) Storage::disk('public')->delete($product->image);
            $data['image'] = null;
        } else {
            unset($data['image']);
        }

        $product->update(Arr::except($data, ['recipes']));

        if (array_key_exists('recipes', $data)) {
            $product->recipes()->delete();
            foreach ($data['recipes'] ?? [] as $row) {
                Recipe::create([
                    'product_id'    => $product->id,
                    'ingredient_id' => $row['ingredient_id'],
                    'quantity'      => $row['quantity'],
                    'unit'          => $row['unit'] ?? null,
                ]);
            }
        }

        return response()->json(
            new ProductResource($product->fresh()->load('category', 'modifiers', 'recipes.ingredient'))
        );
    }

    public function calculateCost(Product $product): JsonResponse
    {
        $this->adminOnly();

        $product->load('recipes.ingredient');

        abort_if($product->recipes->contains(fn ($recipe) => (float) $recipe->quantity <= 0), 422, 'Complete all recipe quantities before calculating cost.');

        $calculatedCost = $product->recipes->sum(
            fn ($r) => (float) $r->quantity * (float) ($r->ingredient?->cost_per_unit ?? 0)
        );

        // Auto-save the calculated cost to the product
        $product->update(['cost' => $calculatedCost]);

        return response()->json(['cost' => round($calculatedCost, 2)]);
    }

    /**
     * Re-cost every product that has a recipe, so stored costs catch up with what
     * ingredients are worth now. Products without a recipe are left alone: their
     * stored cost is the only figure COGS has to fall back on.
     */
    public function recalculateCosts(): JsonResponse
    {
        $this->adminOnly();

        $updated = 0;
        $unchanged = 0;

        Product::with('recipes.ingredient')->has('recipes')->chunkById(100, function ($products) use (&$updated, &$unchanged) {
            foreach ($products as $product) {
                if ($product->recipes->contains(fn ($recipe) => (float) $recipe->quantity <= 0)) {
                    $unchanged++;

                    continue;
                }
                $recipeCost = round($product->recipes->sum(
                    fn ($r) => (float) $r->quantity * (float) ($r->ingredient?->cost_per_unit ?? 0)
                ), 2);

                if (round((float) $product->cost, 2) === $recipeCost) {
                    $unchanged++;

                    continue;
                }

                $product->update(['cost' => $recipeCost]);
                $updated++;
            }
        });

        return response()->json(['updated' => $updated, 'unchanged' => $unchanged]);
    }

    public function destroy(Product $product): Response
    {
        $this->adminOnly();
        if ($product->image) Storage::disk('public')->delete($product->image);
        $product->delete();
        return response()->noContent();
    }

    private function adminOnly(): void
    {
        if (! auth()->user()?->hasAnyRole('admin')) {
            abort(403, 'Only admins can manage products');
        }
    }
}
