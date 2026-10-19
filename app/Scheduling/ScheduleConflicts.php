<?php

namespace App\Scheduling;

use App\Enums\DutyStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Checks a schedule (saved or not) against the rest of its term, in words a
 * dean can act on. Conflicts block saving; warnings only inform.
 *
 * The database rejects room and teacher overlaps by itself (WITHOUT
 * OVERLAPS); this explains them, and adds the section check the database
 * cannot make. Two classes of one section clash when they overlap, unless
 * both are for batches with different names: a section's batches go by name
 * across its subjects (2-AA, 2-AB), so 2-AA's Physics lab may run while 2-AB
 * has Chemistry. Two classes of the same batch name are the same learners
 * and clash. Since membership is set per subject, enrollment (feature 8)
 * must check each learner's own week as well.
 */
class ScheduleConflicts
{
    /**
     * Only the teacher's other classes: for changing who teaches a class,
     * which cannot cause a room or section clash.
     *
     * @return list<string>
     */
    public function teacherConflicts(Schedule $schedule): array
    {
        $messages = [];

        foreach ($this->overlapping($schedule) as $other) {
            if ($other->teacher_id === $schedule->teacher_id) {
                $messages[] = __(':teacher already teaches :what :when.', ['teacher' => $other->teacher->user->full_name, 'what' => self::describe($other, $schedule), 'when' => self::when($other)]);
            }
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    public function conflicts(Schedule $schedule): array
    {
        $schedule->loadMissing('subjectOffering');
        $sectionId = $schedule->subjectOffering->section_id;
        $messages = [];

        foreach ($this->overlapping($schedule) as $other) {
            $when = self::when($other);
            $what = self::describe($other, $schedule);

            if ($schedule->room_id !== null && $other->room_id === $schedule->room_id) {
                $messages[] = __(':room is already booked :when by :what.', ['room' => $other->room?->label, 'when' => $when, 'what' => $what]);
            }

            if ($other->teacher_id === $schedule->teacher_id) {
                $messages[] = __(':teacher already teaches :what :when.', ['teacher' => $other->teacher->user->full_name, 'what' => $what, 'when' => $when]);
            }

            if ($other->subjectOffering->section_id === $sectionId && ! self::parallelBatches($schedule, $other)) {
                $messages[] = __('The section already has :what :when.', ['what' => $what, 'when' => $when]);
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * @return list<string>
     */
    public function warnings(Schedule $schedule): array
    {
        $schedule->loadMissing(['room', 'teacher.user', 'batch', 'subjectOffering.section']);
        $warnings = [];

        if ($schedule->teacher->duty_status === DutyStatus::OnLeave) {
            $warnings[] = __(':teacher is on leave.', ['teacher' => $schedule->teacher->user->full_name]);
        }

        $capacity = $schedule->room?->capacity;
        $headcount = $this->headcount($schedule);

        if ($schedule->room !== null && $capacity !== null && $headcount > $capacity) {
            $warnings[] = __(':room holds :capacity; this class has :count learners.', [
                'room' => $schedule->room->label,
                'capacity' => $capacity,
                'count' => $headcount,
            ]);
        }

        return $warnings;
    }

    /**
     * How many learners the class seats: the batch's members, else the
     * offering's enrollees, else (before enrollment) the section's registered
     * learners, else its planned capacity.
     */
    public function headcount(Schedule $schedule): int
    {
        $enrolled = fn () => $schedule->subjectOffering->enrollments()->where('status', '!=', EnrollmentStatus::Dropped);

        if ($schedule->batch_id !== null) {
            return $enrolled()->where('batch_id', $schedule->batch_id)->count();
        }

        $section = $schedule->subjectOffering->section;

        return $enrolled()->count()
            ?: $section->termRegistrations()->count()
            ?: ($section->capacity ?? 0);
    }

    /**
     * Same-term schedules overlapping this one's slot, with what the
     * messages need.
     *
     * @return Collection<int, Schedule>
     */
    private function overlapping(Schedule $schedule): Collection
    {
        return Schedule::query()
            ->with(['room', 'teacher.user', 'batch', 'subjectOffering.subject', 'subjectOffering.section.program.college'])
            ->where('term_id', $schedule->term_id)
            ->where('starts_at', '<', $schedule->ends_at)
            ->where('ends_at', '>', $schedule->starts_at)
            ->when($schedule->exists, fn (Builder $query) => $query->whereKeyNot($schedule->id))
            ->get();
    }

    /**
     * Batches with different names are different groups of learners, so
     * their classes may run at the same time.
     */
    private static function parallelBatches(Schedule $a, Schedule $b): bool
    {
        if ($a->batch_id === null || $b->batch_id === null) {
            return false;
        }

        $a->loadMissing('batch');
        $b->loadMissing('batch');

        return $a->batch?->name !== $b->batch?->name;
    }

    /**
     * "BSCS 2-A · CS 201 (2-AA)", with the college when it is another one.
     */
    public static function describe(Schedule $other, Schedule $from): string
    {
        $section = $other->subjectOffering->section;
        $from->loadMissing('subjectOffering.section.program');
        $college = $section->program->college_id === $from->subjectOffering->section->program->college_id
            ? ''
            : $section->program->college->code.' ';

        return $college.$section->program->code.' '.$section->name.' · '.$other->subjectOffering->subject->code
            .($other->batch ? " ({$other->batch->name})" : '');
    }

    /**
     * "Mon 8:00–9:30 AM".
     */
    public static function when(Schedule $schedule): string
    {
        $start = $schedule->starts_at;
        $end = $schedule->ends_at;

        return $start->format('D').' '.$start->format($start->format('A') === $end->format('A') ? 'g:i' : 'g:i A').'–'.$end->format('g:i A');
    }
}
