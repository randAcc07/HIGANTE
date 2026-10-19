<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Schedules store their weekly slot as datetimes on a fixed reference week,
 * so MariaDB can reject overlaps (periods cannot use TIME columns):
 * 2001-01-01 is a Monday, ..., 2001-01-07 is a Sunday.
 */
final class ReferenceWeek
{
    public const MONDAY = '2001-01-01';

    /**
     * The reference datetime for an ISO weekday (1 = Monday ... 7 = Sunday)
     * and a time such as "08:30".
     */
    public static function at(int $isoWeekday, string $time): CarbonImmutable
    {
        if ($isoWeekday < 1 || $isoWeekday > 7) {
            throw new InvalidArgumentException("ISO weekday must be 1-7, got {$isoWeekday}.");
        }

        return CarbonImmutable::parse(self::MONDAY.' '.$time)->addDays($isoWeekday - 1);
    }
}
