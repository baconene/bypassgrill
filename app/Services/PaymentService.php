<?php
namespace App\Services;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTender;
use App\Enums\PaymentStatus;
use Illuminate\Support\Facades\DB;

class PaymentService {
    public function processPayment(Order $order, array $paymentData): Payment {
        return DB::transaction(function () use ($order, $paymentData) {
            $tender = PaymentTender::findOrFail($paymentData['payment_tender_id']);

            $payment = Payment::create([
                'order_id'          => $order->id,
                'user_id'           => auth()->id(),
                'amount'            => $paymentData['amount'],
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

            $totalPaid = Payment::where('order_id', $order->id)
                ->where('status', PaymentStatus::COMPLETED->value)
                ->sum('amount');

            if ($totalPaid >= $order->total_amount && $order->payment_status !== 'paid') {
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
            return $refund;
        });
    }
}
