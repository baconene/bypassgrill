<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintServiceSetting extends Model
{
    protected $table = 'print_service_settings';

    protected $fillable = [
        'print_service_url',
        'print_paper_width',
        'print_store_name',
        'print_store_address',
        'print_store_phone',
        'print_footer',
        'print_auto_print',
        'print_enabled',
        'print_channel',
        'social_facebook',
        'social_instagram',
        'receipt_qr_type',
    ];

    protected $casts = [
        'print_paper_width' => 'integer',
        'print_auto_print'  => 'boolean',
        'print_enabled'     => 'boolean',
    ];

    public static function getSetting(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'print_service_url'   => 'http://192.168.1.42:8080',
            'print_paper_width'   => 32,
            'print_store_name'    => '',
            'print_store_address' => '',
            'print_store_phone'   => '',
            'print_footer'        => '',
            'print_auto_print'    => false,
            'print_enabled'       => false,
            'print_channel'       => 'orders',
            'social_facebook'     => null,
            'social_instagram'    => null,
            'receipt_qr_type'     => 'order_url',
        ]);
    }
}
