<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'public_token',
        'user_id',
        'queue_number_id',
        'order_type',
        'status',
        'table_number',
        'customer_name',
        'customer_contact',
        'customer_address',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'notes',
        'payment_status',
        'completed_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (Order $order) {
            $raw   = $order->id . '|' . ($order->customer_name ?? '') . '|' . $order->created_at;
            $token = substr(hash_hmac('sha256', $raw, config('app.key')), 0, 32);
            $order->updateQuietly(['public_token' => $token]);
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function queueNumber()
    {
        return $this->belongsTo(QueueNumber::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function calculateTotals()
    {
        $subtotal = $this->items()->sum('subtotal');
        $totalAmount = $subtotal - ($this->discount_amount ?? 0);

        $this->update([
            'subtotal' => $subtotal,
            'tax_amount' => 0,
            'total_amount' => max(0, $totalAmount),
        ]);

        return $this;
    }
}
