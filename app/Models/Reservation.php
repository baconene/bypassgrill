<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    public const ORDER_TYPES = ['dine_in', 'takeout', 'delivery'];

    public const STATUSES = ['pending', 'confirmed', 'arrived', 'completed', 'cancelled', 'no_show'];

    protected $guarded = ['id'];

    protected $appends = ['planned_total', 'reserved_local'];

    protected function casts(): array
    {
        return ['reserved_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReservationItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Store-local date and time (Asia/Manila), e.g. 2026-12-01T18:00, so screens never shift it by the device's timezone. */
    public function getReservedLocalAttribute(): ?string
    {
        return $this->reserved_at?->copy()->timezone(config('app.timezone'))->format('Y-m-d\\TH:i');
    }

    /** Planned order total at today's menu prices; the order uses the prices current when it is paid. */
    public function getPlannedTotalAttribute(): float
    {
        if (! $this->relationLoaded('items')) {
            return 0.0;
        }

        return round($this->items->sum(fn ($item) => (float) ($item->product?->price ?? 0) * $item->quantity), 2);
    }
}
