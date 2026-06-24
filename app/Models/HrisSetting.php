<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrisSetting extends Model
{
    protected $table = 'hris_settings';

    protected $fillable = [
        'payroll_tender_id',
    ];

    protected $casts = [
        'payroll_tender_id' => 'integer',
    ];

    public static function getSetting(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'payroll_tender_id' => null,
        ]);
    }

    public function payrollTender(): BelongsTo
    {
        return $this->belongsTo(PaymentTender::class, 'payroll_tender_id');
    }
}
