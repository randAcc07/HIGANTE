<?php

namespace App\Registration;

use App\Enums\DropReason;
use App\Enums\EnrollmentStatus;
use App\Enums\LearnerStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Semester;
use App\Enums\TermPhase;
use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\Learner;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers learners for a term the way the registrar's office does: the
 * registrar's approval is the enrollment. A new registration is placed in a
 * section automatically and, unless irregular, enrolled in every subject of
 * that section.
 */
class TermRegistrar
{
    /**
     * Registration changes while the term has not ended (late enrollment
     * included); an ended or closed term's registrations are history.
     */
    public static function isOpen(Term $term): bool
    {
        return in_array($term->phase(), [TermPhase::Upcoming, TermPhase::InSession], true);
    }

    /**
     * The terms whose registrations the list for this term is drawn from:
     * for a 1st semester, last school year's terms; for a 2nd semester, the
     * same school year's 1st semester; for a summer, the same school year's
     * earlier terms.
     *
     * @return Builder<Term>
     */
    public static function sourceTerms(Term $term): Builder
    {
        return match ($term->semester) {
            Semester::First => Term::where('school_year', self::previousSchoolYear($term->school_year)),
            Semester::Second => Term::where('school_year', $term->school_year)->where('semester', Semester::First),
            Semester::Summer => Term::where('school_year', $term->school_year)->where('starts_on', '<', $term->starts_on->toDateString()),
        };
    }

    /**
     * "2026-2027" → "2025-2026".
     */
    public static function previousSchoolYear(string $schoolYear): string
    {
        [$from, $to] = array_map('intval', explode('-', $schoolYear));

        return ($from - 1).'-'.($to - 1);
    }

    /**
     * The year level to propose for a learner, from their registration in
     * the source terms: one up at a new school year's 1st semester (at most
     * 6), else unchanged. Without one, the learner record's year level.
     */
    public static function proposedYearLevel(Term $term, Learner $learner, ?TermRegistration $source): int
    {
        if ($source === null) {
            return $learner->year_level;
        }

        return $term->semester === Semester::First ? min($source->year_level + 1, 6) : $source->year_level;
    }

    /**
     * Register one learner. The caller authorizes and runs this inside a
     * transaction (it locks the candidate sections).
     *
     * @return array{registration: TermRegistration, warning: string|null}
     */
    public function register(Learner $learner, Term $term, int $yearLevel, bool $irregular, User $registrar): array
    {
        if ($learner->status !== LearnerStatus::Active || ! $learner->user->is_active) {
            throw ValidationException::withMessages(['learner' => __(':name is not an active learner.', ['name' => $learner->user->full_name])]);
        }

        if ($learner->termRegistrations()->where('term_id', $term->id)->exists()) {
            throw ValidationException::withMessages(['learner' => __(':name is already registered for this term.', ['name' => $learner->user->full_name])]);
        }

        [$section, $warning] = $this->placement($learner, $term, $yearLevel);

        $registration = $learner->termRegistrations()->create([
            'term_id' => $term->id,
            'year_level' => $yearLevel,
            'section_id' => $section?->id,
            'is_irregular' => $irregular,
            'status' => RegistrationStatus::Registered,
            'registered_by' => $registrar->id,
        ]);

        if (! $irregular && $section !== null) {
            $this->enrollInSection($registration);
        }

        $this->syncYearLevel($learner);

        return ['registration' => $registration, 'warning' => $warning];
    }

    /**
     * Enroll the learner in every offering of their section they are not
     * yet enrolled in (batches are the dean's to assign).
     */
    public function enrollInSection(TermRegistration $registration): void
    {
        if ($registration->section_id === null) {
            return;
        }

        $offeringIds = SubjectOffering::where('section_id', $registration->section_id)
            ->whereDoesntHave('enrollments', fn ($query) => $query->where('learner_id', $registration->learner_id))
            ->pluck('id');

        self::insertEnrollments($offeringIds->map(fn (int $offeringId): array => [$registration->learner_id, $offeringId]));
    }

    /**
     * Enroll the section's regular learners in an offering the dean added
     * after they registered.
     */
    public function enrollSectionIn(SubjectOffering $offering): void
    {
        $learnerIds = TermRegistration::where('section_id', $offering->section_id)
            ->where('status', RegistrationStatus::Registered)
            ->where('is_irregular', false)
            ->whereNotIn('learner_id', $offering->enrollments()->select('learner_id'))
            ->pluck('learner_id');

        self::insertEnrollments($learnerIds->map(fn (int $learnerId): array => [$learnerId, $offering->id]));
    }

    /**
     * The learner leaves for the rest of the term: the registration stays
     * as withdrawn and every class they still have is dropped.
     */
    public function withdraw(TermRegistration $registration, User $registrar): void
    {
        DB::transaction(function () use ($registration, $registrar): void {
            $registration->update(['status' => RegistrationStatus::Withdrawn]);

            $this->termEnrollments($registration)
                ->where('status', '!=', EnrollmentStatus::Dropped)
                ->update([
                    'status' => EnrollmentStatus::Dropped,
                    'drop_confirmed_at' => now(),
                    'drop_confirmed_by' => $registrar->id,
                    'drop_reason' => DropReason::Withdrawn,
                ]);
        });
    }

    /**
     * Whether any of the learner's classes this term has attendance.
     */
    public function hasAttendance(TermRegistration $registration): bool
    {
        return AttendanceRecord::whereIn('enrollment_id', $this->termEnrollments($registration)->select('id'))->exists();
    }

    /**
     * Take back a registration made by mistake, with its enrollments. Only
     * while nothing has been recorded; afterwards it can only be withdrawn.
     */
    public function undo(TermRegistration $registration): void
    {
        DB::transaction(function () use ($registration): void {
            if ($this->hasAttendance($registration)) {
                throw ValidationException::withMessages(['registration' => __('Attendance has been recorded for this learner, so the registration can only be withdrawn.')]);
            }

            $this->termEnrollments($registration)->delete();
            $registration->delete();
            $this->syncYearLevel($registration->learner);
        });
    }

    /**
     * The section a new registration goes to, and a warning when it puts the
     * section past its capacity. The block stays together: the section
     * copied from the learner's section in the source terms (any term), or
     * in a 2nd semester the one named like it (sections made by hand), even
     * past capacity. Otherwise the emptiest section of the program and year
     * level with room (ties go to the first by name). Null when all are
     * full: the dean places them.
     *
     * @return array{Section|null, string|null}
     */
    private function placement(Learner $learner, Term $term, int $yearLevel): array
    {
        /** @var Collection<int, Section> $sections */
        $sections = Section::where('term_id', $term->id)
            ->where('program_id', $learner->program_id)
            ->where('year_level', $yearLevel)
            ->orderBy('name')
            ->lockForUpdate()
            ->get();

        if ($sections->isEmpty()) {
            return [null, null];
        }

        $sizes = TermRegistration::whereIn('section_id', $sections->pluck('id'))
            ->where('status', RegistrationStatus::Registered)
            ->selectRaw('section_id, count(*) as members')
            ->groupBy('section_id')
            ->pluck('members', 'section_id');
        $size = fn (Section $section): int => (int) ($sizes[$section->id] ?? 0);

        // The learner's latest sectioned registration in the source terms.
        $previous = $learner->termRegistrations()
            ->with('section:id,name')
            ->join('terms', 'terms.id', '=', 'term_registrations.term_id')
            ->whereIn('term_registrations.term_id', self::sourceTerms($term)->select('id'))
            ->whereNotNull('term_registrations.section_id')
            ->orderByDesc('terms.starts_on')
            ->select('term_registrations.*')
            ->first();
        $same = $previous?->section === null ? null : (
            $sections->firstWhere('previous_section_id', $previous->section->id)
            ?? ($term->semester === Semester::Second ? $sections->first(fn (Section $section): bool => $section->previous_section_id === null && $section->name === $previous->section->name) : null)
        );

        if ($same !== null && $same->capacity !== null && $size($same) >= $same->capacity) {
            return [$same, __(':section is now over its capacity of :capacity.', ['section' => "{$learner->program->code} {$same->name}", 'capacity' => $same->capacity])];
        }

        if ($same !== null) {
            return [$same, null];
        }

        $emptiest = null;

        foreach ($sections as $section) {
            $hasRoom = $section->capacity === null || $size($section) < $section->capacity;

            if ($hasRoom && ($emptiest === null || $size($section) < $size($emptiest))) {
                $emptiest = $section;
            }
        }

        return [$emptiest, null];
    }

    /**
     * The learner record's year level follows their latest registration.
     */
    private function syncYearLevel(Learner $learner): void
    {
        $latest = $learner->termRegistrations()
            ->join('terms', 'terms.id', '=', 'term_registrations.term_id')
            ->orderByDesc('terms.starts_on')
            ->value('term_registrations.year_level');

        if ($latest !== null && (int) $latest !== $learner->year_level) {
            $learner->update(['year_level' => (int) $latest]);
        }
    }

    /**
     * The learner's enrollments in the registration's term.
     *
     * @return Builder<Enrollment>
     */
    private function termEnrollments(TermRegistration $registration): Builder
    {
        return Enrollment::where('learner_id', $registration->learner_id)
            ->whereHas('subjectOffering.section', fn ($query) => $query->where('term_id', $registration->term_id));
    }

    /**
     * @param  SupportCollection<int, array{int, int}>  $pairs  [learner id, offering id]
     */
    private static function insertEnrollments(SupportCollection $pairs): void
    {
        $now = now();

        foreach ($pairs->chunk(500) as $chunk) {
            Enrollment::insert($chunk->map(fn (array $pair): array => [
                'learner_id' => $pair[0],
                'subject_offering_id' => $pair[1],
                'status' => EnrollmentStatus::Enrolled->value,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all());
        }
    }
}
