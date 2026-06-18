<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncentiveRule extends Model
{
    protected $fillable = [
        'name', 'pool_type', 'rate', 'distribution_method',
        'is_active', 'effective_date', 'expiration_date', 'notes',
    ];

    protected $casts = [
        'rate'            => 'decimal:4',
        'is_active'       => 'boolean',
        'effective_date'  => 'date',
        'expiration_date' => 'date',
    ];

    public function scopeEffectiveDuring($query, string $start, string $end)
    {
        return $query
            ->where('is_active', true)
            ->where('effective_date', '<=', $end)
            ->where(fn ($q) => $q->whereNull('expiration_date')
                ->orWhere('expiration_date', '>=', $start));
    }
}
