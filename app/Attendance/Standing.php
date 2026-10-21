<?php

namespace App\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\DropReason;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\Schedule;
use App\Models\User;
use App\Notifications\AttendanceNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * An enrollment's attendance standing under its term's policy:
 *
 *   equivalent absences = unexcused absences + floor(lates / lates per absence)
 *
 * counting only meetings that weren't suspended (marks on a day suspended
 * afterwards are kept but don't count). Reaching a warning count raises the
 * warning level and tells the learner; reaching the drop count flags the
 * enrollment for the dean, who confirms or dismisses it. Corrections lower
 * the level and lift the flag again; a confirmed drop stays until the dean
 * reinstates it. A dismissal (and a reinstatement) holds until the learner's
 * equivalent absences pass the count it was made at.
 *
 * Resolve it per request: it shares ClassDays' suspension cache.
 */
class Standing
{
    public function __construct(private ClassDays $days) {}

    /**
     * @return array{absences: int, lates: int, excused: int, equivalent: int}
     */
    public function counts(Enrollment $enrollment): array
    {
        $records = AttendanceRecord::with('schedule.term', 'schedule.subjectOffering.section.program')
            ->where('enrollment_id', $enrollment->id)
            ->where('status', '!=', AttendanceStatus::Present)
            ->get()
            ->filter(fn (AttendanceRecord $record): bool => $this->days->suspension($record->schedule, $record->class_date) === null);

        $absences = $records->filter(fn (AttendanceRecord $record): bool => $record->status === AttendanceStatus::Absent && $record->absence_excuse_id === null)->count();
        $excused = $records->filter(fn (AttendanceRecord $record): bool => $record->absence_excuse_id !== null)->count();
        $lates = $records->filter(fn (AttendanceRecord $record): bool => $record->status === AttendanceStatus::Late)->count();

        return [
            'absences' => $absences,
            'lates' => $lates,
            'excused' => $excused,
            'equivalent' => $absences + intdiv($lates, self::policy($enrollment)['lates_per_absence']),
        ];
    }

    /**
     * Class periods for the enrollment: held so far (up to today) and in
     * the whole term, from the day they enrolled to the day it was dropped,
     * skipping suspended meetings. One class on one date is one period.
     *
     * @return array{held: int, total: int}
     */
    public function periods(Enrollment $enrollment): array
    {
        $offering = $enrollment->subjectOffering()->with('section.term')->firstOrFail();
        $term = $offering->section->term;
        $from = $term->starts_on->max($enrollment->created_at?->startOfDay() ?? $term->starts_on);
        $until = $enrollment->status === EnrollmentStatus::Dropped && $enrollment->drop_confirmed_at !== null
            ? $term->ends_on->min($enrollment->drop_confirmed_at->startOfDay())
            : $term->ends_on;
        $today = CarbonImmutable::today();
        $counts = ['held' => 0, 'total' => 0];

        $schedules = Schedule::with(['term', 'subjectOffering.section.program'])
            ->where('subject_offering_id', $offering->id)
            ->where(fn ($query) => $query->whereNull('batch_id')->orWhere('batch_id', $enrollment->batch_id))
            ->get();

        foreach ($schedules as $schedule) {
            $day = $from->addDays(($schedule->weekday() - $from->dayOfWeekIso + 7) % 7);

            for (; $day->lte($until); $day = $day->addWeek()) {
                if ($this->days->suspension($schedule, $day) === null) {
                    $counts['total']++;
                    $counts['held'] += $day->lte($today) ? 1 : 0;
                }
            }
        }

        return $counts;
    }

    /**
     * Bring the enrollment's warning level and drop flag in line with its
     * marks, notifying what is newly reached.
     */
    public function refresh(Enrollment $enrollment): void
    {
        if ($enrollment->status === EnrollmentStatus::Dropped) {
            return;
        }

        $enrollment->loadMissing(['learner.user', 'subjectOffering.subject', 'subjectOffering.section.program']);
        $policy = self::policy($enrollment);
        $equivalent = $this->counts($enrollment)['equivalent'];
        $level = max([0, ...array_filter($policy['warn_at_absences'], fn (int $at): bool => $at <= $equivalent)]);
        $held = $enrollment->drop_dismissed_absences !== null && $equivalent <= $enrollment->drop_dismissed_absences;
        $flag = $equivalent >= $policy['drop_at_absences'] && ! $held;

        $raised = $level > $enrollment->warning_level;
        $flagged = $flag && $enrollment->status === EnrollmentStatus::Enrolled;

        $enrollment->forceFill([
            'warning_level' => $level,
            'status' => $flag ? EnrollmentStatus::ForDropping : EnrollmentStatus::Enrolled,
            'flagged_for_drop_at' => $flag ? ($enrollment->flagged_for_drop_at ?? now()) : null,
        ])->save();

        if ($flagged) {
            $this->announceFlag($enrollment, $equivalent);
        } elseif ($raised) {
            $this->announceWarning($enrollment, $equivalent);
        }
    }

    /**
     * Tell the learner they reached a warning count.
     */
    public function announceWarning(Enrollment $enrollment, int $equivalent): void
    {
        $enrollment->loadMissing('learner.user');
        $enrollment->learner->user->notify(AttendanceNotice::warning($enrollment, $equivalent, self::policy($enrollment)['drop_at_absences']));
    }

    /**
     * Tell the learner, and the deans of their class's college, that they
     * are flagged for dropping.
     */
    public function announceFlag(Enrollment $enrollment, int $equivalent): void
    {
        $enrollment->loadMissing('learner.user');
        $enrollment->learner->user->notify(AttendanceNotice::flagged($enrollment, $equivalent));

        foreach (self::deans($enrollment) as $dean) {
            $dean->notify(AttendanceNotice::learnerFlagged($enrollment, $equivalent));
        }
    }

    /**
     * Recalculate everyone with a late or absent mark in a date range of a
     * college (null = campus-wide): what a suspension changes.
     */
    public function refreshDates(?int $collegeId, CarbonImmutable $from, CarbonImmutable $until): void
    {
        $this->refreshMany(AttendanceRecord::query()
            ->where('status', '!=', AttendanceStatus::Present)
            ->whereBetween('class_date', [$from->toDateString(), $until->toDateString()])
            ->when($collegeId !== null, fn ($query) => $query->whereHas('schedule.subjectOffering.section.program', fn ($program) => $program->where('college_id', $collegeId)))
            ->distinct()
            ->pluck('enrollment_id'));
    }

    /**
     * @param  iterable<int>  $enrollmentIds
     */
    public function refreshMany(iterable $enrollmentIds): void
    {
        foreach (Enrollment::whereIn('id', collect($enrollmentIds)->unique()->all())->get() as $enrollment) {
            $this->refresh($enrollment);
        }
    }

    public function confirmDrop(Enrollment $enrollment, User $dean): void
    {
        $enrollment->forceFill([
            'status' => EnrollmentStatus::Dropped,
            'drop_confirmed_at' => now(),
            'drop_confirmed_by' => $dean->id,
            'drop_reason' => DropReason::Absences,
        ])->save();

        $enrollment->learner->user->notify(AttendanceNotice::dropped($enrollment));
    }

    /**
     * Keep the learner in the class; they are flagged again only once their
     * equivalent absences pass today's count.
     */
    public function dismiss(Enrollment $enrollment, User $dean): void
    {
        $enrollment->forceFill([
            'status' => EnrollmentStatus::Enrolled,
            'flagged_for_drop_at' => null,
            'drop_dismissed_at' => now(),
            'drop_dismissed_by' => $dean->id,
            'drop_dismissed_absences' => $this->counts($enrollment)['equivalent'],
        ])->save();
    }

    /**
     * Undo an absence drop. Like a dismissal, it holds until another
     * equivalent absence.
     */
    public function reinstate(Enrollment $enrollment, User $dean): void
    {
        DB::transaction(function () use ($enrollment, $dean): void {
            $enrollment->forceFill([
                'status' => EnrollmentStatus::Enrolled,
                'flagged_for_drop_at' => null,
                'drop_confirmed_at' => null,
                'drop_confirmed_by' => null,
                'drop_reason' => null,
                'reinstated_at' => now(),
                'reinstated_by' => $dean->id,
                'drop_dismissed_at' => now(),
                'drop_dismissed_by' => $dean->id,
                'drop_dismissed_absences' => $this->counts($enrollment)['equivalent'],
            ])->save();

            $this->refresh($enrollment);
        });

        $enrollment->learner->user->notify(AttendanceNotice::reinstated($enrollment));
    }

    /**
     * The term's policy, or the handbook's when the term has none.
     *
     * @return array{lates_per_absence: int, drop_at_absences: int, warn_at_absences: list<int>}
     */
    public static function policy(Enrollment $enrollment): array
    {
        $enrollment->loadMissing('subjectOffering.section.term.attendancePolicy');
        $policy = $enrollment->subjectOffering->section->term->attendancePolicy;

        return $policy === null ? AttendancePolicy::HANDBOOK : [
            'lates_per_absence' => $policy->lates_per_absence,
            'drop_at_absences' => $policy->drop_at_absences,
            'warn_at_absences' => array_map('intval', $policy->warn_at_absences),
        ];
    }

    /**
     * The active deans of the enrollment's college.
     *
     * @return iterable<User>
     */
    private static function deans(Enrollment $enrollment): iterable
    {
        return User::where('role', UserRole::Dean)
            ->where('college_id', $enrollment->subjectOffering->section->program->college_id)
            ->where('is_active', true)
            ->get();
    }
}
