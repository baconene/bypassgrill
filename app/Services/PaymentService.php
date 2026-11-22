<?php
namespace App\Services;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTender;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Support\Facades\DB;

class PaymentService {
    public function processPayment(Order $order, array $paymentData): Payment {
        return DB::transaction(function () use ($order, $paymentData) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_if($order->status === OrderStatus::CANCELLED->value || in_array($order->payment_status, ['voided', 'refunded'], true), 409, 'This order cannot accept payments.');

            $collected = Payment::where('order_id', $order->id)
                ->where('status', PaymentStatus::COMPLETED->value);
            $paid = (int) round((float) $collected->sum('amount') * 100);
            $total = (int) round((float) $order->total_amount * 100);
            $amount = (int) round((float) $paymentData['amount'] * 100);
            // Mobile checkout can settle a fully discounted order once at zero.
            $zeroSettlement = $total === 0 && $amount === 0 && ! $collected->exists();
            $remaining = max(0, $total - $paid);

            abort_if($order->payment_status === 'paid' || ($remaining === 0 && ! $zeroSettlement), 409, 'This order is already paid.');
            abort_if($amount > $remaining, 409, 'Payment exceeds the remaining balance. Refresh the order before collecting.');
            abort_if($amount <= 0 && ! $zeroSettlement, 422, 'Payment must be greater than zero.');

            $tender = PaymentTender::findOrFail($paymentData['payment_tender_id']);

            $payment = Payment::create([
                'order_id'          => $order->id,
                'user_id'           => auth()->id(),
                'amount'            => $amount / 100,
                'method'            => $tender->name,
                'payment_tender_id' => $tender->id,
                'status'            => PaymentStatus::COMPLETED->value,
                'reference'         => $paymentData['reference'] ?? null,
                'notes'             => $paymentData['notes'] ?? null,
            ]);

            FinancialTransaction::create([
                'type'              => 'payment',
                'amount'            => $payment->amount,
                'description'       => "Payment for Order #{$order->id} via {$tender->name}",
                'order_id'          => $order->id,
                'payment_id'        => $payment->id,
                'payment_tender_id' => $tender->id,
                'user_id'           => auth()->id(),
                'transacted_at'     => now(),
            ]);

            if ($paid + $amount >= $total) {
                $order->update(['payment_status' => 'paid']);
            }

            return $payment;
        });
    }

    public function refundPayment(Payment $payment, array $refundData): \App\Models\Refund {
        return DB::transaction(function () use ($payment, $refundData) {
            $refund = \App\Models\Refund::create([
                'payment_id' => $payment->id,
                'user_id'    => auth()->id(),
                'amount'     => $refundData['amount'],
                'status'     => PaymentStatus::COMPLETED->value,
                'reason'     => $refundData['reason'] ?? null,
            ]);
            $payment->update(['status' => PaymentStatus::REFUNDED->value]);
            $payment->order->update(['payment_status' => 'refunded']);

            // Reverse the money in the ledger: a negative payment in the same tender, so
            // revenue, running balances and deposit snapshots all drop by the refund.
            FinancialTransaction::create([
                'type'              => 'payment',
                'amount'            => -abs((float) $refundData['amount']),
                'description'       => "Refund for Order #{$payment->order_id}"
                    .(! empty($refundData['reason']) ? ": {$refundData['reason']}" : ''),
                'order_id'          => $payment->order_id,
                'payment_id'        => $payment->id,
                'payment_tender_id' => $payment->payment_tender_id,
                'user_id'           => auth()->id(),
                'transacted_at'     => now(),
            ]);

            return $refund;
        });
    }
}
