<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shareholder extends Model
{
    protected $fillable = ['name', 'email', 'user_id', 'ownership_percentage', 'status', 'notes'];

    protected $casts = [
        'ownership_percentage' => 'decimal:2',
    ];

    public function royaltyRules(): HasMany
    {
        return $this->hasMany(RoyaltyRule::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public static function totalOwnership(?int $excludeId = null): float
    {
        return (float) static::active()
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->sum('ownership_percentage');
    }
}
