<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentalStall extends Model
{
    protected $table = 'rental_stalls';

    protected $fillable = ['number', 'label', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function schedules()
    {
        return $this->hasMany(RentalSchedule::class, 'stall_id');
    }

    public function activeScheduleOn(string $date)
    {
        return $this->schedules()
            ->whereIn('status', ['confirmed', 'reserved', 'maintenance'])
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->with('tenant')
            ->first();
    }
}
