<?php

namespace App\Registration;

use App\Enums\DropReason;
use App\Enums\EnrollmentStatus;
use App\Enums\RegistrationStatus;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\TermRegistration;
use App\Models\User;
use App\Scheduling\LearnerConflicts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The dean's side of a registration: which section a learner is in, which
 * subjects they take and in which batch. Subjects with recorded attendance
 * are never deleted: leaving them marks them dropped, confirmed by the dean.
 */
class Placement
{
    public function __construct(private TermRegistrar $registrar, private LearnerConflicts $conflicts) {}

    /**
     * Put a learner in a section (from the unsectioned queue, or moving
     * them). A regular learner swaps the old section's subjects for the new
     * one's; an irregular learner keeps their subjects. Returns a warning
     * when the section is now past its capacity.
     */
    public function place(TermRegistration $registration, Section $section, User $dean): ?string
    {
        if ($registration->section_id === $section->id) {
            return null;
        }

        DB::transaction(function () use ($registration, $section, $dean): void {
            if (! $registration->is_irregular && $registration->section_id !== null) {
                Enrollment::query()
                    ->where('learner_id', $registration->learner_id)
                    ->where('status', '!=', EnrollmentStatus::Dropped)
                    ->whereHas('subjectOffering', fn ($query) => $query->where('section_id', $registration->section_id))
                    ->get()
                    ->each(fn (Enrollment $enrollment) => $this->leave($enrollment, $dean));
            }

            $registration->update(['section_id' => $section->id]);

            if (! $registration->is_irregular) {
                $this->registrar->enrollInSection($registration);
            }
        });

        $members = $section->termRegistrations()->where('status', RegistrationStatus::Registered)->count();

        if ($section->capacity !== null && $members > $section->capacity) {
            return __(':section now has :count learners, past its capacity of :capacity.', ['section' => "{$section->program->code} {$section->name}", 'count' => $members, 'capacity' => $section->capacity]);
        }

        return null;
    }

    /**
     * Enroll the learner in one subject (any section's, of their college),
     * optionally in one of its batches, refused if it clashes with their
     * week. A regular learner becomes irregular: their subjects no longer
     * follow the section.
     */
    public function enroll(TermRegistration $registration, SubjectOffering $offering, ?Batch $batch, User $dean): void
    {
        $existing = Enrollment::where('learner_id', $registration->learner_id)->where('subject_offering_id', $offering->id)->first();

        if ($existing !== null && $existing->status !== EnrollmentStatus::Dropped) {
            throw ValidationException::withMessages(['subject_offering_id' => __(':name already takes this subject.', ['name' => $registration->learner->user->full_name])]);
        }

        $this->refuseConflicts($registration, [$offering->id => $batch?->id], 'subject_offering_id');

        DB::transaction(function () use ($registration, $offering, $batch, $existing): void {
            if ($existing !== null) {
                // Taken again after being dropped: the same enrollment, with its history.
                $existing->forceFill([
                    'status' => EnrollmentStatus::Enrolled,
                    'batch_id' => $batch?->id,
                    'flagged_for_drop_at' => null,
                    'drop_confirmed_at' => null,
                    'drop_confirmed_by' => null,
                    'drop_reason' => null,
                ])->save();
            } else {
                Enrollment::create([
                    'learner_id' => $registration->learner_id,
                    'subject_offering_id' => $offering->id,
                    'batch_id' => $batch?->id,
                    'status' => EnrollmentStatus::Enrolled,
                ]);
            }

            $registration->update(['is_irregular' => true]);
        });
    }

    /**
     * Take a subject off the learner's load; the learner becomes irregular.
     */
    public function remove(TermRegistration $registration, Enrollment $enrollment, User $dean): void
    {
        DB::transaction(function () use ($registration, $enrollment, $dean): void {
            $this->leave($enrollment, $dean);
            $registration->update(['is_irregular' => true]);
        });
    }

    /**
     * Set batches (enrollment id => batch id or null), refused as a whole if
     * any learner would end up in two classes at once.
     *
     * @param  array<int, int|null>  $batches
     */
    public function assignBatches(array $batches): void
    {
        $enrollments = Enrollment::with(['learner.user', 'subjectOffering.section'])->findMany(array_keys($batches));
        $messages = [];

        foreach ($enrollments->groupBy('learner_id') as $own) {
            /** @var Enrollment $first */
            $first = $own->first();
            $changes = $own
                ->filter(fn (Enrollment $enrollment): bool => $enrollment->batch_id !== $batches[$enrollment->id])
                ->mapWithKeys(fn (Enrollment $enrollment): array => [$enrollment->subject_offering_id => $batches[$enrollment->id]])
                ->all();

            if ($changes !== []) {
                $messages = [...$messages, ...$this->conflicts->check($first->learner, $first->subjectOffering->section->term_id, $changes)];
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages(self::keyed('batches', $messages));
        }

        DB::transaction(function () use ($enrollments, $batches): void {
            foreach ($enrollments as $enrollment) {
                $enrollment->update(['batch_id' => $batches[$enrollment->id]]);
            }
        });
    }

    /**
     * Leave a subject: removed outright, or marked dropped once attendance
     * has been recorded for it.
     */
    private function leave(Enrollment $enrollment, User $dean): void
    {
        if (! $enrollment->attendanceRecords()->exists()) {
            $enrollment->delete();

            return;
        }

        $enrollment->forceFill([
            'status' => EnrollmentStatus::Dropped,
            'drop_confirmed_at' => now(),
            'drop_confirmed_by' => $dean->id,
            'drop_reason' => DropReason::Removed,
        ])->save();
    }

    /**
     * @param  array<int, int|null>  $changes  offering id => batch id
     */
    private function refuseConflicts(TermRegistration $registration, array $changes, string $key): void
    {
        $messages = $this->conflicts->check($registration->learner, $registration->term_id, $changes);

        if ($messages !== []) {
            throw ValidationException::withMessages(self::keyed($key, $messages));
        }
    }

    /**
     * One error per key, since Inertia passes only the first error of each.
     *
     * @param  list<string>  $messages
     * @return array<string, string>
     */
    private static function keyed(string $key, array $messages): array
    {
        return array_combine(array_map(fn (int $i): string => "{$key}.{$i}", array_keys($messages)), $messages);
    }
}
