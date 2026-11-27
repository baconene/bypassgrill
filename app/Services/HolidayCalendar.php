<?php

namespace App\Services;

use App\Models\Holiday;
use Carbon\CarbonImmutable;

/**
 * Philippine holidays and the special days the sales forecast learns from.
 *
 * A long weekend is a run of 3+ days off (Saturdays, Sundays and non-working holidays) containing a holiday.
 * The day before it starts counts too: people eat out the night a break begins.
 */
class HolidayCalendar
{
    // Most specific first: a day gets the first kind that applies.
    public const SPECIAL_DAYS = ['payday_long_weekend', 'long_weekend', 'holiday', 'near_payday'];

    /** @var array<string, array{name: string, day_off: bool}>|null */
    private ?array $holidays = null;

    public function holiday(CarbonImmutable $date): ?string
    {
        return $this->holidays()[$date->toDateString()]['name'] ?? null;
    }

    public function specialDay(CarbonImmutable $date): ?string
    {
        if ($run = $this->longWeekend($date)) {
            [$start, $end] = $run;
            for ($day = $start->subDays(3); $day->lte($end); $day = $day->addDay()) {
                if (WeekdaySalesRank::isPayday($day)) {
                    return 'payday_long_weekend';
                }
            }

            return 'long_weekend';
        }

        if ($this->holiday($date) !== null) {
            return 'holiday';
        }

        return WeekdaySalesRank::nearPayday($date) ? 'near_payday' : null;
    }

    /** The long weekend containing the date, or starting the day after it. */
    public function longWeekend(CarbonImmutable $date): ?array
    {
        foreach ([$date, $date->addDay()] as $probe) {
            if (!$this->dayOff($probe)) {
                continue;
            }

            $start = $end = $probe;
            while ($this->dayOff($start->subDay())) {
                $start = $start->subDay();
            }
            while ($this->dayOff($end->addDay())) {
                $end = $end->addDay();
            }

            $hasHoliday = false;
            for ($day = $start; $day->lte($end); $day = $day->addDay()) {
                $hasHoliday = $hasHoliday || ($this->holidays()[$day->toDateString()]['day_off'] ?? false);
            }

            if ($hasHoliday && $start->diffInDays($end) >= 2) {
                return [$start, $end];
            }
        }

        return null;
    }

    private function dayOff(CarbonImmutable $date): bool
    {
        return $date->isWeekend() || ($this->holidays()[$date->toDateString()]['day_off'] ?? false);
    }

    private function holidays(): array
    {
        return $this->holidays ??= Holiday::query()->get()->mapWithKeys(fn (Holiday $holiday) => [
            $holiday->date->toDateString() => ['name' => $holiday->name, 'day_off' => $holiday->isDayOff()],
        ])->all();
    }
}
