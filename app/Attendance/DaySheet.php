<?php

namespace App\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\ClassCover;
use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The classes that meet on one date (from a set of schedules), each with
 * how many were marked late or absent (everyone else counts as present):
 * the teacher's day, or the dean's.
 */
class DaySheet
{
    public function __construct(private ClassDays $days, private ClassList $list) {}

    /**
     * @param  Builder<Schedule>  $schedules
     * @param  int|null  $viewer  the teacher looking, to flag classes they cover
     * @return list<array<string, mixed>>
     */
    public function rows(Builder $schedules, CarbonImmutable $date, ?int $viewer = null): array
    {
        $classes = $schedules
            ->with(['subjectOffering.subject:id,code,title', 'subjectOffering.section.program:id,college_id,code', 'batch:id,name', 'room', 'teacher.user', 'term'])
            ->get()
            ->filter(fn (Schedule $schedule): bool => $schedule->weekday() === $date->dayOfWeekIso)
            ->sortBy(fn (Schedule $schedule): string => $schedule->starts_at->format('H:i').$schedule->subjectOffering->subject->code)
            ->values();

        // Exceptions per class: [schedule id][status] => count.
        $marked = AttendanceRecord::query()
            ->whereIn('schedule_id', $classes->modelKeys())
            ->where('class_date', $date->toDateString())
            ->where('status', '!=', AttendanceStatus::Present)
            ->selectRaw('schedule_id, status, count(*) as total')
            ->groupBy('schedule_id', 'status')
            ->get()
            ->groupBy('schedule_id');

        $covers = ClassCover::with('substitute.user')
            ->whereIn('schedule_id', $classes->modelKeys())
            ->onDate($date)
            ->get()
            ->keyBy('schedule_id');

        return array_values($classes->map(function (Schedule $schedule) use ($date, $marked, $covers, $viewer): array {
            $offering = $schedule->subjectOffering;
            $refusal = $this->days->refusal($schedule, $date);

            return [
                'schedule_id' => $schedule->id,
                'subject' => $offering->subject->code,
                'title' => $offering->subject->title,
                'section' => "{$offering->section->program->code} {$offering->section->name}",
                'batch' => $schedule->batch?->name,
                'room' => $schedule->room?->label,
                'teacher' => $schedule->teacher->user->full_name,
                // The substitute covering it that day, if any, and whether that's the viewer.
                'cover' => $covers->has($schedule->id) ? $covers[$schedule->id]->substitute->user->full_name : null,
                'covering' => $viewer !== null && $schedule->teacher_id !== $viewer,
                'start' => $schedule->starts_at->format('H:i'),
                'end' => $schedule->ends_at->format('H:i'),
                // Why it can't be taken that day (suspended, not yet), if so.
                'refusal' => $refusal,
                'listed' => $refusal === null ? $this->list->attendees($schedule, $date)->count() : 0,
                'late' => (int) ($marked->get($schedule->id)?->firstWhere('status', AttendanceStatus::Late)?->getAttribute('total') ?? 0),
                'absent' => (int) ($marked->get($schedule->id)?->firstWhere('status', AttendanceStatus::Absent)?->getAttribute('total') ?? 0),
            ];
        })->all());
    }
}
