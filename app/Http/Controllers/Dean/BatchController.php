<?php

namespace App\Http\Controllers\Dean;

use App\Http\Controllers\Admin\Concerns\RefusesDeletingInUse;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\SubjectOffering;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
