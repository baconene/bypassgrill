<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentalTenant extends Model
{
    protected $table = 'rental_tenants';

    protected $fillable = ['name', 'business_name', 'contact_number', 'email', 'notes'];

    public function schedules()
    {
        return $this->hasMany(RentalSchedule::class, 'tenant_id');
    }
}
