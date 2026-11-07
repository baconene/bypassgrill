<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class BillInstallment extends Model
{
    protected $fillable = [
        'bill_id', 'installment_number', 'amount', 'due_date',
        'paid_at', 'financial_transaction_id', 'notes',
    ];

    protected $casts = [
        'amount'   => 'decimal:2',
        'due_date' => 'date',
        'paid_at'  => 'datetime',
    ];

    public function bill() { return $this->belongsTo(Bill::class); }
    public function financialTransaction() { return $this->belongsTo(FinancialTransaction::class); }

    public function isPaid(): bool { return $this->paid_at !== null; }

    public function status(): string
    {
        if ($this->paid_at) return 'paid';
        $due = Carbon::parse($this->due_date);
        if ($due->isPast() && !$due->isToday()) return 'overdue';
        if ($due->isToday()) return 'due_today';
        if (Carbon::today()->diffInDays($due) <= 7) return 'upcoming';
        return 'scheduled';
    }
}
