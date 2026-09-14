<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositControl extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'opening_snapshot' => 'array',
            'closing_snapshot' => 'array',
            'reconciliation' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
