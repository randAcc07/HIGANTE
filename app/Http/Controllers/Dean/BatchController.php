<?php

namespace App\Http\Controllers\Dean;

use App\Http\Controllers\Admin\Concerns\RefusesDeletingInUse;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Registration\Placement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Splits of a subject's learners (e.g. 1-AA / 1-AB), usually for labs.
 */
class BatchController extends Controller
{
    use RefusesDeletingInUse;

    public function store(Request $request, SubjectOffering $offering): RedirectResponse
    {
        Gate::authorize('update', $offering->section);

        $offering->batches()->create($this->validated($request, $offering));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Batch added.')]);

        return back();
    }

    public function update(Request $request, Batch $batch): RedirectResponse
    {
        Gate::authorize('update', $batch->subjectOffering->section);

        $batch->update($this->validated($request, $batch->subjectOffering, $batch));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Batch renamed.')]);

        return back();
    }

    public function destroy(Batch $batch): RedirectResponse
    {
        Gate::authorize('update', $batch->subjectOffering->section);

        $refusal = $this->refuseIfInUse($batch->name, [
            'class' => $batch->schedules()->count(),
            'learner' => $batch->enrollments()->count(),
        ]);

        if ($refusal !== null) {
            return $refusal;
        }

        $batch->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Batch deleted.')]);

        return back();
    }

    /**
     * The section's batch roster: each learner's batch in each split
     * subject, saved as a whole and refused if anyone would be in two
     * classes at once.
     */
    public function roster(Request $request, Section $section, Placement $placement): RedirectResponse
    {
        Gate::authorize('update', $section);

        $validated = $request->validate([
            'batches' => ['present', 'array'],
            'batches.*.enrollment_id' => ['required', 'integer', 'distinct'],
            'batches.*.batch_id' => ['nullable', 'integer'],
        ]);

        $offeringIds = $section->subjectOfferings()->pluck('id');
        $enrollments = Enrollment::whereIn('id', array_column($validated['batches'], 'enrollment_id'))->whereIn('subject_offering_id', $offeringIds)->get()->keyBy('id');
        $batches = Batch::whereIn('subject_offering_id', $offeringIds)->get()->keyBy('id');
        $assignments = [];

        foreach ($validated['batches'] as $i => $row) {
            $enrollment = $enrollments->get($row['enrollment_id']);
            $batch = $row['batch_id'] === null ? null : $batches->get($row['batch_id']);

            if ($enrollment === null || ($row['batch_id'] !== null && $batch?->subject_offering_id !== $enrollment->subject_offering_id)) {
                throw ValidationException::withMessages(["batches.{$i}" => __('The roster is out of date. Reload the page and try again.')]);
            }

            $assignments[$enrollment->id] = $batch?->id;
        }

        $placement->assignBatches($assignments);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Batches saved.')]);

        return back();
    }

    /**
     * @return array{name: string}
     */
    private function validated(Request $request, SubjectOffering $offering, ?Batch $batch = null): array
    {
        /** @var array{name: string} */
        return $request->validate([
            'name' => ['required', 'string', 'max:30', Rule::unique(Batch::class)->where('subject_offering_id', $offering->id)->ignore($batch?->id)],
        ], ['name.unique' => __('The subject already has a batch with that name.')]);
    }
}
