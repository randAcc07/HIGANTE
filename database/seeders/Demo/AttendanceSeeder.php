<?php

namespace Database\Seeders\Demo;

use App\Attendance\ClassDays;
use App\Enums\AttendanceStatus;
use App\Enums\DecisionStatus;
use App\Models\AbsenceExcuse;
use App\Models\CalendarEvent;
use App\Models\College;
use App\Models\Enrollment;
use App\Models\ExcuseDecision;
use App\Models\Schedule;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Attendance from the start of the term to yesterday, as the teachers would
 * have marked it. Everyone counts as present unless marked otherwise, so
 * only lates and absences are written; a present record exists only where
 * a mark was later corrected back to present. Suspended meetings (whole
 * days, or the classes starting inside a suspension's window) have no
 * marks, except a couple of afternoon classes marked before the partial-day
 * suspension was entered: those marks stay but don't count.
 *
 * Most learners are rarely late or absent; some often are, so warnings and
 * drop flags happen naturally. The demo logins are scripted:
 * learner.<college>@ ends at 3 and 4 equivalent absences in two classes,
 * atrisk.<college>@ at 5 in two (StandingSeeder has the dean dismiss the
 * second). Learners with an excuse are absent on its days, and the
 * absences in the classes where it was approved are linked to it.
 *
 * Every record gets its first-mark audit row; corrected ones (a few by the
 * dean, the rest by the teacher) get a second.
 */
class AttendanceSeeder extends Seeder
{
    /** Chance of [absent, late] per class, by learner profile. */
    private const PROFILES = [
        'steady' => [0.01, 0.03],
        'unsteady' => [0.04, 0.09],
        'struggling' => [0.11, 0.12],
    ];

    /** Scripted [unexcused absences, lates] for the demo logins' first classes. */
    private const SCRIPTS = [
        'learner' => [[2, 3], [4, 0]],
        'atrisk' => [[5, 1], [4, 3]],
    ];

    /** About 1 in this many presents was first marked absent (or late) and corrected. */
    private const CORRECTED_TO_PRESENT = 1500;

    /** About 1 in this many lates and absences was first marked the other way. */
    private const CORRECTED_BETWEEN = 80;

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /**
     * Marks changed after the fact: [enrollment id, schedule id, date, first status, changed by, changed at].
     *
     * @var list<array{int, int, string, AttendanceStatus, int, CarbonImmutable}>
     */
    private array $corrections = [];

    public static function lastClassDay(): CarbonImmutable
    {
        return CarbonImmutable::yesterday()->min(CarbonImmutable::parse(CampusData::TERM['ends_on']));
    }

    /**
     * Dates with any suspension in a college (whole day or partial), for
     * picking excuse days clear of them.
     *
     * @return array<string, true>
     */
    public static function suspendedDates(int $collegeId): array
    {
        $dates = [];
        $events = CalendarEvent::where('suspends_classes', true)
            ->where(fn ($query) => $query->whereNull('college_id')->orWhere('college_id', $collegeId))
            ->get();

        foreach ($events as $event) {
            for ($day = $event->starts_on; $day->lte($event->ends_on); $day = $day->addDay()) {
                $dates[$day->toDateString()] = true;
            }
        }

        return $dates;
    }

    public function run(ClassDays $days): void
    {
        $term = Term::where('school_year', CampusData::TERM['school_year'])
            ->where('semester', CampusData::TERM['semester'])
            ->sole();
        $first = $term->starts_on;
        $last = self::lastClassDay();

        $excusedDays = $this->excusedDays();
        $scripts = $this->scripts();
        $marked = $this->markedBeforeSuspension($term);

        foreach (College::all() as $college) {
            $dean = User::where('email', StaffSeeder::deanEmail($college->code))->sole();
            $profiles = [];

            $schedules = Schedule::with(['teacher', 'term', 'subjectOffering.section.program'])
                ->where('term_id', $term->id)
                ->whereHas('subjectOffering.subject', fn ($subject) => $subject->where('college_id', $college->id))
                ->orderBy('id')
                ->get()
                ->groupBy('subject_offering_id');

            $enrollments = Enrollment::query()
                ->whereIn('subject_offering_id', $schedules->keys())
                ->orderBy('id')
                ->get(['id', 'learner_id', 'subject_offering_id', 'batch_id', 'drop_confirmed_at'])
                ->groupBy('subject_offering_id');

            foreach ($schedules as $offeringId => $offeringSchedules) {
                // Every period of the offering: [schedule, date, counts], in date order.
                $periods = [];

                foreach ($offeringSchedules as $schedule) {
                    $day = $first->addDays(($schedule->weekday() - $first->dayOfWeekIso + 7) % 7);

                    for (; $day->lte($last); $day = $day->addWeek()) {
                        if ($days->suspension($schedule, $day) === null) {
                            $periods[] = [$schedule, $day, true];
                        } elseif (isset($marked["{$schedule->id}|{$day->toDateString()}"])) {
                            $periods[] = [$schedule, $day, false];
                        }
                    }
                }

                usort($periods, fn (array $a, array $b): int => [$a[1], $a[0]->starts_at] <=> [$b[1], $b[0]->starts_at]);
                $forced = [];

                foreach ($enrollments[$offeringId] ?? [] as $enrollment) {
                    $mine = array_values(array_filter($periods, fn (array $period): bool => ($period[0]->batch_id === null || $period[0]->batch_id === $enrollment->batch_id)
                        && ($enrollment->drop_confirmed_at === null || $period[1]->lt($enrollment->drop_confirmed_at->startOfDay()))));
                    $profiles[$enrollment->learner_id] ??= self::profile();

                    $this->mark($enrollment, $mine, $excusedDays[$enrollment->learner_id] ?? [], $scripts[$enrollment->id] ?? null, $profiles[$enrollment->learner_id], $dean, $forced);
                }
            }
        }

        $this->flush();
        $this->audit();
        $this->linkDecidedExcuses();
    }

    /**
     * @param  list<array{Schedule, CarbonImmutable, bool}>  $periods
     * @param  array<string, true>  $excusedDays
     * @param  array{int, int}|null  $script
     * @param  array{float, float}  $profile
     * @param  array<string, int>  $forced  Marks already forced per uncounted period ("schedule|date").
     */
    private function mark(Enrollment $enrollment, array $periods, array $excusedDays, ?array $script, array $profile, User $dean, array &$forced): void
    {
        $statuses = [];

        foreach ($periods as $index => [$schedule, $day, $counts]) {
            if (! $counts) {
                // Marked before the suspension: one absence and one late per class, so the note shows.
                $key = "{$schedule->id}|{$day->toDateString()}";
                $forced[$key] = ($forced[$key] ?? 0) + 1;
                $statuses[$index] = match ($forced[$key]) {
                    1 => AttendanceStatus::Absent,
                    2 => AttendanceStatus::Late,
                    default => AttendanceStatus::Present,
                };
            } elseif (isset($excusedDays[$day->toDateString()])) {
                $statuses[$index] = AttendanceStatus::Absent;
            }
        }

        if ($script !== null) {
            // Scripted absences and lates on random free periods; the rest present.
            $free = array_values(array_diff(array_keys($periods), array_keys($statuses)));
            shuffle($free);
            [$absences, $lates] = $script;

            foreach ($free as $n => $index) {
                $statuses[$index] = match (true) {
                    $n < $absences => AttendanceStatus::Absent,
                    $n < $absences + $lates => AttendanceStatus::Late,
                    default => AttendanceStatus::Present,
                };
            }
        }

        foreach ($periods as $index => [$schedule, $day, $counts]) {
            $status = $statuses[$index] ?? self::roll($profile);
            $firstStatus = $status;

            if ($script === null && ! isset($statuses[$index])) {
                $firstStatus = match ($status) {
                    AttendanceStatus::Present => mt_rand(1, self::CORRECTED_TO_PRESENT) === 1 ? (mt_rand(1, 4) === 1 ? AttendanceStatus::Late : AttendanceStatus::Absent) : $status,
                    AttendanceStatus::Absent => mt_rand(1, self::CORRECTED_BETWEEN) === 1 ? AttendanceStatus::Late : $status,
                    AttendanceStatus::Late => mt_rand(1, self::CORRECTED_BETWEEN) === 1 ? AttendanceStatus::Absent : $status,
                };
            }

            // Present is the default: nothing is written unless it was marked otherwise first.
            if ($firstStatus === AttendanceStatus::Present) {
                continue;
            }

            $markedAt = $day->setTimeFrom($schedule->ends_at)->subMinutes(mt_rand(0, 40));
            $updatedAt = $markedAt;

            if ($firstStatus !== $status) {
                $updatedAt = $day->addDay()->setTime(mt_rand(7, 11), mt_rand(0, 59))->min(CarbonImmutable::now());
                $changer = mt_rand(1, 4) === 1 ? $dean->id : $schedule->teacher->user_id;
                $this->corrections[] = [$enrollment->id, $schedule->id, $day->toDateString(), $firstStatus, $changer, $updatedAt];
            }

            $this->rows[] = [
                'enrollment_id' => $enrollment->id,
                'schedule_id' => $schedule->id,
                'class_date' => $day->toDateString(),
                'status' => $status->value,
                'recorded_by' => $schedule->teacher->user_id,
                'created_at' => $markedAt,
                'updated_at' => $updatedAt,
            ];

            if (count($this->rows) >= 2000) {
                $this->flush();
            }
        }
    }

    private function flush(): void
    {
        if ($this->rows !== []) {
            DB::table('attendance_records')->insert($this->rows);
            $this->rows = [];
        }
    }

    /**
     * First-mark audit rows for every record, then the corrections.
     */
    private function audit(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO attendance_audits (attendance_record_id, changed_by, old_status, new_status, changed_at)
            SELECT id, recorded_by, NULL, status, created_at FROM attendance_records
            SQL);

        foreach ($this->corrections as [$enrollmentId, $scheduleId, $date, $firstStatus, $changer, $changedAt]) {
            $record = DB::table('attendance_records')
                ->where(['enrollment_id' => $enrollmentId, 'schedule_id' => $scheduleId, 'class_date' => $date])
                ->first(['id', 'status']);

            if ($record === null) {
                continue;
            }

            DB::table('attendance_audits')->where('attendance_record_id', $record->id)->update(['new_status' => $firstStatus->value]);
            DB::table('attendance_audits')->insert([
                'attendance_record_id' => $record->id,
                'changed_by' => $changer,
                'old_status' => $firstStatus->value,
                'new_status' => $record->status,
                'changed_at' => $changedAt,
            ]);
        }
    }

    /**
     * An approval excuses the learner's absences in that class on the
     * excuse's days (lates stay); a revoked one was linked and then unlinked.
     * Each step is audited as done by whoever decided or revoked it, when
     * they did. Marks come after the decisions in seeding order, so this is
     * what Excuses::decide() and revoke() would have linked.
     */
    private function linkDecidedExcuses(): void
    {
        $decisions = ExcuseDecision::with('absenceExcuse')
            ->whereIn('status', [DecisionStatus::Approved, DecisionStatus::Revoked])
            ->orderBy('id')
            ->get();

        foreach ($decisions as $decision) {
            $excuse = $decision->absenceExcuse;
            $records = DB::table('attendance_records')
                ->join('enrollments', 'enrollments.id', '=', 'attendance_records.enrollment_id')
                ->where('enrollments.learner_id', $excuse->learner_id)
                ->where('enrollments.subject_offering_id', $decision->subject_offering_id)
                ->whereBetween('attendance_records.class_date', [$excuse->date_from->toDateString(), $excuse->date_to->toDateString()])
                ->where('attendance_records.status', AttendanceStatus::Absent->value)
                ->whereNull('attendance_records.absence_excuse_id')
                ->pluck('attendance_records.id');
            $revoked = $decision->status === DecisionStatus::Revoked;
            $audit = fn (int $id, ?int $old, ?int $new, ?int $by, mixed $at): array => [
                'attendance_record_id' => $id,
                'changed_by' => $by,
                'old_status' => AttendanceStatus::Absent->value,
                'new_status' => AttendanceStatus::Absent->value,
                'old_absence_excuse_id' => $old,
                'new_absence_excuse_id' => $new,
                'changed_at' => $at,
            ];

            DB::table('attendance_records')->whereIn('id', $records)->update([
                'absence_excuse_id' => $revoked ? null : $excuse->id,
                'updated_at' => $revoked ? $decision->revoked_at : $decision->decided_at,
            ]);
            DB::table('attendance_audits')->insert($records->flatMap(fn (int $id): array => [
                $audit($id, null, $excuse->id, $decision->decided_by, $decision->decided_at),
                ...($revoked ? [$audit($id, $excuse->id, null, $decision->revoked_by, $decision->revoked_at)] : []),
            ])->all());
        }
    }

    /**
     * Classes marked on a partial-day suspension's date before it was
     * entered: the first two (by start) that start inside its window and
     * had ended by then. Keys are "schedule id|date".
     *
     * @return array<string, true>
     */
    private function markedBeforeSuspension(Term $term): array
    {
        $marked = [];
        $events = CalendarEvent::where('suspends_classes', true)
            ->whereNotNull('suspends_from')
            ->whereBetween('starts_on', [$term->starts_on->toDateString(), self::lastClassDay()->toDateString()])
            ->get();

        foreach ($events as $event) {
            $enteredAt = ($event->created_at ?? CarbonImmutable::now())->format('H:i:s');
            $schedules = Schedule::query()
                ->where('term_id', $term->id)
                ->when($event->college_id !== null, fn ($query) => $query->whereHas('subjectOffering.section.program', fn ($program) => $program->where('college_id', $event->college_id)))
                ->whereRaw('WEEKDAY(starts_at) = ?', [$event->starts_on->dayOfWeekIso - 1])
                ->whereRaw('TIME(starts_at) >= ?', [$event->suspends_from])
                ->when($event->suspends_until !== null, fn ($query) => $query->whereRaw('TIME(starts_at) < ?', [$event->suspends_until]))
                ->whereRaw('TIME(ends_at) <= ?', [$enteredAt])
                ->orderBy('starts_at')
                ->orderBy('id')
                ->limit(2)
                ->pluck('id');

            foreach ($schedules as $scheduleId) {
                $marked["{$scheduleId}|{$event->starts_on->toDateString()}"] = true;
            }
        }

        return $marked;
    }

    /**
     * Each learner's days covered by an excuse of any status: they were absent.
     *
     * @return array<int, array<string, true>>
     */
    private function excusedDays(): array
    {
        $days = [];

        foreach (AbsenceExcuse::all() as $excuse) {
            for ($day = $excuse->date_from; $day->lte($excuse->date_to); $day = $day->addDay()) {
                $days[$excuse->learner_id][$day->toDateString()] = true;
            }
        }

        return $days;
    }

    /**
     * Scripted [absences, lates] per enrollment id: the demo logins' first
     * scheduled classes.
     *
     * @return array<int, array{int, int}>
     */
    private function scripts(): array
    {
        $scripts = [];

        foreach (College::all() as $college) {
            foreach (self::SCRIPTS as $kind => $classes) {
                $enrollmentIds = Enrollment::query()
                    ->whereHas('learner.user', fn ($user) => $user->where('email', RegistrationSeeder::demoEmail($kind, $college->code)))
                    ->whereHas('subjectOffering.schedules')
                    ->orderBy('id')
                    ->limit(count($classes))
                    ->pluck('id')
                    ->all();

                foreach ($enrollmentIds as $index => $id) {
                    $scripts[$id] = $classes[$index];
                }
            }
        }

        return $scripts;
    }

    /**
     * @return array{float, float}
     */
    private static function profile(): array
    {
        $roll = mt_rand(1, 100);

        return self::PROFILES[$roll <= 85 ? 'steady' : ($roll <= 96 ? 'unsteady' : 'struggling')];
    }

    /**
     * @param  array{float, float}  $profile
     */
    private static function roll(array $profile): AttendanceStatus
    {
        $roll = mt_rand() / mt_getrandmax();

        return match (true) {
            $roll < $profile[0] => AttendanceStatus::Absent,
            $roll < $profile[0] + $profile[1] => AttendanceStatus::Late,
            default => AttendanceStatus::Present,
        };
    }
}
