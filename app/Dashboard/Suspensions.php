<?php

namespace App\Dashboard;

use App\Attendance\ClassDays;
use App\Models\CalendarEvent;
use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Holidays and suspensions coming up that cancel at least one of a set of
 * classes (a teacher's, a learner's), soonest first.
 */
final class Suspensions
{
    public const DAYS_AHEAD = 30;

    /**
     * @param  Collection<int, Schedule>  $schedules  with their term loaded
     * @return list<array{title: string, starts_on: string, ends_on: string, from: string|null, until: string|null, classes: int}>
     */
    public static function upcoming(Collection $schedules): array
    {
        if ($schedules->isEmpty()) {
            return [];
        }

        $today = CarbonImmutable::today();
        $horizon = $today->addDays(self::DAYS_AHEAD);
        $events = CalendarEvent::query()
            ->with('programs:id')
            ->where('suspends_classes', true)
            ->where('ends_on', '>=', $today->toDateString())
            ->where('starts_on', '<=', $horizon->toDateString())
            ->orderBy('starts_on')
            ->get();
        $upcoming = [];

        foreach ($events as $event) {
            $caught = [];

            for ($day = $event->starts_on->max($today); $day->lte($event->ends_on->min($horizon)); $day = $day->addDay()) {
                foreach ($schedules as $schedule) {
                    if ($schedule->weekday() === $day->dayOfWeekIso && $day->between($schedule->term->starts_on, $schedule->term->ends_on) && ClassDays::catches($event, $schedule)) {
                        $caught[$schedule->id] = true;
                    }
                }
            }

            if ($caught !== []) {
                $upcoming[] = [
                    'title' => $event->title,
                    'starts_on' => $event->starts_on->toDateString(),
                    'ends_on' => $event->ends_on->toDateString(),
                    'from' => $event->suspends_from === null ? null : substr($event->suspends_from, 0, 5),
                    'until' => $event->suspends_until === null ? null : substr($event->suspends_until, 0, 5),
                    'classes' => count($caught),
                ];
            }
        }

        return $upcoming;
    }
}
