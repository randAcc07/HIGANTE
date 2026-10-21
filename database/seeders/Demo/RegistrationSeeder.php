<?php

namespace Database\Seeders\Demo;

use App\Enums\EnrollmentStatus;
use App\Enums\LearnerStatus;
use App\Enums\RegistrationStatus;
use App\Enums\UserRole;
use App\Models\Batch;
use App\Models\College;
use App\Models\Learner;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use App\Registration\TermRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admits learners and registers them for the current term the way the
 * registrar will: each goes to the emptiest section of their program and
 * year level that has room, or stays unsectioned. Regular learners are
 * enrolled in every offering of their section; lab offerings split them
 * unevenly into the A/B batches, sized by the lab room, so membership
 * differs from subject to subject.
 *
 * Per program, one second-year and one third-year learner are irregular:
 * they skip one of their section's major subjects and take one subject
 * from the year below, in a batch that fits their timetable. Per college,
 * one second year withdrew mid-term (through the app's own withdrawal),
 * and two graduates, one deactivated learner and one returnee (back after
 * a year away, never registered in the demo terms) are admitted but not
 * registered.
 *
 * Demo logins per college: learner.<college>@ (first year, will carry
 * warnings and excuses), atrisk.<college>@ (first year, will be flagged for
 * dropping), irregular.<college>@ and returnee.<college>@.
 */
class RegistrationSeeder extends Seeder
{
    public const WITHDRAWN_ON = '2026-09-11';

    /** @var array<string, true> */
    private array $studentNumbers = [];

    /**
     * Each offering's weekly periods: [batch id or null, ISO weekday, start, end].
     *
     * @var array<int, list<array{int|null, int, string, string}>>
     */
    private array $periods = [];

    public function __construct(private StaffSeeder $staff) {}

    public static function demoEmail(string $kind, string $collegeCode): string
    {
        return $kind.'.'.Str::lower($collegeCode).'@'.CampusData::EMAIL_DOMAIN;
    }

    public function run(): void
    {
        $term = CampusData::currentTerm();
        $registrar = User::where('role', UserRole::Registrar)->sole();

        foreach (Schedule::where('term_id', $term->id)->get() as $schedule) {
            $this->periods[$schedule->subject_offering_id][] = [
                $schedule->batch_id, $schedule->weekday(), $schedule->starts_at->format('H:i'), $schedule->ends_at->format('H:i'),
            ];
        }

        foreach (College::with('programs')->get() as $college) {
            foreach ($college->programs->values() as $programIndex => $program) {
                foreach (CampusData::YEAR_LEVELS as $yearLevel => $year) {
                    $count = $yearLevel === 1 && in_array($program->code, CampusData::OVERFLOWING_PROGRAMS, true) ? 62 : $year['learners'];
                    $demo = $programIndex === 0 ? match ($yearLevel) {
                        1 => ['learner', 'atrisk'],
                        2 => ['irregular'],
                        default => [],
                    } : [];

                    $this->registerYear($college, $program, $yearLevel, $count, $demo, $term, $registrar, withdrawOne: $programIndex === 1 && $yearLevel === 2);
                }
            }

            $this->unregistered($college, returneeYear: $college->code === 'COE' ? 3 : 2);
        }
    }

    /**
     * @param  list<string>  $demo  Demo login kinds for the year's first learners.
     */
    private function registerYear(College $college, Program $program, int $yearLevel, int $count, array $demo, Term $term, User $registrar, bool $withdrawOne): void
    {
        $sections = Section::where('term_id', $term->id)->where('program_id', $program->id)->where('year_level', $yearLevel)->orderBy('name')->get();
        /** @var array<int, int> $sizes */
        $sizes = $sections->mapWithKeys(fn (Section $section): array => [$section->id => 0])->all();

        /** @var array<int, list<int>> $members Learner ids per section. */
        $members = [];
        $irregulars = [];
        $withdrawn = null;

        for ($i = 0; $i < $count; $i++) {
            $kind = $demo[$i] ?? null;
            $learner = $this->admit($college, $program, $yearLevel, $kind === null ? null : self::demoEmail($kind, $college->code));
            // One irregular per program in years 2 and 3; the demo one where there is one.
            $irregular = in_array($yearLevel, [2, 3], true) && ($kind === 'irregular' || ($i === 0 && $demo === []));

            // Emptiest section with room; ties go to the first section.
            $sectionId = null;

            foreach ($sections as $section) {
                $hasRoom = $section->capacity === null || $sizes[$section->id] < $section->capacity;

                if ($hasRoom && ($sectionId === null || $sizes[$section->id] < $sizes[$sectionId])) {
                    $sectionId = $section->id;
                }
            }

            if ($sectionId !== null) {
                $sizes[$sectionId]++;
                $members[$sectionId][] = $learner->id;
            }

            $registration = new TermRegistration([
                'learner_id' => $learner->id,
                'term_id' => $term->id,
                'year_level' => $yearLevel,
                'section_id' => $sectionId,
                'is_irregular' => $irregular,
                'status' => RegistrationStatus::Registered,
                'registered_by' => $registrar->id,
            ]);
            $registeredAt = CampusData::date('2026-08-03 08:00')->addDays(mt_rand(0, 4))->addMinutes(mt_rand(0, 8 * 60));
            $registration->created_at = $registeredAt;
            $registration->updated_at = $registeredAt;
            $registration->save();

            if ($irregular && $sectionId !== null) {
                $irregulars[$learner->id] = $sectionId;
            }

            if ($withdrawOne && $withdrawn === null && $i === 1) {
                $withdrawn = $registration;
            }
        }

        foreach ($sections as $section) {
            $this->enrollSection($section, $members[$section->id] ?? [], $irregulars, $term);
        }

        if ($withdrawn !== null) {
            $this->withdraw($withdrawn, $registrar);
        }
    }

    /**
     * Withdraws through the app (registration withdrawn, the term's classes
     * dropped and confirmed by the registrar), as of WITHDRAWN_ON.
     */
    private function withdraw(TermRegistration $registration, User $registrar): void
    {
        Clock::at(CampusData::date(self::WITHDRAWN_ON.' 10:00'), fn () => app(TermRegistrar::class)->withdraw($registration, $registrar));
    }

    /**
     * @param  list<int>  $learnerIds
     * @param  array<int, int>  $irregulars  Irregular learner id => home section id.
     */
    private function enrollSection(Section $section, array $learnerIds, array $irregulars, Term $term): void
    {
        $offerings = SubjectOffering::with(['subject', 'batches.schedules.room'])->where('section_id', $section->id)->orderBy('id')->get();
        $skipped = [];

        foreach (array_keys(array_intersect($irregulars, [$section->id])) as $learnerId) {
            $majors = array_values($offerings->filter(fn (SubjectOffering $offering): bool => ! preg_match('/^(GE|PE|NSTP) /', $offering->subject->code))->all());

            if ($majors !== []) {
                $skipped[$learnerId] = FilipinoNames::pick($majors)->id;
            }
        }

        $rows = [];
        $now = CampusData::date('2026-08-07 17:00');

        foreach ($offerings as $offering) {
            $enrolled = array_values(array_filter($learnerIds, fn (int $id): bool => ($skipped[$id] ?? null) !== $offering->id));
            $batchOf = $this->splitIntoBatches($offering, $enrolled);

            foreach ($enrolled as $learnerId) {
                $rows[] = $this->enrollmentRow($learnerId, $offering->id, $batchOf[$learnerId] ?? null, $now);
            }
        }

        // Irregulars also take one subject from the year below.
        foreach (array_keys($skipped) as $learnerId) {
            $taken = $offerings->reject(fn (SubjectOffering $offering): bool => $offering->id === $skipped[$learnerId]);
            $busy = [];

            foreach ($taken as $offering) {
                $batchId = collect($rows)->first(fn (array $row): bool => $row['learner_id'] === $learnerId && $row['subject_offering_id'] === $offering->id)['batch_id'] ?? null;
                $busy = [...$busy, ...$this->periodsFor($offering->id, $batchId)];
            }

            $lower = SubjectOffering::with(['subject', 'batches'])
                ->whereHas('section', fn ($query) => $query->where('term_id', $term->id)->where('program_id', $section->program_id)->where('year_level', $section->year_level - 1))
                ->whereNotNull('teacher_id')
                ->whereNotIn('subject_id', $offerings->pluck('subject_id'))
                ->get()
                ->all();
            // Native shuffle: Laravel's shuffle ignores mt_srand().
            shuffle($lower);

            foreach ($lower as $offering) {
                $choices = $offering->batches->isEmpty() ? [null] : $offering->batches->pluck('id')->all();

                foreach ($choices as $batchId) {
                    if (! self::overlaps($busy, $this->periodsFor($offering->id, $batchId))) {
                        $rows[] = $this->enrollmentRow($learnerId, $offering->id, $batchId, $now);

                        continue 3;
                    }
                }
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('enrollments')->insert($chunk);
        }
    }

    /**
     * Batch A takes about half the class plus a few, batch B the rest, and
     * neither more than its lab room holds. Only when the class outgrows both
     * rooms together does batch B run over (capacity only ever warns).
     *
     * @param  list<int>  $learnerIds
     * @return array<int, int>
     */
    private function splitIntoBatches(SubjectOffering $offering, array $learnerIds): array
    {
        $batches = $offering->batches->sortBy('name')->values();

        if ($batches->count() !== 2) {
            return [];
        }

        $capacityA = self::labCapacity($batches[0]);
        $capacityB = self::labCapacity($batches[1]);
        $count = count($learnerIds);
        shuffle($learnerIds);
        $sizeA = min($capacityA, intdiv($count + 1, 2) + mt_rand(0, 4), $count);
        // If batch B would overflow its room, move learners to A while A has seats.
        $sizeA = max($sizeA, min($capacityA, $count - $capacityB));
        $batchOf = [];

        foreach ($learnerIds as $index => $learnerId) {
            $batchOf[$learnerId] = $batches[$index < $sizeA ? 0 : 1]->id;
        }

        return $batchOf;
    }

    /**
     * The capacity of the room the batch has its lab in; no limit when unknown.
     */
    private static function labCapacity(Batch $batch): int
    {
        return $batch->schedules->first()?->room->capacity ?? PHP_INT_MAX;
    }

    /**
     * @return list<array{int, string, string}>
     */
    private function periodsFor(int $offeringId, ?int $batchId): array
    {
        $periods = [];

        foreach ($this->periods[$offeringId] ?? [] as [$periodBatch, $day, $start, $end]) {
            if ($periodBatch === null || $periodBatch === $batchId) {
                $periods[] = [$day, $start, $end];
            }
        }

        return $periods;
    }

    /**
     * @param  list<array{int, string, string}>  $a
     * @param  list<array{int, string, string}>  $b
     */
    private static function overlaps(array $a, array $b): bool
    {
        foreach ($a as [$dayA, $startA, $endA]) {
            foreach ($b as [$dayB, $startB, $endB]) {
                if ($dayA === $dayB && $startA < $endB && $startB < $endA) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{learner_id: int, subject_offering_id: int, batch_id: int|null, status: string, created_at: CarbonImmutable, updated_at: CarbonImmutable}
     */
    private function enrollmentRow(int $learnerId, int $offeringId, ?int $batchId, CarbonImmutable $at): array
    {
        return [
            'learner_id' => $learnerId,
            'subject_offering_id' => $offeringId,
            'batch_id' => $batchId,
            'status' => EnrollmentStatus::Enrolled->value,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    /**
     * Two graduates, one deactivated learner and one returnee, admitted but
     * not registered.
     */
    private function unregistered(College $college, int $returneeYear): void
    {
        $program = $college->programs->firstOrFail();

        // Entered a year earlier than their classmates and sat last year out.
        $entryYear = (int) substr(CampusData::currentTerm()->school_year, 0, 4) - $returneeYear;
        $this->admit($college, $college->programs->last() ?? $program, $returneeYear, self::demoEmail('returnee', $college->code), $entryYear);

        foreach ([LearnerStatus::Graduated, LearnerStatus::Graduated, LearnerStatus::Inactive] as $status) {
            $learner = $this->admit($college, $program, $status === LearnerStatus::Graduated ? 4 : 2, null, entryYear: $status === LearnerStatus::Graduated ? 2022 : null);
            $learner->status = $status;
            $learner->save();

            if ($status === LearnerStatus::Inactive) {
                $learner->user->is_active = false;
                $learner->user->save();
            }
        }
    }

    private function admit(College $college, Program $program, int $yearLevel, ?string $email, ?int $entryYear = null): Learner
    {
        $entryYear ??= (int) substr(CampusData::currentTerm()->school_year, 0, 4) - ($yearLevel - 1);
        $user = $this->staff->user(UserRole::Learner, $college, $email);

        $learner = new Learner;
        $learner->forceFill([
            'user_id' => $user->id,
            'program_id' => $program->id,
            'student_number' => $this->studentNumber($entryYear),
            'year_level' => $yearLevel,
            'status' => LearnerStatus::Active,
        ])->save();
        $learner->setRelation('user', $user);

        return $learner;
    }

    /**
     * "M2026-04821" or "M2026-7342": entry year, then 4 or 5 random digits.
     */
    private function studentNumber(int $entryYear): string
    {
        do {
            $digits = mt_rand(0, 1) === 1 ? 5 : 4;
            $number = sprintf('M%d-%0'.$digits.'d', $entryYear, mt_rand(0, 10 ** $digits - 1));
        } while (isset($this->studentNumbers[$number]));

        $this->studentNumbers[$number] = true;

        return $number;
    }
}
