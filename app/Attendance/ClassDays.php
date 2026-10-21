<?php

namespace App\Attendance;

use App\Models\CalendarEvent;
use App\Models\Schedule;
use App\Models\Term;
use Carbon\CarbonImmutable;

/**
 * When a weekly class actually meets: on its weekday, inside its term, and
 * not on a day the campus or its college suspended classes. Attendance can
 * be taken for such a day once it has come (today or earlier).
 */
class ClassDays
{
    /** @var array<string, array<string, string>> Suspended dates by "termId:collegeId": date => event title. */
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

        $suspension = $this->suspendedOn($term, $schedule->subjectOffering->section->program->college_id)[$date->toDateString()] ?? null;

        if ($suspension !== null) {
            return __('Classes are suspended on :date (:event).', ['date' => $date->format('M j'), 'event' => $suspension]);
        }

        if ($date->gt(CarbonImmutable::today())) {
            return __('This class hasn\'t met yet; attendance opens on the day.');
        }

        return null;
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
        $suspended = $this->suspendedOn($term, $schedule->subjectOffering->section->program->college_id);
        $last = CarbonImmutable::today()->min($term->ends_on);
        $dates = [];

        $day = $term->starts_on->addDays(($schedule->weekday() - $term->starts_on->dayOfWeekIso + 7) % 7);

        for (; $day->lte($last); $day = $day->addWeek()) {
            if (! isset($suspended[$day->toDateString()])) {
                $dates[] = $day;
            }
        }

        return array_reverse($dates);
    }

    /**
     * Campus-wide and college suspensions within the term: date => title.
     *
     * @return array<string, string>
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
            for ($day = $event->starts_on; $day->lte($event->ends_on); $day = $day->addDay()) {
                $dates[$day->toDateString()] = $event->title;
            }
        }

        return $this->suspensions[$key] = $dates;
    }
}
