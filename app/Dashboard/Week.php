<?php

namespace App\Dashboard;

use App\Attendance\ClassDays;
use App\Http\Controllers\Dean\ScheduleBlocks;
use App\Models\CalendarEvent;
use App\Models\ClassCover;
use App\Models\Schedule;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * One dated week (Mon–Sat) of classes for a dashboard: each class on its
 * date, struck through when a holiday or suspension cancels it, with who
 * covers it that day; and each day's calendar events.
 *
 * Inject per action (ClassDays caches suspensions).
 */
final class Week
{
    public function __construct(private readonly ClassDays $days) {}

    /**
     * The week to show: the one asked for (any date in it), else this week
     * while the term is on, its first week before it starts, its last
     * after it ends. Always a Monday.
     */
    public static function monday(?string $asked, ?Term $term): CarbonImmutable
    {
        $today = CarbonImmutable::today();
        $date = $asked !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asked) === 1 ? CarbonImmutable::parse($asked) : null;
        $date ??= $term === null ? $today : $today->max($term->starts_on)->min($term->ends_on);

        return $date->startOfWeek(CarbonImmutable::MONDAY);
    }

    /**
     * @param  Collection<int, Schedule>  $schedules  with subject, section, batch, room, teacher and term loaded
     * @param  int|null  $collegeId  whose calendar events to show (campus-wide ones always)
     * @param  int|null  $viewer  the teacher looking, to flag classes they cover
     * @return array{monday: string, previous: string|null, next: string|null, days: list<array{weekday: int, date: string, events: list<array{title: string, suspends: bool}>}>, blocks: list<array<string, mixed>>}
     */
    public function build(Collection $schedules, CarbonImmutable $monday, ?Term $term, ?int $collegeId, ?int $viewer = null): array
    {
        $saturday = $monday->addDays(5);
        $covers = ClassCover::with('substitute.user')
            ->whereIn('schedule_id', $schedules->modelKeys())
            ->where('starts_on', '<=', $saturday->toDateString())
            ->where('ends_on', '>=', $monday->toDateString())
            ->get()
            ->groupBy('schedule_id');
        $events = CalendarEvent::query()
            ->where(fn ($query) => $query->whereNull('college_id')->when($collegeId, fn ($query, int $id) => $query->orWhere('college_id', $id)))
            ->where('starts_on', '<=', $saturday->toDateString())
            ->where('ends_on', '>=', $monday->toDateString())
            ->orderBy('starts_on')
            ->get();

        $days = [];
        $blocks = [];

        for ($weekday = 1; $weekday <= 6; $weekday++) {
            $date = $monday->addDays($weekday - 1);
            $days[] = [
                'weekday' => $weekday,
                'date' => $date->toDateString(),
                'events' => array_values($events
                    ->filter(fn (CalendarEvent $event): bool => $date->between($event->starts_on, $event->ends_on))
                    ->map(fn (CalendarEvent $event): array => ['title' => $event->title, 'suspends' => $event->suspends_classes])
                    ->all()),
            ];

            foreach ($schedules as $schedule) {
                if ($schedule->weekday() !== $weekday || ! $date->between($schedule->term->starts_on, $schedule->term->ends_on)) {
                    continue;
                }

                $cover = $covers->get($schedule->id)?->first(fn (ClassCover $cover): bool => $date->between($cover->starts_on, $cover->ends_on));
                $covering = $viewer !== null && $schedule->teacher_id !== $viewer;

                // A class the viewer covers shows only on the days they cover it.
                if ($covering && $cover?->substitute_id !== $viewer) {
                    continue;
                }

                $blocks[] = [
                    ...ScheduleBlocks::block($schedule),
                    'program' => $schedule->subjectOffering->section->program->code,
                    'date' => $date->toDateString(),
                    // The holiday or suspension that cancels it, if any.
                    'cancelled' => $this->days->suspension($schedule, $date),
                    'cover' => $cover?->substitute->user->full_name,
                    'covering' => $covering,
                ];
            }
        }

        $first = $term?->starts_on->startOfWeek(CarbonImmutable::MONDAY);
        $last = $term?->ends_on->startOfWeek(CarbonImmutable::MONDAY);

        return [
            'monday' => $monday->toDateString(),
            // Stepping stays within the term.
            'previous' => $first === null || $monday->lte($first) ? null : $monday->subWeek()->toDateString(),
            'next' => $last === null || $monday->gte($last) ? null : $monday->addWeek()->toDateString(),
            'days' => $days,
            'blocks' => $blocks,
        ];
    }
}
