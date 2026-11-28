<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Reservation requests sent by customers from the homepage. Staff confirm them; payment comes later. */
class PublicReservationController extends Controller
{
    public function store(Request $request, ReservationService $reservations): JsonResponse
    {
        // Bots fill every field; people never see this one
        abort_if($request->filled('website'), 422, 'Could not send the request.');

        $data = $request->validate([
            'contact_name' => 'required|string|max:120',
            'phone' => 'required|string|min:7|max:30',
            'social_media' => 'nullable|string|max:255',
            'reserved_at' => 'required|date|after:now',
            'party_size' => 'required|integer|min:1|max:100',
            'order_type' => ['required', Rule::in(Reservation::ORDER_TYPES)],
            'customer_address' => 'nullable|required_if:order_type,delivery|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'items' => 'sometimes|array|max:30',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1|max:99',
        ], [
            'order_type.required' => 'Choose dine in, takeout or delivery.',
            'customer_address.required_if' => 'Add the delivery address.',
            'reserved_at.after' => 'Choose a date and time in the future.',
        ]);

        $reservation = $reservations->save($data + ['source' => 'online', 'status' => 'pending']);

        return response()->json([
            'reference' => $reservation->id,
            'reserved_at' => $reservation->reserved_at,
            'planned_total' => $reservation->planned_total,
        ], 201);
    }
}
