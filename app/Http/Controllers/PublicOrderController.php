<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Inertia\Inertia;
use Inertia\Response;

class PublicOrderController extends Controller
{
    public function show(string $token): Response
    {
        $order = Order::with(['items.product', 'user', 'queueNumber', 'payments.tender'])
            ->where('public_token', $token)
            ->firstOrFail();

        $payment        = $order->payments->where('status', 'completed')->first()
                       ?? $order->payments->sortByDesc('created_at')->first();
        $amountTendered = (float) ($payment?->amount ?? $order->total_amount);
        $change         = max(0.0, $amountTendered - (float) $order->total_amount);

        return Inertia::render('PublicOrderPage', [
            'order' => [
                'id'               => $order->id,
                'queue_number'     => $order->queueNumber?->number,
                'order_type'       => $order->order_type,
                'status'           => $order->status,
                'payment_status'   => $order->payment_status,
                'table_number'     => $order->table_number,
                'customer_name'    => $order->customer_name,
                'customer_contact' => $order->customer_contact,
                'customer_address' => $order->customer_address,
                'notes'            => $order->notes,
                'subtotal'         => (float) $order->subtotal,
                'discount_amount'  => (float) $order->discount_amount,
                'tax_amount'       => (float) $order->tax_amount,
                'total_amount'     => (float) $order->total_amount,
                'cashier'          => $order->user?->name,
                'created_at'       => $order->created_at?->setTimezone('Asia/Manila')->format('M d, Y h:i A'),
                'items'            => $order->items->map(fn ($item) => [
                    'name'       => $item->product?->name ?? 'Unknown Item',
                    'quantity'   => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal'   => (float) $item->subtotal,
                ])->values(),
                'payment' => $payment ? [
                    'method'    => $payment->tender?->name ?? $payment->method ?? 'Cash',
                    'amount'    => $amountTendered,
                    'change'    => $change,
                    'status'    => $payment->status,
                ] : null,
            ],
        ]);
    }
}
