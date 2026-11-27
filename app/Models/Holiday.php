<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    public const TYPES = ['regular', 'special', 'special_working'];

    protected $fillable = ['date', 'name', 'type', 'confirmed'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'confirmed' => 'boolean'];
    }

    public function isDayOff(): bool
    {
        return $this->type !== 'special_working';
    }
}
