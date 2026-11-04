<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepositAdjustment extends Model
{
    public const REQUIRED_APPROVALS = 3;

    // 'rejected' stays open: the requester decides whether to ask for another approval or keep it rejected ('closed')
    public const OPEN_STATUSES = ['pending', 'approved', 'rejected'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'posted_at' => 'datetime',
            'rejection_history' => 'array',
        ];
    }

    public function depositControl(): BelongsTo
    {
        return $this->belongsTo(DepositControl::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(PaymentTender::class, 'payment_tender_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'financial_transaction_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(DepositAdjustmentApproval::class)->orderBy('approved_at');
    }
}
