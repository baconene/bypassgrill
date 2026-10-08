<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Reservation extends Model{protected $guarded=['id'];protected function casts():array{return ['reserved_at'=>'datetime'];}public function user():BelongsTo{return $this->belongsTo(User::class);}}