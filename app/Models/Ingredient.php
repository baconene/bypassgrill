<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ingredient extends Model
{
    use SoftDeletes;

    public const TYPE_FOOD = 'food';

    /** Types an item may be. Food is made here; the rest are bought. */
    public const TYPES = ['ingredient', 'tool', 'equipment', 'supply', self::TYPE_FOOD];

    protected $fillable = [
        'name',
        'item_type',
        'unit',
        'current_quantity',
        'min_quantity',
        'cost_per_unit',
        'track_inventory',
        'is_active',
    ];

    protected $casts = [
        'current_quantity' => 'decimal:3',
        'min_quantity' => 'decimal:3',
        'cost_per_unit' => 'decimal:4',
        'track_inventory' => 'boolean',
        'is_active' => 'boolean',
    ];

    /** Product recipe lines that use this item. */
    public function recipes()
    {
        return $this->hasMany(Recipe::class);
    }

    /** What this Food is made of. Empty for every other item type. */
    public function components()
    {
        return $this->hasMany(Recipe::class, 'food_id');
    }

    public function isFood(): bool
    {
        return $this->item_type === self::TYPE_FOOD;
    }

    public function scopeFood($query)
    {
        return $query->where('item_type', self::TYPE_FOOD);
    }

    /**
     * What one unit costs to make at today's component prices. This is the figure a
     * production run is costed at, and it is deliberately live rather than stored:
     * the stored cost_per_unit is the weighted average of batches already made.
     */
    public function componentCost(): float
    {
        return round($this->components->sum(
            fn ($c) => (float) $c->quantity * (float) ($c->ingredient?->cost_per_unit ?? 0)
        ), 4);
    }

    public function transactions()
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    public function isLowStock(): bool
    {
        return $this->current_quantity <= $this->min_quantity;
    }
}
