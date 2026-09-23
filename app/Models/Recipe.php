<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    protected $fillable = ['product_id', 'food_id', 'ingredient_id', 'quantity', 'unit'];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** Set when this row is a component of a Food rather than a product recipe line. */
    public function food()
    {
        return $this->belongsTo(Ingredient::class, 'food_id')->withTrashed();
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
