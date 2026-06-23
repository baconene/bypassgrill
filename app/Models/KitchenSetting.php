<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenSetting extends Model
{
    protected $table = 'kitchen_settings';

    protected $fillable = [
        'serving_fast_minutes',
        'serving_slow_minutes',
    ];

    protected $casts = [
        'serving_fast_minutes' => 'integer',
        'serving_slow_minutes' => 'integer',
    ];

    public static function getSetting(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'serving_fast_minutes' => 5,
            'serving_slow_minutes' => 10,
        ]);
    }
}
