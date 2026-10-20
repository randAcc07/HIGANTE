<?php

namespace App\Http\Controllers\Dean;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dean\ScheduleRequest;
use App\Models\Schedule;
use App\Models\SubjectOffering;
use App\Scheduling\LearnerConflicts;
use App\Scheduling\ScheduleConflicts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A subject's weekly classes.
 */
class ScheduleController extends Controller
{
    public function __construct(private ScheduleConflicts $checker, private LearnerConflicts $learners) {}

    public function store(ScheduleRequest $request, SubjectOffering $offering): RedirectResponse
    {
        Gate::authorize('update', $offering->section);

        return $this->save($request->candidate(), __('Class added.'));
    }

    public function update(ScheduleRequest $request, Schedule $schedule): RedirectResponse
    {
        Gate::authorize('update', $schedule->subjectOffering->section);

        return $this->save($request->candidate(), __('Class updated.'));
    }

    public function destroy(Schedule $schedule): RedirectResponse
    {
        Gate::authorize('update', $schedule->subjectOffering->section);

        if ($schedule->attendanceRecords()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This class already has attendance, so it stays.')]);

            return back();
        }

        $schedule->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Class removed.')]);

        return back();
    }

    private function save(Schedule $schedule, string $message): RedirectResponse
    {
        try {
            $schedule->save();
        } catch (UniqueConstraintViolationException) {
            // Someone booked the room or teacher between the check and the save.
            throw ValidationException::withMessages(['slot.0' => __('That room or teacher was just booked by someone else. Check the timetable and try again.')]);
        }

        $warnings = [...$this->checker->warnings($schedule), ...$this->learners->clashesWith($schedule)];

        Inertia::flash('toast', $warnings === []
            ? ['type' => 'success', 'message' => $message]
            : ['type' => 'warning', 'message' => $message.' '.implode(' ', $warnings)]);

        return back();
    }
}
