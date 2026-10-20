<?php

namespace App\Scheduling;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Learner;
use App\Models\Schedule;
use Illuminate\Support\Collection;

/**
 * Clashes in one learner's own week. A learner attends a subject's
 * whole-class meetings plus those of their batch, so membership is set per
 * subject: 2-AA in Physics and 2-AB in Chemistry can land them in two labs
 * at once, which section-level scheduling cannot see.
 */
class LearnerConflicts
{
    /**
     * The classes a learner attends in a term, given their subjects
     * (offering id => batch id), with what the messages need.
     *
     * @param  array<int, int|null>  $subjects
     * @return Collection<int, Schedule>
     */
    public function classes(array $subjects): Collection
    {
        if ($subjects === []) {
            return collect();
        }

        return Schedule::query()
            ->with(['batch', 'room', 'teacher.user', 'subjectOffering.subject', 'subjectOffering.section.program'])
            ->whereIn('subject_offering_id', array_keys($subjects))
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Schedule $schedule): bool => $schedule->batch_id === null || $schedule->batch_id === $subjects[$schedule->subject_offering_id])
            ->values();
    }

    /**
     * The learner's current subjects in a term: offering id => batch id.
     *
     * @return array<int, int|null>
     */
    public function subjects(Learner $learner, int $termId): array
    {
        return Enrollment::query()
            ->where('learner_id', $learner->id)
            ->where('status', '!=', EnrollmentStatus::Dropped)
            ->whereHas('subjectOffering.section', fn ($query) => $query->where('term_id', $termId))
            ->pluck('batch_id', 'subject_offering_id')
            ->all();
    }

    /**
     * What a change to the learner's subjects (offering id => batch id, for
     * subjects added or re-batched) would clash with. Clashes among the
     * subjects left alone are not this change's doing, so they are not
     * reported.
     *
     * @param  array<int, int|null>  $changes
     * @return list<string>
     */
    public function check(Learner $learner, int $termId, array $changes): array
    {
        $classes = $this->classes(array_replace($this->subjects($learner, $termId), $changes));
        $messages = [];

        foreach ($classes as $i => $a) {
            foreach ($classes->slice($i + 1) as $b) {
                $touched = array_key_exists($a->subject_offering_id, $changes) || array_key_exists($b->subject_offering_id, $changes);

                if ($touched && $a->subject_offering_id !== $b->subject_offering_id && self::overlap($a, $b)) {
                    $messages[] = __(':name would have :first and :second at the same time, :when.', [
                        'name' => $learner->user->full_name,
                        'first' => self::label($a),
                        'second' => self::label($b),
                        'when' => ScheduleConflicts::when($a),
                    ]);
                }
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * Learners in a class who have another class at the same time (an
     * irregular learner's subject from another section, or mixed batches).
     * Saving the class still goes ahead; this only warns.
     *
     * @return list<string>
     */
    public function clashesWith(Schedule $schedule): array
    {
        $attendees = Enrollment::query()
            ->where('subject_offering_id', $schedule->subject_offering_id)
            ->where('status', '!=', EnrollmentStatus::Dropped)
            ->when($schedule->batch_id !== null, fn ($query) => $query->where('batch_id', $schedule->batch_id))
            ->pluck('learner_id');

        if ($attendees->isEmpty()) {
            return [];
        }

        $overlapping = Schedule::query()
            ->where('term_id', $schedule->term_id)
            ->where('subject_offering_id', '!=', $schedule->subject_offering_id)
            ->where('starts_at', '<', $schedule->ends_at)
            ->where('ends_at', '>', $schedule->starts_at)
            ->get(['subject_offering_id', 'batch_id']);

        if ($overlapping->isEmpty()) {
            return [];
        }

        $clashing = Enrollment::query()
            ->with('learner.user')
            ->whereIn('learner_id', $attendees)
            ->whereIn('subject_offering_id', $overlapping->pluck('subject_offering_id'))
            ->where('status', '!=', EnrollmentStatus::Dropped)
            ->get()
            ->filter(fn (Enrollment $enrollment): bool => $overlapping->contains(fn (Schedule $other): bool => $other->subject_offering_id === $enrollment->subject_offering_id
                && ($other->batch_id === null || $other->batch_id === $enrollment->batch_id)))
            ->map(fn (Enrollment $enrollment): string => $enrollment->learner->user->full_name)
            ->unique()
            ->sort()
            ->values();

        if ($clashing->isEmpty()) {
            return [];
        }

        $names = $clashing->take(3)->implode(', ').($clashing->count() > 3 ? __(' and :count more', ['count' => $clashing->count() - 3]) : '');

        return [trans_choice('{1} :names has another class at that time.|[2,*] :count learners have another class at that time: :names.', $clashing->count(), ['count' => $clashing->count(), 'names' => $names])];
    }

    public static function overlap(Schedule $a, Schedule $b): bool
    {
        return $a->starts_at->lt($b->ends_at) && $b->starts_at->lt($a->ends_at);
    }

    /**
     * "CS 201 (2-AA) of BSCS 2-A".
     */
    public static function label(Schedule $schedule): string
    {
        $offering = $schedule->subjectOffering;

        return $offering->subject->code.($schedule->batch ? " ({$schedule->batch->name})" : '')
            .' of '.$offering->section->program->code.' '.$offering->section->name;
    }
}
