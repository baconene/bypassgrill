<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    private const RELATIONS = ['user:id,name', 'items.product:id,name,price', 'order:id,total_amount,payment_status'];

    public function __construct(private ReservationService $reservations) {}

    public function index(Request $request): JsonResponse
    {
        $query = Reservation::with(self::RELATIONS)->orderBy('reserved_at');
        if ($request->filled('from')) {
            $query->where('reserved_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('reserved_at', '<=', $request->input('to'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->data($request);
        if (! empty($data['client_id']) && ($existing = Reservation::where('client_id', $data['client_id'])->first())) {
            return response()->json($existing->load(self::RELATIONS));
        }
        $data['user_id'] = $request->user()->id;

        return response()->json($this->reservations->save($data), 201);
    }

    public function update(Request $request, Reservation $reservation): JsonResponse
    {
        return response()->json($this->reservations->save($this->data($request, false), $reservation));
    }

    public function destroy(Reservation $reservation): JsonResponse
    {
        abort_if($reservation->order_id !== null, 409, 'This reservation is paid and has an order. Cancel it instead.');
        $reservation->delete();

        return response()->json(null, 204);
    }

    /** Customer paid: create the POS order from the planned items and record the payment. */
    public function checkout(Request $request, Reservation $reservation): JsonResponse
    {
        abort_unless($request->user()->can('process payments'), 403);
        $data = $request->validate([
            'payment_tender_id' => 'required|exists:payment_tenders,id',
            'amount' => 'nullable|numeric|min:0.01',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);
        $result = $this->reservations->checkout($reservation, $data);

        return response()->json([
            'reservation' => $reservation->fresh()->load(self::RELATIONS),
            'order_id' => $result['order']->id,
            'payment_id' => $result['payment']->id,
            'payment_status' => $result['order']->payment_status,
        ], 201);
    }

    private function data(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'client_id' => 'nullable|uuid',
            'contact_name' => "$required|string|max:255",
            'phone' => 'nullable|string|max:50',
            'social_media' => 'nullable|string|max:255',
            'reserved_at' => "$required|date",
            'party_size' => 'nullable|integer|min:1|max:500',
            'status' => ['nullable', Rule::in(Reservation::STATUSES)],
            'notes' => 'nullable|string|max:2000',
            'order_type' => [$required, Rule::in(Reservation::ORDER_TYPES)],
            'customer_address' => 'nullable|required_if:order_type,delivery|string|max:500',
            'items' => 'sometimes|array|max:50',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1|max:999',
        ], ['customer_address.required_if' => 'Add the delivery address.']);
    }
}
