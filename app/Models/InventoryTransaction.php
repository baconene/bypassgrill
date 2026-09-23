<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryTransaction extends Model
{
    protected $fillable = [
        'ingredient_id',
        'user_id',
        'type',
        'quantity',
        'old_quantity',
        'new_quantity',
        'reference',
        'notes',
        'order_id',
        'order_item_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'old_quantity' => 'decimal:3',
        'new_quantity' => 'decimal:3',
    ];

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
