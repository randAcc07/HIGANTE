<?php

namespace App\Http\Controllers\Dean;

use App\Http\Controllers\Admin\Concerns\RefusesDeletingInUse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dean\SectionRequest;
use App\Models\Program;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Scheduling\ScheduleConflicts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dean's sections for one term, and each section's subjects, batches
 * and weekly timetable.
 */
class SectionController extends Controller
{
    use RefusesDeletingInUse;

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $terms = Term::orderByDesc('starts_on')->get();
        $term = $terms->firstWhere('id', $request->integer('term')) ?? Term::forPreparation() ?? Term::forViewing();

        $programs = Program::where('college_id', $user->college_id)->orderBy('code')->get(['id', 'code', 'name']);

        $sections = $term === null ? collect() : Section::query()
            ->with('program:id,code')
            ->withCount(['termRegistrations', 'subjectOfferings'])
            ->where('term_id', $term->id)
            ->whereIn('program_id', $programs->pluck('id'))
            ->orderBy('program_id')
            ->orderBy('year_level')
            ->orderBy('name')
            ->get();

        return Inertia::render('dean/sections/Index', [
            'terms' => $terms->map(fn (Term $term): array => ['id' => $term->id, 'label' => $term->label, 'phase' => $term->phase()->value]),
            'term' => $term === null ? null : ['id' => $term->id, 'label' => $term->label, 'phase' => $term->phase()->value],
            'programs' => $programs,
            'sections' => $sections,
            'canEdit' => $term !== null && ! $term->isClosed(),
        ]);
    }

    public function store(SectionRequest $request): RedirectResponse
    {
        $program = Program::findOrFail($request->integer('program_id'));
        $term = Term::findOrFail($request->integer('term_id'));
        Gate::authorize('create', [Section::class, $program, $term]);

        $section = Section::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Section :name added.', ['name' => "{$program->code} {$section->name}"])]);

        return to_route('dean.sections.show', $section);
    }

    public function show(Section $section, ScheduleConflicts $checker): Response
    {
        Gate::authorize('view', $section);

        $section->load(['program:id,college_id,code,name', 'term']);
        $offerings = $section->subjectOfferings()
            ->with(['subject:id,code,title,lecture_units,laboratory_units', 'teacher.user:id,first_name,middle_name,last_name,suffix', 'batches' => fn ($batches) => $batches->withCount(['enrollments', 'schedules'])->orderBy('name')])
            ->withCount('enrollments')
            ->orderBy('id')
            ->get();

        $schedules = Schedule::query()
            ->with(['room', 'teacher.user', 'batch', 'subjectOffering.subject', 'subjectOffering.section'])
            ->withCount('attendanceRecords')
            ->whereIn('subject_offering_id', $offerings->pluck('id'))
            ->orderBy('starts_at')
            ->get();

        $collegeId = $section->program->college_id;

        return Inertia::render('dean/sections/Show', [
            'section' => [
                ...$section->only(['id', 'name', 'year_level', 'capacity']),
                'program' => $section->program->only(['id', 'code', 'name']),
                'term' => ['id' => $section->term->id, 'label' => $section->term->label, 'phase' => $section->term->phase()->value],
                'registered' => $section->termRegistrations()->count(),
            ],
            'offerings' => $offerings,
            'classes' => $schedules->map(fn (Schedule $schedule): array => [
                ...ScheduleBlocks::block($schedule),
                'warnings' => $checker->warnings($schedule),
                'has_attendance' => $schedule->attendance_records_count > 0,
            ]),
            'subjects' => Subject::where('college_id', $collegeId)
                ->whereNotIn('id', $offerings->pluck('subject_id'))
                ->orderBy('code')
                ->get(['id', 'code', 'title']),
            'teachers' => ScheduleBlocks::teacherOptions($collegeId),
            'rooms' => Room::where('is_active', true)->orderBy('building')->orderBy('code')->get(['id', 'code', 'name', 'building', 'capacity', 'room_type']),
            'canEdit' => Gate::allows('update', $section),
        ]);
    }

    public function update(SectionRequest $request, Section $section): RedirectResponse
    {
        Gate::authorize('update', $section);

        $section->update($request->safe()->only(['name', 'capacity']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Section updated.')]);

        return back();
    }

    public function destroy(Section $section): RedirectResponse
    {
        Gate::authorize('delete', $section);

        $refusal = $this->refuseIfInUse("{$section->program->code} {$section->name}", [
            'registered learner' => $section->termRegistrations()->count(),
            'enrollment' => $section->subjectOfferings()->withCount('enrollments')->get()->sum('enrollments_count'),
        ]);

        if ($refusal !== null) {
            return $refusal;
        }

        $termId = $section->term_id;

        // Its subjects go with it; their batches and classes cascade.
        DB::transaction(function () use ($section): void {
            $section->subjectOfferings()->delete();
            $section->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Section deleted.')]);

        return to_route('dean.sections.index', ['term' => $termId]);
    }
}
