<?php

namespace Database\Seeders\Demo;

use App\Attendance\ClassDays;
use App\Attendance\Standing;
use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\UserRole;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\College;
use App\Models\Enrollment;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use App\Notifications\AttendanceNotice;
use App\Registration\Placement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Attendance standing, as the app would have reached it mark by mark, under
 * App\Attendance\Standing's rules: equivalent absences = unexcused absences
 * + floor(lates / lates per absence), not counting excused absences or
 * marks on suspended meetings.
 *
 * Each class's counted marks are replayed in order: every warning count
 * reached sends the learner a warning, dated by the mark that reached it;
 * reaching the drop count flags the class and notifies the learner and the
 * college's dean. Then the deans decide, through the app's own actions at
 * the time they did: per college, the earliest flag of a non-demo learner
 * is confirmed as an absence drop two days later, and the atrisk demo
 * login's second flagged class is dismissed (their first stays flagged);
 * once on campus a later flag is dropped and then reinstated. One irregular learner had a subject removed by the dean after
 * attendance was taken. Older notifications are partly read.
 *
 * Finally Standing::refreshMany() runs over everything: it must change
 * nothing and notify nobody, or seeding fails.
 */
class StandingSeeder extends Seeder
{
    public function run(): void
    {
        $term = Term::where('school_year', CampusData::TERM['school_year'])
            ->where('semester', CampusData::TERM['semester'])
            ->sole();

        $this->removeOneSubject($term);
        $this->replay($term);
        $this->deanDecisions($term);
        $this->readOlderNotifications();
        $this->verify($term);
    }

    /**
     * The dean takes a major subject off one irregular learner's load a week
     * after its first late or absence; no marks after that.
     */
    private function removeOneSubject(Term $term): void
    {
        $registrations = TermRegistration::with('learner.user')
            ->where('term_id', $term->id)
            ->where('is_irregular', true)
            ->where('status', RegistrationStatus::Registered)
            ->orderBy('id')
            ->get();

        foreach ($registrations as $registration) {
            $enrollment = Enrollment::query()
                ->where('learner_id', $registration->learner_id)
                ->where('status', EnrollmentStatus::Enrolled)
                ->whereHas('subjectOffering.subject', fn ($subject) => $subject->where('code', 'not regexp', '^(GE|PE|NSTP) '))
                ->whereHas('attendanceRecords')
                ->orderBy('id')
                ->first();
            $firstMark = $enrollment?->attendanceRecords()->min('class_date');

            if ($enrollment === null || $firstMark === null) {
                continue;
            }

            $removedAt = CarbonImmutable::parse($firstMark)->addWeek()->setTime(10, 0);

            if ($removedAt->isFuture()) {
                continue;
            }

            $dean = self::dean($registration->learner->user->college_id);
            Clock::at($removedAt, fn () => app(Placement::class)->remove($registration, $enrollment, $dean));
            $enrollment->attendanceRecords()->where('class_date', '>=', $removedAt->toDateString())->delete();

            return;
        }
    }

    /**
     * Warning levels, drop flags and their notices, from each class's
     * counted marks in order.
     */
    private function replay(Term $term): void
    {
        $days = app(ClassDays::class);
        /** @var AttendancePolicy $policy */
        $policy = $term->attendancePolicy;
        /** @var list<int> $warnAt */
        $warnAt = array_map(intval(...), $policy->warn_at_absences);

        $marks = AttendanceRecord::with(['schedule.term', 'schedule.subjectOffering.section.program'])
            ->whereIn('status', [AttendanceStatus::Absent, AttendanceStatus::Late])
            ->whereHas('schedule', fn ($schedule) => $schedule->where('term_id', $term->id))
            ->orderBy('class_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('enrollment_id');
        $enrollments = Enrollment::with(['learner.user', 'subjectOffering.subject', 'subjectOffering.section.program'])
            ->findMany($marks->keys())
            ->keyBy('id');

        foreach ($marks as $enrollmentId => $records) {
            $enrollment = $enrollments[$enrollmentId];
            $absences = 0;
            $lates = 0;
            $level = 0;
            $flaggedAt = null;
            /** @var list<array{string, int, CarbonImmutable}> $events */
            $events = [];

            foreach ($records as $record) {
                if ($record->absence_excuse_id !== null || $days->suspension($record->schedule, $record->class_date) !== null) {
                    continue;
                }

                $record->status === AttendanceStatus::Absent ? $absences++ : $lates++;
                $equivalent = $absences + intdiv($lates, $policy->lates_per_absence);
                $at = $record->created_at ?? $record->class_date;

                if ($equivalent >= $policy->drop_at_absences) {
                    $level = max([0, ...$warnAt]);

                    if ($flaggedAt === null) {
                        $flaggedAt = $at;
                        $events[] = [AttendanceNotice::FLAGGED, $equivalent, $at];
                    }

                    continue;
                }

                $reached = max([0, ...array_filter($warnAt, fn (int $count): bool => $count <= $equivalent)]);

                if ($reached > $level) {
                    $level = $reached;
                    $events[] = [AttendanceNotice::WARNING, $equivalent, $at];
                }
            }

            if ($events === []) {
                continue;
            }

            $dropped = $enrollment->status === EnrollmentStatus::Dropped;
            $enrollment->forceFill([
                'warning_level' => $level,
                'status' => $flaggedAt !== null && ! $dropped ? EnrollmentStatus::ForDropping : $enrollment->status,
                'flagged_for_drop_at' => $flaggedAt,
            ])->save();

            foreach ($events as [$kind, $count, $at]) {
                $this->notify($enrollment, $kind, $count, $at->addMinutes(mt_rand(1, 20)));
            }
        }
    }

    /**
     * The app's own warning or flag notice, as of the mark that reached it.
     */
    private function notify(Enrollment $enrollment, string $kind, int $count, CarbonImmutable $at): void
    {
        Clock::at($at, fn () => $kind === AttendanceNotice::WARNING
            ? app(Standing::class)->announceWarning($enrollment, $count)
            : app(Standing::class)->announceFlag($enrollment, $count));
    }

    /**
     * Per college: confirm the earliest non-demo flag two days later (its
     * class has no marks after that) and dismiss the atrisk login's second
     * flagged class the day after its last mark. Once on campus (the first
     * college with a later non-demo flag at least three days old), drop
     * that flag and reinstate it two days later.
     */
    private function deanDecisions(Term $term): void
    {
        $reinstated = false;

        foreach (College::orderBy('id')->get() as $college) {
            $dean = self::dean($college->id);
            $atrisk = RegistrationSeeder::demoEmail('atrisk', $college->code);
            $flagged = fn (bool $demo) => Enrollment::query()
                ->where('status', EnrollmentStatus::ForDropping)
                ->whereHas('subjectOffering.section', fn ($section) => $section->where('term_id', $term->id))
                ->whereHas('learner.user', fn ($user) => $user->where('college_id', $college->id)->where('email', $demo ? '=' : '!=', $atrisk));

            $natural = $flagged(false)->orderBy('flagged_for_drop_at')->orderBy('id')->get()->values();

            if (isset($natural[0])) {
                $this->confirm($natural[0], $dean, days: 2, until: null);
            }

            // One reinstatement on campus: the first college with a later flag old enough.
            foreach ($reinstated ? [] : $natural->slice(1) as $enrollment) {
                $reinstatedAt = self::flaggedAt($enrollment)->addDays(3)->setTime(9, 0);

                if (! $reinstatedAt->isFuture() && $this->confirm($enrollment, $dean, days: 1, until: $reinstatedAt)) {
                    Clock::at($reinstatedAt, fn () => app(Standing::class)->reinstate($enrollment->refresh(), $dean));
                    $reinstated = true;

                    break;
                }
            }

            $second = $flagged(true)->orderBy('id')->skip(1)->first();

            if ($second !== null) {
                $lastMark = CarbonImmutable::parse((string) $second->attendanceRecords()->max('created_at'));
                $dismissedAt = $lastMark->addDay()->setTime(10, 0)->min(CarbonImmutable::now());
                Clock::at($dismissedAt, fn () => app(Standing::class)->dismiss($second, $dean));
            }
        }
    }

    /**
     * Confirm an absence drop some days after the flag; marks from then (up
     * to a reinstatement, if any) are taken away, since a dropped learner
     * is off the class list.
     */
    private function confirm(Enrollment $enrollment, User $dean, int $days, ?CarbonImmutable $until): bool
    {
        $confirmedAt = self::flaggedAt($enrollment)->addDays($days)->setTime(9, 0);

        if ($confirmedAt->isFuture()) {
            return false;
        }

        Clock::at($confirmedAt, fn () => app(Standing::class)->confirmDrop($enrollment, $dean));
        $enrollment->attendanceRecords()
            ->where('class_date', '>=', $confirmedAt->toDateString())
            ->when($until !== null, fn ($query) => $query->where('class_date', '<', $until->toDateString()))
            ->delete();

        return true;
    }

    /**
     * Notices more than a week old were read about half the time; the
     * rest, and everything recent, stay unread.
     */
    private function readOlderNotifications(): void
    {
        $older = DB::table('notifications')
            ->where('type', AttendanceNotice::class)
            ->where('created_at', '<', CarbonImmutable::now()->subWeek())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'created_at']);

        foreach ($older as $notification) {
            if (mt_rand(0, 1) === 1) {
                DB::table('notifications')->where('id', $notification->id)->update([
                    'read_at' => CarbonImmutable::parse($notification->created_at)->addHours(mt_rand(1, 48)),
                ]);
            }
        }
    }

    /**
     * The app's own recalculation must agree with everything seeded above.
     * A class with fewer lates and absences than the first warning (or drop)
     * count can't have reached anything, so only the others are recalculated.
     */
    private function verify(Term $term): void
    {
        /** @var AttendancePolicy $policy */
        $policy = $term->attendancePolicy;
        $ids = AttendanceRecord::query()
            ->whereIn('status', [AttendanceStatus::Absent, AttendanceStatus::Late])
            ->whereHas('schedule', fn ($schedule) => $schedule->where('term_id', $term->id))
            ->groupBy('enrollment_id')
            ->havingRaw('COUNT(*) >= ?', [min([$policy->drop_at_absences, ...array_map(intval(...), $policy->warn_at_absences)])])
            ->pluck('enrollment_id');
        $snapshot = fn (): array => Enrollment::whereIn('id', $ids)
            ->orderBy('id')
            ->get(['id', 'status', 'warning_level', 'flagged_for_drop_at'])
            ->map(fn (Enrollment $enrollment): string => implode('|', [$enrollment->id, $enrollment->status->value, $enrollment->warning_level, $enrollment->flagged_for_drop_at?->toDateTimeString()]))
            ->all();

        $before = $snapshot();
        $notices = DB::table('notifications')->count();

        app(Standing::class)->refreshMany($ids);

        $changed = array_diff($snapshot(), $before);

        if ($changed !== [] || DB::table('notifications')->count() !== $notices) {
            throw new RuntimeException('The seeded standing disagrees with App\Attendance\Standing: '.implode(', ', array_slice($changed, 0, 5)));
        }
    }

    private static function flaggedAt(Enrollment $enrollment): CarbonImmutable
    {
        return $enrollment->flagged_for_drop_at ?? throw new RuntimeException("Enrollment {$enrollment->id} is not flagged.");
    }

    private static function dean(?int $collegeId): User
    {
        return User::where('role', UserRole::Dean)->where('college_id', $collegeId)->orderBy('id')->firstOrFail();
    }
}
