<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class AccountingSetting extends Model
{
    public const DEFAULT_START_DAY = 21;

    protected $table = 'accounting_settings';

    protected $fillable = ['period_start_day'];

    protected $casts = ['period_start_day' => 'integer'];

    public static function getSetting(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'period_start_day' => self::DEFAULT_START_DAY,
        ]);
    }

    public static function startDay(): int
    {
        return static::getSetting()->period_start_day ?: self::DEFAULT_START_DAY;
    }

    /**
     * The accounting period containing $date. Start days are capped at 28 so every
     * month has one; a start day of 1 gives plain calendar months.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function periodContaining(Carbon $date, ?int $startDay = null): array
    {
        $startDay ??= static::startDay();
        $start = $date->copy()->startOfDay()->day($startDay);

        if ($date->day < $startDay) {
            $start->subMonthNoOverflow();
        }

        return [$start, $start->copy()->addMonthNoOverflow()->subDay()];
    }
}
