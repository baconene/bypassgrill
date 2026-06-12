<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Application-level "effective now" override (admin only).
 *
 * When active, an offset (in seconds) is stored so the effective time advances
 * naturally from the chosen moment. The ApplySystemClock middleware feeds this
 * into Carbon::setTestNow(), so now() AND all Eloquent created_at/updated_at
 * timestamps use the override — letting admins backdate late entries.
 */
class SystemClock
{
    private const FILE = 'system_clock.json';

    public static function status(): array
    {
        $s = self::read();
        $effective = self::isActive()
            ? Carbon::createFromTimestamp(time() + $s['offset_seconds'])->setTimezone('Asia/Manila')
            : Carbon::now('Asia/Manila');

        return [
            'active'         => self::isActive(),
            'offset_seconds' => $s['offset_seconds'],
            'label'          => $s['label'],
            'effective'      => $effective->format('M d, Y h:i A'),
            'effective_iso'  => $effective->toIso8601String(),
            'real'           => Carbon::createFromTimestamp(time())->setTimezone('Asia/Manila')->format('M d, Y h:i A'),
        ];
    }

    public static function isActive(): bool
    {
        return (bool) (self::read()['active'] ?? false);
    }

    public static function offsetSeconds(): int
    {
        return (int) (self::read()['offset_seconds'] ?? 0);
    }

    /** Set the effective "now" to the given Manila-local datetime. */
    public static function set(string $manilaDatetime): void
    {
        $target = Carbon::parse($manilaDatetime, 'Asia/Manila');
        self::write([
            'active'         => true,
            'offset_seconds' => $target->getTimestamp() - time(),
            'label'          => $target->format('M d, Y h:i A'),
        ]);
    }

    public static function disable(): void
    {
        self::write(['active' => false, 'offset_seconds' => 0, 'label' => null]);
    }

    private static function read(): array
    {
        try {
            if (Storage::disk('local')->exists(self::FILE)) {
                return array_merge(
                    ['active' => false, 'offset_seconds' => 0, 'label' => null],
                    json_decode(Storage::disk('local')->get(self::FILE), true) ?: []
                );
            }
        } catch (\Throwable) {
            // fall through to defaults
        }
        return ['active' => false, 'offset_seconds' => 0, 'label' => null];
    }

    private static function write(array $data): void
    {
        Storage::disk('local')->put(self::FILE, json_encode($data));
    }
}
