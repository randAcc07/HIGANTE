<?php

namespace App\Attendance;

use App\Models\CalendarEvent;
use App\Models\Schedule;
use App\Models\Term;
use Carbon\CarbonImmutable;

/**
 * When a weekly class actually meets: on its weekday, inside its term, and
 * not suspended by the campus or its college. A suspension covers whole
 * days, or only the classes that start inside its window ("from 1:00 PM"
 * leaves a lab that began at noon held). Attendance can be taken for a
 * meeting once its day has come (today or earlier).
 */
class ClassDays
{
    /**
     * Suspensions by "termId:collegeId": date => list of [title, from, until]
     * ("HH:MM", null = open end; both null = the whole day).
     *
     * @var array<string, array<string, list<array{string, string|null, string|null}>>>
     */
    private array $suspensions = [];

    /**
     * Why attendance can't be taken for the class on that date, or null when
     * it can.
     */
    public function refusal(Schedule $schedule, CarbonImmutable $date): ?string
    {
        $schedule->loadMissing(['term', 'subjectOffering.section.program']);
        $term = $schedule->term;

        if ($date->dayOfWeekIso !== $schedule->weekday()) {
            return __('This class meets on :day, not on :date.', ['day' => $schedule->starts_at->format('l'), 'date' => $date->format('l, M j')]);
        }

        if ($date->lt($term->starts_on) || $date->gt($term->ends_on)) {
            return __(':date is outside :term.', ['date' => $date->format('M j, Y'), 'term' => $term->label]);
        }

        $suspension = $this->suspension($schedule, $date);

        if ($suspension !== null) {
            return __('Classes are suspended on :date (:event).', ['date' => $date->format('M j'), 'event' => $suspension]);
        }

        if ($date->gt(CarbonImmutable::today())) {
            return __('This class hasn\'t met yet; attendance opens on the day.');
        }

        return null;
    }

    /**
     * Whether the class meets on that date (past or future): its weekday,
     * inside its term, and not suspended.
     */
    public function meets(Schedule $schedule, CarbonImmutable $date): bool
    {
        $schedule->loadMissing('term');

        return $date->dayOfWeekIso === $schedule->weekday()
            && $date->between($schedule->term->starts_on, $schedule->term->ends_on)
            && $this->suspension($schedule, $date) === null;
    }

    /**
     * The class's meeting dates in its term up to today, latest first.
     *
     * @return list<CarbonImmutable>
     */
    public function pastDates(Schedule $schedule): array
    {
        $schedule->loadMissing(['term', 'subjectOffering.section.program']);
        $term = $schedule->term;
        $last = CarbonImmutable::today()->min($term->ends_on);
        $dates = [];

        $day = $term->starts_on->addDays(($schedule->weekday() - $term->starts_on->dayOfWeekIso + 7) % 7);

        for (; $day->lte($last); $day = $day->addWeek()) {
            if ($this->suspension($schedule, $day) === null) {
                $dates[] = $day;
            }
        }

        return array_reverse($dates);
    }

    /**
     * The suspension that cancels the class on that date, by its title, or
     * null when the class meets (as far as suspensions go).
     */
    public function suspension(Schedule $schedule, CarbonImmutable $date): ?string
    {
        $schedule->loadMissing(['term', 'subjectOffering.section.program']);
        $start = $schedule->starts_at->format('H:i');

        foreach ($this->suspendedOn($schedule->term, $schedule->subjectOffering->section->program->college_id)[$date->toDateString()] ?? [] as [$title, $from, $until]) {
            if (($from === null || $start >= $from) && ($until === null || $start < $until)) {
                return $title;
            }
        }

        return null;
    }

    /**
     * Campus-wide and college suspensions within the term: date => list of
     * [title, from, until].
     *
     * @return array<string, list<array{string, string|null, string|null}>>
     */
    public function suspendedOn(Term $term, int $collegeId): array
    {
        $key = "{$term->id}:{$collegeId}";

        if (isset($this->suspensions[$key])) {
            return $this->suspensions[$key];
        }

        $dates = [];
        $events = CalendarEvent::query()
            ->where('suspends_classes', true)
            ->where(fn ($query) => $query->whereNull('college_id')->orWhere('college_id', $collegeId))
            ->where('starts_on', '<=', $term->ends_on->toDateString())
            ->where('ends_on', '>=', $term->starts_on->toDateString())
            ->get();

        foreach ($events as $event) {
            $window = [$event->title, self::hhmm($event->suspends_from), self::hhmm($event->suspends_until)];

            for ($day = $event->starts_on; $day->lte($event->ends_on); $day = $day->addDay()) {
                $dates[$day->toDateString()][] = $window;
            }
        }

        return $this->suspensions[$key] = $dates;
    }

    /**
     * "13:00:00" → "13:00".
     */
    private static function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
