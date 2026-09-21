<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'sku',
        'description',
        'price',
        'cost',
        'image',
        'is_active',
        'display_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function recipes()
    {
        return $this->hasMany(Recipe::class);
    }

    public function modifiers()
    {
        return $this->belongsToMany(Modifier::class, 'product_modifier');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Stock flags from the product's tracked recipe ingredients (load recipes.ingredient first).
     * Sold out: an ingredient is at zero or can't cover a single serving.
     * Low stock: not sold out, but an ingredient is at or below its minimum quantity.
     *
     * @return array{soldOut: bool, lowStock: bool}
     */
    public function stockStatus(): array
    {
        $tracked = $this->recipes->filter(fn ($r) => $r->ingredient?->track_inventory);

        $soldOut = $tracked->contains(fn ($r) => (float) $r->ingredient->current_quantity <= 0
            || (float) $r->ingredient->current_quantity < (float) $r->quantity);

        return [
            'soldOut'  => $soldOut,
            'lowStock' => ! $soldOut && $tracked->contains(fn ($r) => $r->ingredient->isLowStock()),
        ];
    }
}
