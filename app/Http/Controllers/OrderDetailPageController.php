<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Inertia\Inertia;
use Inertia\Response;

class OrderDetailPageController extends Controller
{
    public function show(Order $order): Response
    {
        $order->load([
            'user',
            'queueNumber',
            'items.product.category',
            'items.modifiers.modifier',
            'payments.tender',
        ]);

        $orderTypeLabel = match($order->order_type) {
            'dine_in'  => 'Dine In',
            'takeout'  => 'Takeout',
            'delivery' => 'Delivery',
            default    => ucfirst($order->order_type),
        };

        $data = [
            'id'               => $order->id,
            'queue_number'     => $order->queueNumber?->number,
            'order_type'       => $order->order_type,
            'order_type_label' => $orderTypeLabel,
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
            'created_at'       => $order->created_at?->toDateTimeString(),
            'completed_at'     => $order->completed_at?->toDateTimeString(),
            'created_by'       => $order->user?->name,
            'public_token'     => $order->public_token,
            'items'            => $order->items->map(fn ($item) => [
                'id'                   => $item->id,
                'product_id'           => $item->product_id,
                'product_name'         => $item->product?->name ?? 'Deleted product',
                'category_name'        => $item->product?->category?->name,
                'quantity'             => $item->quantity,
                'unit_price'           => (float) $item->unit_price,
                'unit_cost'            => (float) ($item->unit_cost ?? 0),
                'subtotal'             => (float) $item->subtotal,
                'cost_subtotal'        => (float) ($item->cost_subtotal ?? 0),
                'special_instructions' => $item->special_instructions,
                'modifiers'            => $item->modifiers->map(fn ($m) => [
                    'name'  => $m->modifier?->name,
                    'price' => (float) $m->price,
                ])->values(),
            ])->values(),
            'payments' => $order->payments->map(fn ($p) => [
                'id'         => $p->id,
                'amount'     => (float) $p->amount,
                'tender'     => $p->tender?->name ?? $p->method ?? 'Cash',
                'status'     => $p->status,
                'reference'  => $p->reference,
                'created_at' => $p->created_at?->toDateTimeString(),
            ])->values(),
        ];

        return Inertia::render('OrderDetail', ['order' => $data]);
    }
}
