<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PosCheckoutService
{
    public function __construct(
        private OrderService $orders,
        private PaymentService $payments,
    ) {}

    public function createOrder(array $data, ?string $clientId, ?string $offlineNumber = null): Order
    {
        if ($clientId === null) {
            return $this->orders->createOrder($data);
        }

        $table = 'mobile_pos_sync_records';
        $replay = function () use ($table, $clientId): ?Order {
            $record = DB::table($table)->where('client_id', $clientId)->first();
            if (! $record) {
                return null;
            }

            abort_unless((int) $record->user_id === (int) auth()->id(), 409, 'Order request ID is already in use.');

            return Order::findOrFail($record->order_id);
        };

        try {
            return DB::transaction(function () use ($data, $clientId, $offlineNumber, $table, $replay) {
                // Serialize checkout attempts from this cashier before any stock is consumed.
                User::query()->lockForUpdate()->findOrFail(auth()->id());

                if ($existing = $replay()) {
                    return $existing;
                }

                $order = $this->orders->createOrder($data);
                if ($offlineNumber && Schema::hasColumn('orders', 'offline_number')) {
                    $order->update(['offline_number' => $offlineNumber]);
                }

                // The unique key commits with the order and its inventory and ledger writes.
                DB::table($table)->insert([
                    'client_id' => $clientId,
                    'order_id' => $order->id,
                    'user_id' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $order;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // The losing transaction has rolled back; read the winner from a fresh snapshot.
            return $replay() ?? throw $e;
        }
    }

    public function collectPayment(Order $order, array $data, ?string $clientId): Payment
    {
        if ($clientId === null) {
            return $this->payments->processPayment($order, $data);
        }

        $table = 'mobile_pos_payment_sync_records';

        try {
            return DB::transaction(function () use ($order, $data, $clientId, $table) {
                $order = Order::query()->lockForUpdate()->findOrFail($order->id);

                if ($existing = $this->replayPayment($order, $data, $clientId)) {
                    return $existing;
                }

                $payment = $this->payments->processPayment($order, $data);
                DB::table($table)->insert([
                    'client_id' => $clientId,
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'user_id' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $payment;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            return $this->replayPayment($order, $data, $clientId) ?? throw $e;
        }
    }

    private function replayPayment(Order $order, array $data, string $clientId): ?Payment
    {
        $record = DB::table('mobile_pos_payment_sync_records')->where('client_id', $clientId)->first();
        if (! $record) {
            return null;
        }

        abort_unless(
            (int) $record->user_id === (int) auth()->id() && (int) $record->order_id === (int) $order->id,
            409,
            'Payment request ID is already in use.',
        );

        $payment = Payment::findOrFail($record->payment_id);
        abort_unless(
            (int) $payment->payment_tender_id === (int) $data['payment_tender_id']
                && (int) round((float) $payment->amount * 100) === (int) round((float) $data['amount'] * 100)
                && $payment->reference === ($data['reference'] ?? null)
                && $payment->notes === ($data['notes'] ?? null),
            409,
            'Payment request ID was already used with different payment details.',
        );

        return $payment;
    }
}
