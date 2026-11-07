<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Bill extends Model
{
    protected $fillable = [
        'name', 'description', 'amount', 'frequency', 'due_date',
        'category', 'is_active', 'is_installment', 'installment_count',
        'last_paid_at', 'user_id',
    ];

    protected $casts = [
        'amount'            => 'decimal:2',
        'due_date'          => 'date',
        'last_paid_at'      => 'datetime',
        'is_active'         => 'boolean',
        'is_installment'    => 'boolean',
        'installment_count' => 'integer',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function installments() { return $this->hasMany(BillInstallment::class)->orderBy('installment_number'); }

    public function status(): string
    {
        if (!$this->is_active) return 'inactive';

        if ($this->is_installment) {
            $next = $this->relationLoaded('installments')
                ? $this->installments->whereNull('paid_at')->sortBy('due_date')->first()
                : $this->installments()->whereNull('paid_at')->orderBy('due_date')->first();
            if (!$next) return 'inactive';
            $due = Carbon::parse($next->due_date);
        } else {
            $due = Carbon::parse($this->due_date);
        }

        if ($due->isPast() && !$due->isToday()) return 'overdue';
        if ($due->isToday()) return 'due_today';
        if (Carbon::today()->diffInDays($due) <= 7) return 'upcoming';
        return 'scheduled';
    }

    public function nextDueDate(): ?Carbon
    {
        $base = Carbon::parse($this->due_date);
        return match ($this->frequency) {
            'one_time'    => null,
            'daily'       => $base->addDay(),
            'weekly'      => $base->addWeek(),
            'bi_weekly'   => $base->addWeeks(2),
            'monthly'     => $base->addMonth(),
            'quarterly'   => $base->addMonths(3),
            'semi_annual' => $base->addMonths(6),
            'annual'      => $base->addYear(),
            default       => null,
        };
    }
}
