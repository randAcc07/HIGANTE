<?php

namespace App\Http\Controllers\Dean;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\TermRegistration;
use App\Models\User;
use App\Registration\Placement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Placing a learner in a section, and their subjects and batches one by
 * one. Every change is checked against the learner's own week.
 */
class PlacementController extends Controller
{
    public function __construct(private Placement $placement) {}

    public function section(Request $request, TermRegistration $registration): RedirectResponse
    {
        Gate::authorize('place', $registration);
        /** @var User $dean */
        $dean = $request->user();

        $request->validate([
            'section_id' => ['required', 'integer', Rule::exists(Section::class, 'id')
                ->where('term_id', $registration->term_id)
                ->where('program_id', $registration->learner->program_id)
                ->where('year_level', $registration->year_level)],
        ], ['section_id.exists' => __('Choose a section of the learner\'s program and year level this term.')]);

        $section = Section::with('program')->findOrFail($request->integer('section_id'));
        $warning = $this->placement->place($registration, $section, $dean);

        $message = $registration->is_irregular
            ? __(':name is now in :section; their subjects stay as they were.', ['name' => $registration->learner->user->full_name, 'section' => "{$section->program->code} {$section->name}"])
            : __(':name is now in :section and takes its subjects.', ['name' => $registration->learner->user->full_name, 'section' => "{$section->program->code} {$section->name}"]);
        Inertia::flash('toast', ['type' => $warning === null ? 'success' : 'warning', 'message' => trim($message.' '.$warning)]);

        return back();
    }

    public function enroll(Request $request, TermRegistration $registration): RedirectResponse
    {
        Gate::authorize('place', $registration);
        /** @var User $dean */
        $dean = $request->user();

        $request->validate([
            'subject_offering_id' => ['required', 'integer', Rule::exists(SubjectOffering::class, 'id')],
            'batch_id' => ['nullable', 'integer', Rule::exists(Batch::class, 'id')->where('subject_offering_id', $request->integer('subject_offering_id'))],
        ]);

        $offering = SubjectOffering::with(['section.program', 'subject'])->findOrFail($request->integer('subject_offering_id'));
        Gate::authorize('update', $offering->section);

        if ($offering->section->term_id !== $registration->term_id) {
            throw ValidationException::withMessages(['subject_offering_id' => __('That subject is offered in another term.')]);
        }

        $batch = $request->filled('batch_id') ? Batch::findOrFail($request->integer('batch_id')) : null;
        $wasRegular = ! $registration->is_irregular;
        $this->placement->enroll($registration, $offering, $batch, $dean);

        Inertia::flash('toast', ['type' => 'success', 'message' => trim(__(':subject of :section added.', ['subject' => $offering->subject->code, 'section' => "{$offering->section->program->code} {$offering->section->name}"])
            .' '.($wasRegular ? __('The learner is now irregular.') : ''))]);

        return back();
    }

    /**
     * Change the batch of one of the learner's subjects.
     */
    public function batch(Request $request, Enrollment $enrollment): RedirectResponse
    {
        $registration = $this->authorizeEnrollment($enrollment);

        $batchId = $request->validate([
            'batch_id' => ['nullable', 'integer', Rule::exists(Batch::class, 'id')->where('subject_offering_id', $enrollment->subject_offering_id)],
        ])['batch_id'] ?? null;

        $this->placement->assignBatches([$enrollment->id => $batchId === null ? null : (int) $batchId]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Batch changed for :name.', ['name' => $registration->learner->user->full_name])]);

        return back();
    }

    public function remove(Request $request, Enrollment $enrollment): RedirectResponse
    {
        $registration = $this->authorizeEnrollment($enrollment);
        /** @var User $dean */
        $dean = $request->user();

        $wasRegular = ! $registration->is_irregular;
        $recorded = $enrollment->attendanceRecords()->exists();
        $this->placement->remove($registration, $enrollment, $dean);

        Inertia::flash('toast', ['type' => 'success', 'message' => trim(($recorded
            ? __('Subject dropped; its attendance stays on record.')
            : __('Subject removed.')).' '.($wasRegular ? __('The learner is now irregular.') : ''))]);

        return back();
    }

    /**
     * The enrollment's registration for its term, which the dean must be
     * able to place.
     */
    private function authorizeEnrollment(Enrollment $enrollment): TermRegistration
    {
        $registration = TermRegistration::query()
            ->where('learner_id', $enrollment->learner_id)
            ->where('term_id', $enrollment->subjectOffering->section->term_id)
            ->firstOrFail();

        Gate::authorize('place', $registration);

        return $registration;
    }
}
