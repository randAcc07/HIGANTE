<?php

namespace App\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\Schedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who a class meets with on a date, and recording their marks. Everyone on
 * the list counts as present unless marked otherwise: only exceptions (late,
 * absent) are recorded, and a mark put back to present keeps its record so
 * its history stays. A learner is on the list from the day they enrolled in
 * the subject until the day it was dropped (anyone already marked that day
 * stays listed); a batch's class lists only that batch.
 */
class ClassList
{
    /**
     * The enrollments on the class list, by last name, with the day's records.
     *
     * @return Collection<int, Enrollment>
     */
    public function attendees(Schedule $schedule, CarbonImmutable $date): Collection
    {
        $day = $date->toDateString();
        $marked = AttendanceRecord::where('schedule_id', $schedule->id)->where('class_date', $day)->select('enrollment_id');

        return Enrollment::query()
            ->with(['learner.user', 'batch:id,name', 'attendanceRecords' => fn ($records) => $records
                ->where('schedule_id', $schedule->id)
                ->where('class_date', $day)
                ->with('recorder:id,first_name,middle_name,last_name,suffix')])
            ->where('subject_offering_id', $schedule->subject_offering_id)
            ->when($schedule->batch_id !== null, fn ($query) => $query->where('batch_id', $schedule->batch_id))
            ->where(fn ($query) => $query
                ->whereIn('enrollments.id', $marked)
                ->orWhere(fn ($query) => $query
                    ->whereDate('enrollments.created_at', '<=', $day)
                    ->where(fn ($query) => $query
                        ->where('enrollments.status', '!=', EnrollmentStatus::Dropped)
                        ->orWhereDate('enrollments.drop_confirmed_at', '>=', $day))))
            ->join('learners', 'learners.id', '=', 'enrollments.learner_id')
            ->join('users', 'users.id', '=', 'learners.user_id')
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->select('enrollments.*')
            ->get();
    }

    /**
     * Save changed marks (enrollment id => status) for learners on the list.
     * Marking someone present who has no record changes nothing (they
     * already count as present). Each new or changed mark gets an audit row,
     * whose old status is NULL when it replaced the implicit present; an
     * excused absence stays as it is (the excuse decides it, not the mark).
     *
     * @param  array<int, AttendanceStatus>  $marks
     * @return array{created: int, changed: int}
     */
    public function record(Schedule $schedule, CarbonImmutable $date, array $marks, User $by): array
    {
        $attendees = $this->attendees($schedule, $date)->keyBy('id');
        $unknown = array_diff(array_keys($marks), $attendees->keys()->all());

        if ($unknown !== []) {
            throw ValidationException::withMessages(['marks' => __('The class list changed since it was opened. Reload the page and try again.')]);
        }

        $counts = ['created' => 0, 'changed' => 0];

        DB::transaction(function () use ($attendees, $marks, $schedule, $date, $by, &$counts): void {
            $now = now();

            foreach ($marks as $enrollmentId => $status) {
                /** @var Enrollment $enrollment */
                $enrollment = $attendees[$enrollmentId];
                /** @var AttendanceRecord|null $record */
                $record = $enrollment->attendanceRecords->first();

                $unchanged = $record === null
                    ? $status === AttendanceStatus::Present
                    : $record->status === $status || $record->absence_excuse_id !== null;

                if ($unchanged) {
                    continue;
                }

                $old = $record?->status;

                if ($record === null) {
                    $record = AttendanceRecord::create([
                        'enrollment_id' => $enrollmentId,
                        'schedule_id' => $schedule->id,
                        'class_date' => $date->toDateString(),
                        'status' => $status,
                        'recorded_by' => $by->id,
                    ]);
                    $counts['created']++;
                } else {
                    $record->update(['status' => $status]);
                    $counts['changed']++;
                }

                AttendanceAudit::create([
                    'attendance_record_id' => $record->id,
                    'changed_by' => $by->id,
                    'old_status' => $old,
                    'new_status' => $status,
                    'changed_at' => $now,
                ]);
            }
        });

        return $counts;
    }
}
