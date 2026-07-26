<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentalSchedule extends Model
{
    protected $table = 'rental_schedules';

    protected $fillable = [
        'stall_id', 'tenant_id', 'rental_type', 'status',
        'start_date', 'end_date', 'start_time', 'end_time',
        'price', 'notes',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date'   => 'date:Y-m-d',
        'price'      => 'decimal:2',
    ];

    public function stall()
    {
        return $this->belongsTo(RentalStall::class, 'stall_id');
    }

    public function tenant()
    {
        return $this->belongsTo(RentalTenant::class, 'tenant_id');
    }
}
