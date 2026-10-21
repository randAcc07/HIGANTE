<?php

namespace App\Http\Controllers;

use App\Attendance\ClassDays;
use App\Attendance\ClassList;
use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Dean\AttendanceController as DeanAttendanceController;
use App\Http\Controllers\Teacher\ClassController;
use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\Schedule;
use App\Models\User;
use App\Scheduling\ScheduleConflicts;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One class's list on one date. Everyone counts as present unless marked
 * otherwise: the teacher marks who was late or absent, the dean of its
 * college can correct it. Every new or changed mark is audited.
 */
class AttendanceController extends Controller
{
    // The services are injected per action, not into the constructor: a
    // route keeps its controller instance, and ClassDays caches suspensions.
    public function show(Request $request, Schedule $schedule, string $date, ClassDays $days, ClassList $list): Response
    {
        Gate::authorize('viewAttendance', $schedule);
        /** @var User $user */
        $user = $request->user();
        $day = self::date($date);
        $schedule->load(['subjectOffering.subject', 'subjectOffering.section.program', 'batch', 'room', 'teacher.user', 'term']);
        $refusal = $days->refusal($schedule, $day);
        $dates = array_map(fn (CarbonImmutable $d): string => $d->toDateString(), $days->pastDates($schedule));
        $at = array_search($day->toDateString(), $dates, true);

        $learners = $refusal === null ? $list->attendees($schedule, $day)->map(function (Enrollment $enrollment): array {
            /** @var AttendanceRecord|null $record */
            $record = $enrollment->attendanceRecords->first();

            return [
                'enrollment_id' => $enrollment->id,
                'name' => $enrollment->learner->user->last_name.', '.$enrollment->learner->user->first_name,
                'student_number' => $enrollment->learner->student_number,
                'status' => $record?->status->value,
                'excused' => $record?->absence_excuse_id !== null,
                'recorded_by' => $record?->recorder?->full_name,
                'recorded_at' => $record?->updated_at?->toIso8601String(),
            ];
        })->values() : collect();

        $offering = $schedule->subjectOffering;

        return Inertia::render('attendance/ClassList', [
            'schedule' => [
                'id' => $schedule->id,
                'subject' => $offering->subject->only(['code', 'title']),
                'section' => "{$offering->section->program->code} {$offering->section->name}",
                'batch' => $schedule->batch?->name,
                'room' => $schedule->room?->label,
                'teacher' => $schedule->teacher->user->full_name,
                'when' => ScheduleConflicts::when($schedule),
                'term' => $schedule->term->label,
            ],
            'date' => $day->toDateString(),
            'refusal' => $refusal,
            'learners' => $learners,
            // Neighbouring meeting dates (latest first in $dates).
            'previous' => $at === false ? ($dates[0] ?? null) : ($dates[$at + 1] ?? null),
            'next' => $at === false || $at === 0 ? null : $dates[$at - 1],
            'canEdit' => $refusal === null && Gate::allows('takeAttendance', $schedule),
            'closed' => $schedule->term->isClosed(),
            'backLabel' => $user->role === UserRole::Dean ? __('Attendance') : __('My classes'),
            'back' => $user->role === UserRole::Dean
                ? action([DeanAttendanceController::class, 'index'], ['term' => $schedule->term_id, 'date' => $day->toDateString(), 'section' => $offering->section_id])
                : action([ClassController::class, 'index'], ['term' => $schedule->term_id, 'date' => $day->toDateString()]),
        ]);
    }

    public function update(Request $request, Schedule $schedule, string $date, ClassDays $days, ClassList $list): RedirectResponse
    {
        Gate::authorize('takeAttendance', $schedule);
        /** @var User $user */
        $user = $request->user();
        $day = self::date($date);

        $refusal = $days->refusal($schedule, $day);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['marks' => $refusal]);
        }

        $validated = $request->validate([
            'marks' => ['required', 'array'],
            'marks.*.enrollment_id' => ['required', 'integer', 'distinct'],
            'marks.*.status' => ['required', Rule::enum(AttendanceStatus::class)],
        ]);

        $marks = [];

        foreach ($validated['marks'] as $mark) {
            $marks[(int) $mark['enrollment_id']] = AttendanceStatus::from($mark['status']);
        }

        $counts = $list->record($schedule, $day, $marks, $user);

        $saved = $counts['created'] + $counts['changed'];
        Inertia::flash('toast', ['type' => 'success', 'message' => $saved === 0
            ? __('Nothing changed.')
            : trans_choice('{1} :count mark saved.|[2,*] :count marks saved.', $saved, ['count' => $saved])]);

        return back();
    }

    private static function date(string $date): CarbonImmutable
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        abort_if($day === null || $day->toDateString() !== $date, 404);

        return $day;
    }
}
