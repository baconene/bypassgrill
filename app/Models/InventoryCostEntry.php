<?php

namespace App\Models;

use App\Enums\InventoryCostKind;
use App\Enums\InventoryCostSource;
use Illuminate\Database\Eloquent\Model;

class InventoryCostEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['kind' => InventoryCostKind::class, 'source' => InventoryCostSource::class,
            'quantity' => 'decimal:3', 'unit_cost' => 'decimal:4', 'total_cost' => 'decimal:2', 'recognized_at' => 'datetime'];
    }
}
