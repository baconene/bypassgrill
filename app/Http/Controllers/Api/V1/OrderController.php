<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Repositories\OrderRepository;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $orderService,
        private OrderRepository $orderRepository
    ) {}

    public function index(Request $request)
    {
        $this->checkPermission('view orders');

        $applyFilters = function ($q) use ($request) {
            return $q
                ->when($request->status, fn($q) => $q->where('status', $request->status))
                ->when($request->payment_status, fn($q) => $q->where('payment_status', $request->payment_status))
                ->when($request->exclude_cancelled, fn($q) => $q->where('status', '!=', 'cancelled'))
                ->when($request->date_from, fn($q) => $q->where('created_at', '>=',
                    Carbon::parse($request->date_from, 'Asia/Manila')->startOfDay()))
                ->when($request->date_to, fn($q) => $q->where('created_at', '<=',
                    Carbon::parse($request->date_to, 'Asia/Manila')->endOfDay()))
                ->when($request->search, fn($q) => $q->where(function ($q) use ($request) {
                    $q->where('id', $request->search)
                      ->orWhere('customer_name', 'like', "%{$request->search}%")
                      ->orWhere('notes', 'like', "%{$request->search}%")
                      ->orWhere('table_number', 'like', "%{$request->search}%");
                }));
        };

        $allowed  = ['created_at', 'total_amount', 'customer_name', 'status', 'payment_status'];
        $sortBy   = in_array($request->sort_by, $allowed) ? $request->sort_by : 'created_at';
        $sortDir  = $request->sort_dir === 'asc' ? 'asc' : 'desc';

        $orders = $applyFilters(Order::with(['items.product', 'user', 'queueNumber']))
            ->orderBy($sortBy, $sortDir)
            ->paginate(min((int) $request->input('per_page', 20), 100));

        $agg = $applyFilters(Order::query())
            ->selectRaw("
                COUNT(*) as total_count,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END), 0) as paid_count,
                COALESCE(SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END), 0) as unpaid_count,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0) as paid_revenue
            ")
            ->first();

        return OrderResource::collection($orders)->additional([
            'summary' => [
                'total_count'  => (int) $agg->total_count,
                'paid_count'   => (int) $agg->paid_count,
                'unpaid_count' => (int) $agg->unpaid_count,
                'paid_revenue' => round((float) $agg->paid_revenue, 2),
            ],
        ]);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $this->checkPermission('create orders');
        $order = $this->orderService->createOrder($request->validated());
        return response()->json(new OrderResource($order), 201);
    }

    public function show(Order $order): JsonResponse
    {
        $this->checkPermission('view orders');
        $order = $this->orderRepository->getWithItems($order->id);
        return response()->json(new OrderResource($order));
    }

    public function update(Order $order, Request $request): JsonResponse
    {
        $this->checkPermission('update orders');

        $data = $request->validate([
            'notes'                  => 'nullable|string|max:500',
            'discount_amount'        => 'nullable|numeric|min:0',
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|integer|exists:products,id',
            'items.*.quantity'       => 'required|integer|min:1',
        ]);

        DB::transaction(function () use ($order, $data) {
            if (array_key_exists('notes', $data)) {
                $order->update(['notes' => $data['notes']]);
            }
            if (array_key_exists('discount_amount', $data)) {
                $order->update(['discount_amount' => $data['discount_amount']]);
            }

            $order->items()->delete();

            foreach ($data['items'] as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);
                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => $product->id,
                    'quantity'   => $itemData['quantity'],
                    'unit_price' => $product->price,
                    'subtotal'   => $product->price * $itemData['quantity'],
                ]);
            }

            $order->calculateTotals();
        });

        $fresh = $order->fresh(['items.product', 'queueNumber']);
        return response()->json(\App\Http\Controllers\QueueMonitorController::formatOrder($fresh));
    }

    public function destroy(Order $order): Response
    {
        $this->checkPermission('delete orders');
        return response()->noContent();
    }

    public function updateStatus(Order $order, UpdateOrderStatusRequest $request): JsonResponse
    {
        $this->checkPermission('update orders');
        $order = $this->orderService->updateOrderStatus($order, $request->enum('status', OrderStatus::class));
        return response()->json(new OrderResource($order));
    }

    public function cancel(Order $order): JsonResponse
    {
        $this->checkPermission('update orders');
        $order = $this->orderService->cancelOrder($order, request()->input('reason'));
        return response()->json(new OrderResource($order));
    }

    public function activeOrders(): JsonResponse
    {
        $this->checkPermission('view orders');
        $orders = $this->orderRepository->getActiveOrders();
        return response()->json(
            $orders->map(fn ($o) => \App\Http\Controllers\QueueMonitorController::formatOrder($o))->values()
        );
    }

    public function queueOrders(): JsonResponse
    {
        $this->checkPermission('view orders');
        $orders = $this->orderRepository->getQueueOrders();
        return response()->json(OrderResource::collection($orders));
    }

    private function checkPermission(string $permission): void
    {
        if (! auth()->user()?->hasAnyRole('admin') && ! auth()->user()?->hasPermissionTo($permission)) {
            abort(403, 'Unauthorized');
        }
    }
}
