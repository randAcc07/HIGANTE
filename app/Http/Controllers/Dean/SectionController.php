<?php

namespace App\Http\Controllers\Dean;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Admin\Concerns\RefusesDeletingInUse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dean\SectionRequest;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * A section's subjects and batches. Its classes are scheduled on the
     * Timetables page.
     */
    public function show(Section $section): Response
    {
        Gate::authorize('view', $section);

        $section->load(['program:id,college_id,code,name', 'term']);
        $offerings = $section->subjectOfferings()
            ->with(['subject:id,code,title,lecture_units,laboratory_units', 'teacher.user:id,first_name,middle_name,last_name,suffix', 'batches' => fn ($batches) => $batches->withCount(['enrollments', 'schedules'])->orderBy('name')])
            ->withCount(['enrollments', 'schedules'])
            ->orderBy('id')
            ->get();

        // A batch seats as many as the smallest room it meets in (null when
        // unknown); the roster's automatic split sizes batches by it.
        $batches = $offerings->flatMap(fn (SubjectOffering $offering) => $offering->batches->all())->all();
        $roomCapacities = Schedule::query()
            ->join('rooms', 'rooms.id', '=', 'schedules.room_id')
            ->whereIn('schedules.batch_id', array_map(fn (Batch $batch): int => $batch->id, $batches))
            ->whereNotNull('rooms.capacity')
            ->groupBy('schedules.batch_id')
            ->selectRaw('schedules.batch_id, min(rooms.capacity) as capacity')
            ->pluck('capacity', 'batch_id');

        foreach ($batches as $batch) {
            $batch->setAttribute('room_capacity', isset($roomCapacities[$batch->id]) ? (int) $roomCapacities[$batch->id] : null);
        }

        $collegeId = $section->program->college_id;

        return Inertia::render('dean/sections/Show', [
            'section' => [
                ...$section->only(['id', 'name', 'year_level', 'capacity']),
                'program' => $section->program->only(['id', 'code', 'name']),
                'term' => ['id' => $section->term->id, 'label' => $section->term->label, 'phase' => $section->term->phase()->value],
                'registered' => $section->termRegistrations()->count(),
            ],
            'offerings' => $offerings,
            'subjects' => Subject::where('college_id', $collegeId)
                ->whereNotIn('id', $offerings->pluck('subject_id'))
                ->orderBy('code')
                ->get(['id', 'code', 'title']),
            'teachers' => ScheduleBlocks::teacherOptions($collegeId),
            'roster' => $this->roster($section, $offerings),
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

    /**
     * Everyone taking the section's subjects (its members and irregular
     * learners from elsewhere), with their batch in each subject.
     *
     * @param  Collection<int, SubjectOffering>  $offerings
     * @return list<array<string, mixed>>
     */
    private function roster(Section $section, Collection $offerings): array
    {
        $enrollments = Enrollment::with('learner.user')
            ->whereIn('subject_offering_id', $offerings->modelKeys())
            ->where('status', '!=', EnrollmentStatus::Dropped)
            ->get()
            ->groupBy('learner_id');
        $registrations = TermRegistration::with('section:id,name')
            ->where('term_id', $section->term_id)
            ->whereIn('learner_id', $enrollments->keys())
            ->get()
            ->keyBy('learner_id');

        return array_values($enrollments
            ->map(function ($own, int $learnerId) use ($registrations, $section): array {
                $learner = $own->first()->learner;
                $registration = $registrations->get($learnerId);

                return [
                    'learner_id' => $learnerId,
                    'registration_id' => $registration?->id,
                    'name' => $learner->user->last_name.', '.$learner->user->first_name,
                    'student_number' => $learner->student_number,
                    'is_irregular' => (bool) $registration?->is_irregular,
                    // Null when the learner belongs to this section.
                    'from_section' => $registration?->section_id === $section->id ? null : ($registration?->section->name ?? '—'),
                    'enrollments' => $own->mapWithKeys(fn (Enrollment $enrollment): array => [
                        $enrollment->subject_offering_id => ['id' => $enrollment->id, 'batch_id' => $enrollment->batch_id],
                    ])->all(),
                ];
            })
            ->sortBy(fn (array $row): string => ($row['from_section'] === null ? '0' : '1').$row['name'])
            ->all());
    }
}
