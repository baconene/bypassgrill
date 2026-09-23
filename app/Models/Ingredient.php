<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ingredient extends Model
{
    use SoftDeletes;

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

    public function recipes()
    {
        return $this->hasMany(Recipe::class);
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
