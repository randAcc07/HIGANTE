<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RefusesDeletingInUse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubjectRequest;
use App\Models\College;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubjectController extends Controller
{
    use RefusesDeletingInUse;

    public function index(Request $request): Response
    {
        $filters = [
            'college' => $request->integer('college') ?: null,
            'search' => $request->string('search')->trim()->value(),
        ];

        $subjects = Subject::query()
            ->with('college:id,code')
            ->withCount('offerings')
            ->when($filters['college'], fn ($query, int $college) => $query->where('college_id', $college))
            ->when($filters['search'] !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('code', 'like', "%{$filters['search']}%")
                ->orWhere('title', 'like', "%{$filters['search']}%")))
            ->orderBy('college_id')
            ->orderBy('code')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/subjects/Index', [
            'subjects' => $subjects,
            'colleges' => College::orderBy('code')->get(['id', 'code', 'name']),
            'filters' => $filters,
        ]);
    }

    public function store(SubjectRequest $request): RedirectResponse
    {
        Subject::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subject added.')]);

        return back();
    }

    public function update(SubjectRequest $request, Subject $subject): RedirectResponse
    {
        $subject->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subject updated.')]);

        return back();
    }

    public function destroy(Subject $subject): RedirectResponse
    {
        $refusal = $this->refuseIfInUse($subject->code, [
            'offering' => $subject->offerings()->count(),
            'curriculum' => $subject->curriculumLines()->count(),
        ]);

        if ($refusal !== null) {
            return $refusal;
        }

        $subject->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subject deleted.')]);

        return back();
    }
}
