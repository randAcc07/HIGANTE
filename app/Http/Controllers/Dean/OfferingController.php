<?php

namespace App\Http\Controllers\Dean;

use App\Http\Controllers\Admin\Concerns\RefusesDeletingInUse;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Teacher;
use App\Registration\TermRegistrar;
use App\Scheduling\ScheduleConflicts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A section's subjects and their default teacher.
 */
class OfferingController extends Controller
{
    use RefusesDeletingInUse;

    /**
     * Add a subject; the section's regular learners are enrolled in it.
     */
    public function store(Request $request, Section $section, TermRegistrar $registrar): RedirectResponse
    {
        Gate::authorize('update', $section);
        $collegeId = $section->program->college_id;

        $validated = $request->validate([
            'subject_id' => [
                'required', 'integer',
                Rule::exists(Subject::class, 'id')->where('college_id', $collegeId),
                Rule::unique(SubjectOffering::class)->where('section_id', $section->id),
            ],
            'teacher_id' => ['nullable', 'integer', self::teacherRule($collegeId)],
        ], ['subject_id.unique' => __('The section already has this subject.')]);

        $enrolled = DB::transaction(function () use ($section, $validated, $registrar): int {
            $offering = $section->subjectOfferings()->create($validated);
            $registrar->enrollSectionIn($offering);

            return $offering->enrollments()->count();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $enrolled === 0
            ? __('Subject added.')
            : trans_choice('{1} Subject added; :count regular learner enrolled.|[2,*] Subject added; :count regular learners enrolled.', $enrolled, ['count' => $enrolled])]);

        return back();
    }

    /**
     * Change the default teacher, optionally moving the subject's existing
     * classes to them too (e.g. a substitute), conflict-checked.
     */
    public function update(Request $request, SubjectOffering $offering, ScheduleConflicts $checker): RedirectResponse
    {
        Gate::authorize('update', $offering->section);

        $validated = $request->validate([
            'teacher_id' => ['nullable', 'integer', self::teacherRule($offering->section->program->college_id)],
            'apply_to_classes' => ['boolean'],
        ]);

        $teacherId = $validated['teacher_id'] ?? null;
        $moveClasses = ($validated['apply_to_classes'] ?? false) && $teacherId !== null;

        DB::transaction(function () use ($offering, $teacherId, $moveClasses, $checker): void {
            $offering->update(['teacher_id' => $teacherId]);

            if (! $moveClasses) {
                return;
            }

            foreach ($offering->schedules as $schedule) {
                $schedule->teacher_id = $teacherId;
                $conflicts = $checker->teacherConflicts($schedule);

                if ($conflicts !== []) {
                    throw ValidationException::withMessages(array_combine(
                        array_map(fn (int $i): string => "teacher_id.{$i}", array_keys($conflicts)),
                        $conflicts,
                    ));
                }

                $schedule->save();
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $moveClasses ? __('Teacher changed for the subject and its classes.') : __('Default teacher changed.')]);

        return back();
    }

    /**
     * Remove a subject while nothing has been recorded for it; its
     * enrollments go with it.
     */
    public function destroy(SubjectOffering $offering): RedirectResponse
    {
        Gate::authorize('update', $offering->section);

        $refusal = $this->refuseIfInUse(
            $offering->subject->code,
            ['attendance record' => AttendanceRecord::whereIn('enrollment_id', $offering->enrollments()->select('id'))->count()],
        );

        if ($refusal !== null) {
            return $refusal;
        }

        DB::transaction(function () use ($offering): void {
            $offering->enrollments()->delete();
            // Batches and classes cascade.
            $offering->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subject removed from the section.')]);

        return back();
    }

    /**
     * An active teacher of the college.
     */
    public static function teacherRule(int $collegeId): Exists
    {
        return Rule::exists(Teacher::class, 'id')->where(fn ($query) => $query->whereIn(
            'user_id',
            fn ($users) => $users->select('id')->from('users')->where('college_id', $collegeId)->where('is_active', true),
        ));
    }
}
