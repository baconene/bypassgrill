<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A reservation can carry a planned order. Nothing is ordered, no stock is used and no sale is
 * recorded until the customer pays: checkout() then creates the POS order and records the
 * payment together, so an unpaid reservation never leaves an open order behind.
 */
class ReservationService
{
    public function __construct(
        private OrderService $orders,
        private PaymentService $payments,
    ) {}

    public function save(array $data, ?Reservation $reservation = null): Reservation
    {
        return DB::transaction(function () use ($data, $reservation) {
            $items = $data['items'] ?? null;
            unset($data['items']);
            if ($reservation) {
                abort_if($reservation->order_id && $items !== null, 409, 'This reservation is already paid; change the order in the POS.');
                $reservation->update($data);
            } else {
                $reservation = Reservation::create($data);
            }

            if ($items !== null) {
                $this->syncItems($reservation, $items);
            }

            return $reservation->fresh()->load(['user:id,name', 'items.product:id,name,price', 'order:id,total_amount,payment_status']);
        });
    }

    private function syncItems(Reservation $reservation, array $items): void
    {
        // One line per product, quantities added together
        $quantities = [];
        foreach ($items as $item) {
            $quantities[(int) $item['product_id']] = ($quantities[(int) $item['product_id']] ?? 0) + (int) $item['quantity'];
        }

        $inactive = array_diff(array_keys($quantities), Product::whereIn('id', array_keys($quantities))->where('is_active', true)->pluck('id')->all());
        if ($inactive) {
            throw ValidationException::withMessages(['items' => 'Some items are no longer on the menu. Remove them and try again.']);
        }

        $reservation->items()->delete();
        foreach ($quantities as $productId => $quantity) {
            $reservation->items()->create(['product_id' => $productId, 'quantity' => min($quantity, 999)]);
        }
    }

    /** @return array{order: Order, payment: Payment} */
    public function checkout(Reservation $reservation, array $payment): array
    {
        return DB::transaction(function () use ($reservation, $payment) {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            abort_if($reservation->order_id !== null, 409, 'This reservation is already paid.');
            abort_if(in_array($reservation->status, ['cancelled', 'no_show'], true), 409, 'Cancelled reservations cannot be paid.');
            abort_unless($reservation->order_type, 422, 'Choose an order type (dine in, takeout or delivery) before collecting payment.');
            $items = $reservation->items()->get();
            abort_if($items->isEmpty(), 422, 'Add the planned order before collecting payment.');

            $order = $this->orders->createOrder([
                'order_type' => $reservation->order_type,
                'customer_name' => $reservation->contact_name,
                'customer_contact' => $reservation->phone ?: $reservation->social_media,
                'customer_address' => $reservation->customer_address,
                'notes' => trim("Reservation #{$reservation->id} for {$reservation->reserved_at->timezone('Asia/Manila')->format('M j, g:i A')}, "
                    ."party of {$reservation->party_size}".($reservation->notes ? ". {$reservation->notes}" : '')),
                'items' => $items->map(fn ($item) => ['product_id' => $item->product_id, 'quantity' => $item->quantity])->all(),
            ]);

            $amount = $payment['amount'] ?? (float) $order->fresh()->total_amount;
            $recorded = $this->payments->processPayment($order, [
                'amount' => $amount,
                'payment_tender_id' => $payment['payment_tender_id'],
                'reference' => $payment['reference'] ?? null,
                'notes' => $payment['notes'] ?? "Reservation #{$reservation->id}",
            ]);

            $reservation->update([
                'order_id' => $order->id,
                'paid_at' => now(),
                'status' => $reservation->status === 'pending' ? 'confirmed' : $reservation->status,
            ]);

            return ['order' => $order->fresh(), 'payment' => $recorded];
        });
    }
}
