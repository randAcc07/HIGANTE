<?php

namespace App\Http\Controllers\Dean;

use App\Enums\Semester;
use App\Http\Controllers\Controller;
use App\Models\CurriculumSubject;
use App\Models\Program;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The curriculum (prospectus) of the dean's programs: which of the
 * college's subjects are taken in which year level and semester. New
 * sections get their subjects from it; editing it changes no section.
 */
class CurriculumController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $programs = Program::where('college_id', $user->college_id)->orderBy('code')->get(['id', 'code', 'name']);
        $program = $programs->firstWhere('id', $request->integer('program')) ?? $programs->first();

        $lines = $program === null ? collect() : $program->curriculum()
            ->with('subject:id,code,title,lecture_units,laboratory_units')
            ->get()
            ->sortBy(fn (CurriculumSubject $line): string => $line->year_level.array_search($line->semester, Semester::cases(), true).$line->subject->code)
            ->values();

        return Inertia::render('dean/curriculum/Index', [
            'programs' => $programs,
            'program' => $program,
            'lines' => $lines->map(fn (CurriculumSubject $line): array => [
                'id' => $line->id,
                'year_level' => $line->year_level,
                'semester' => $line->semester->value,
                'subject' => $line->subject,
            ]),
            'subjects' => $program === null ? [] : Subject::where('college_id', $user->college_id)
                ->whereNotIn('id', $lines->pluck('subject_id'))
                ->orderBy('code')
                ->get(['id', 'code', 'title']),
            'semesters' => array_map(fn (Semester $semester): array => ['value' => $semester->value, 'label' => $semester->label()], Semester::cases()),
        ]);
    }

    /**
     * Add subjects to one year level and semester.
     */
    public function store(Request $request, Program $program): RedirectResponse
    {
        Gate::authorize('editCurriculum', $program);

        $validated = $request->validate([
            ...self::placementRules(),
            'subject_ids' => ['required', 'array', 'min:1'],
            'subject_ids.*' => [
                'integer', 'distinct',
                Rule::exists(Subject::class, 'id')->where('college_id', $program->college_id),
                Rule::unique(CurriculumSubject::class, 'subject_id')->where('program_id', $program->id),
            ],
        ], [
            'subject_ids.required' => __('Pick at least one subject.'),
            'subject_ids.*.unique' => __('That subject is already in the curriculum.'),
        ]);

        foreach ($validated['subject_ids'] as $subjectId) {
            $program->curriculum()->create([
                'subject_id' => $subjectId,
                'year_level' => $validated['year_level'],
                'semester' => $validated['semester'],
            ]);
        }

        $count = count($validated['subject_ids']);
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{1} :count subject added.|[2,*] :count subjects added.', $count, ['count' => $count])]);

        return back();
    }

    /**
     * Move a subject to another year level or semester.
     */
    public function update(Request $request, CurriculumSubject $line): RedirectResponse
    {
        Gate::authorize('editCurriculum', $line->program);

        $line->update($request->validate(self::placementRules()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':code moved.', ['code' => $line->subject->code])]);

        return back();
    }

    public function destroy(CurriculumSubject $line): RedirectResponse
    {
        Gate::authorize('editCurriculum', $line->program);

        $line->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':code removed from the curriculum.', ['code' => $line->subject->code])]);

        return back();
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function placementRules(): array
    {
        return [
            'year_level' => ['required', 'integer', 'between:1,6'],
            'semester' => ['required', Rule::enum(Semester::class)],
        ];
    }
}
