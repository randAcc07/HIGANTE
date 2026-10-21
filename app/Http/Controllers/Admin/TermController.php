<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TermPhase;
use App\Http\Controllers\Admin\Concerns\RefusesDeletingInUse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TermRequest;
use App\Models\AttendancePolicy;
use App\Models\Term;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TermController extends Controller
{
    use RefusesDeletingInUse;

    public function index(): Response
    {
        return Inertia::render('admin/terms/Index', [
            'terms' => Term::query()
                ->with('attendancePolicy')
                ->withCount(['sections', 'termRegistrations'])
                ->orderByDesc('starts_on')
                ->get()
                ->map(fn (Term $term): array => [...$term->toArray(), 'phase' => $term->phase()->value]),
            'handbookPolicy' => AttendancePolicy::HANDBOOK,
        ]);
    }

    public function store(TermRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $term = Term::create($request->safe()->only(['school_year', 'semester', 'starts_on', 'ends_on']));
            $term->attendancePolicy()->create($this->policy($request));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Term added.')]);

        return back();
    }

    public function update(TermRequest $request, Term $term): RedirectResponse
    {
        if ($term->isClosed()) {
            return $this->refuseClosed($term);
        }

        DB::transaction(function () use ($request, $term): void {
            $term->update($request->safe()->only(['school_year', 'semester', 'starts_on', 'ends_on']));
            $term->attendancePolicy()->updateOrCreate([], $this->policy($request));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Term updated.')]);

        return back();
    }

    /**
     * Close an ended term once its records are final: it becomes read-only.
     */
    public function close(Request $request, Term $term): RedirectResponse
    {
        if ($term->phase() !== TermPhase::Ended) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Only a term that has ended can be closed.')]);

            return back();
        }

        $term->forceFill(['closed_at' => now(), 'closed_by' => $request->user()?->id])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':term closed. Its records are now read-only.', ['term' => $term->label])]);

        return back();
    }

    /**
     * Undo a close, e.g. to correct a record.
     */
    public function reopen(Term $term): RedirectResponse
    {
        $term->forceFill(['closed_at' => null, 'closed_by' => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':term reopened.', ['term' => $term->label])]);

        return back();
    }

    public function destroy(Term $term): RedirectResponse
    {
        $refusal = $this->refuseIfInUse($term->label, [
            'section' => $term->sections()->count(),
            'registration' => $term->termRegistrations()->count(),
        ]);

        if ($refusal !== null) {
            return $refusal;
        }

        // The attendance policy goes with it (cascade).
        $term->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Term deleted.')]);

        return back();
    }

    private function refuseClosed(Term $term): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __(':term is closed. Reopen it to make changes.', ['term' => $term->label])]);

        return back();
    }

    /**
     * @return array{lates_per_absence: int, drop_at_absences: int, warn_at_absences: list<int>, teacher_edit_days: int|null}
     */
    private function policy(TermRequest $request): array
    {
        return [
            'lates_per_absence' => $request->integer('lates_per_absence'),
            'drop_at_absences' => $request->integer('drop_at_absences'),
            'warn_at_absences' => $request->warnAt(),
            'teacher_edit_days' => $request->filled('teacher_edit_days') ? $request->integer('teacher_edit_days') : null,
        ];
    }
}
