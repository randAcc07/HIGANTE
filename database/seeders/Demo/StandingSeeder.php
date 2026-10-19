<?php

namespace Database\Seeders\Demo;

use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AttendancePolicy;
use App\Models\College;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Derives each enrollment's standing from its marks, by the term's policy:
 * equivalent absences = unexcused absences + floor(lates / lates per
 * absence). The highest warning count reached becomes warning_level;
 * reaching the drop count flags the enrollment at the class where it
 * happened. In each college the earliest flag (other than the atrisk demo
 * login's) was already confirmed by the dean two days later, and that
 * class has no marks after the drop.
 */
class StandingSeeder extends Seeder
{
    public function run(): void
    {
        $term = Term::where('school_year', CampusData::TERM['school_year'])
            ->where('semester', CampusData::TERM['semester'])
            ->sole();
        /** @var AttendancePolicy $policy */
        $policy = $term->attendancePolicy;
        /** @var list<int> $warnAt */
        $warnAt = $policy->warn_at_absences;

        $counts = DB::table('enrollments')
            ->join('attendance_records', 'attendance_records.enrollment_id', '=', 'enrollments.id')
            ->where('enrollments.status', EnrollmentStatus::Enrolled->value)
            ->groupBy('enrollments.id')
            ->selectRaw("enrollments.id, SUM(attendance_records.status = 'absent' AND attendance_records.absence_excuse_id IS NULL) AS absences, SUM(attendance_records.status = 'late') AS lates")
            ->get();

        foreach ($counts as $count) {
            $equivalent = (int) $count->absences + intdiv((int) $count->lates, $policy->lates_per_absence);
            $reached = array_filter($warnAt, fn (int $at): bool => $at <= $equivalent);

            if ($equivalent >= $policy->drop_at_absences) {
                DB::table('enrollments')->where('id', $count->id)->update([
                    'warning_level' => max([0, ...$warnAt]),
                    'status' => EnrollmentStatus::ForDropping->value,
                    'flagged_for_drop_at' => $this->reachedAt($count->id, $policy),
                ]);
            } elseif ($reached !== []) {
                DB::table('enrollments')->where('id', $count->id)->update(['warning_level' => max($reached)]);
            }
        }

        $this->confirmEarliestDrops();
    }

    /**
     * When the enrollment's equivalent absences first reached the drop count.
     */
    private function reachedAt(int $enrollmentId, AttendancePolicy $policy): ?string
    {
        $absences = 0;
        $lates = 0;
        $records = DB::table('attendance_records')
            ->where('enrollment_id', $enrollmentId)
            ->orderBy('class_date')
            ->orderBy('created_at')
            ->get(['status', 'absence_excuse_id', 'created_at']);

        foreach ($records as $record) {
            $absences += (int) ($record->status === AttendanceStatus::Absent->value && $record->absence_excuse_id === null);
            $lates += (int) ($record->status === AttendanceStatus::Late->value);

            if ($absences + intdiv($lates, $policy->lates_per_absence) >= $policy->drop_at_absences) {
                return $record->created_at;
            }
        }

        return null;
    }

    private function confirmEarliestDrops(): void
    {
        foreach (College::all() as $college) {
            $dean = User::where('email', StaffSeeder::deanEmail($college->code))->sole();
            $flagged = DB::table('enrollments')
                ->join('learners', 'learners.id', '=', 'enrollments.learner_id')
                ->join('users', 'users.id', '=', 'learners.user_id')
                ->where('users.college_id', $college->id)
                ->where('users.email', '!=', RegistrationSeeder::demoEmail('atrisk', $college->code))
                ->where('enrollments.status', EnrollmentStatus::ForDropping->value)
                ->orderBy('enrollments.flagged_for_drop_at')
                ->first(['enrollments.id', 'enrollments.flagged_for_drop_at']);

            if ($flagged === null) {
                continue;
            }

            $confirmedAt = CarbonImmutable::parse($flagged->flagged_for_drop_at)->addDays(2)->setTime(9, 0);

            if ($confirmedAt->isFuture()) {
                continue;
            }

            DB::table('enrollments')->where('id', $flagged->id)->update([
                'status' => EnrollmentStatus::Dropped->value,
                'drop_confirmed_at' => $confirmedAt,
                'drop_confirmed_by' => $dean->id,
            ]);
            DB::table('attendance_records')
                ->where('enrollment_id', $flagged->id)
                ->where('class_date', '>=', $confirmedAt->toDateString())
                ->delete();
        }
    }
}
