<?php

namespace Database\Seeders\Demo;

use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Runs the app's own code as of a past moment, so its now() and the
 * timestamps it writes read as when it happened. Restores whatever clock
 * was set before (tests travel in time too).
 */
final class Clock
{
    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    public static function at(CarbonImmutable $moment, callable $action): mixed
    {
        $now = Carbon::getTestNow();
        Carbon::setTestNow($moment);

        try {
            return $action();
        } finally {
            Carbon::setTestNow($now);
        }
    }
}
