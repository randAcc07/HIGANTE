<?php

namespace App\Http\Controllers\Dean;

use App\Enums\EnrollmentStatus;
use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use App\Scheduling\LearnerConflicts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dean's registered learners for a term: the unsectioned queue,
 * irregulars, and each learner's section, subjects and batches.
 */
class LearnerController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validate([
            'term' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'program' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer', 'between:1,6'],
            'section' => ['nullable', 'string', 'max:20'],
            'irregular' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::enum(RegistrationStatus::class)],
        ]) + ['term' => null, 'search' => null, 'program' => null, 'year' => null, 'section' => null, 'irregular' => null, 'status' => null];

        $terms = Term::orderByDesc('starts_on')->get();
        $term = $terms->firstWhere('id', $filters['term']) ?? Term::forViewing();
        $programs = Program::where('college_id', $user->college_id)->orderBy('code')->get(['id', 'code', 'name']);
        $inCollege = TermRegistration::query()
            ->where('term_registrations.term_id', $term?->id)
            ->whereHas('learner', fn ($learner) => $learner->whereIn('program_id', $programs->pluck('id')));

        $registrations = $term === null ? null : (clone $inCollege)
            ->with(['learner.user', 'learner.program:id,code', 'section:id,name'])
            ->where('term_registrations.status', $filters['status'] ?? RegistrationStatus::Registered->value)
            ->when($filters['search'], fn ($query, string $search) => $query->whereHas('learner', fn ($learner) => $learner->matching($search)))
            ->when($filters['program'], fn ($query, int $program) => $query->whereHas('learner', fn ($learner) => $learner->where('program_id', $program)))
            ->when($filters['year'], fn ($query, int $year) => $query->where('term_registrations.year_level', $year))
            ->when($filters['section'] === 'none', fn ($query) => $query->whereNull('section_id'))
            ->when(is_numeric($filters['section']), fn ($query) => $query->where('section_id', (int) $filters['section']))
            ->when($filters['irregular'] !== null, fn ($query) => $query->where('is_irregular', (bool) $filters['irregular']))
            ->join('learners', 'learners.id', '=', 'term_registrations.learner_id')
            ->join('users', 'users.id', '=', 'learners.user_id')
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->select('term_registrations.*')
            ->addSelect(['subjects_count' => Enrollment::query()
                ->selectRaw('count(*)')
                ->whereColumn('enrollments.learner_id', 'term_registrations.learner_id')
                ->where('enrollments.status', '!=', EnrollmentStatus::Dropped->value)
                ->whereHas('subjectOffering.section', fn ($section) => $section->whereColumn('sections.term_id', 'term_registrations.term_id'))])
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('dean/learners/Index', [
            // The next term, while another is shown: these pages prepare it.
            'nextTerm' => Term::nextTermShortcut($term),
            'terms' => $terms->map(fn (Term $term): array => ['id' => $term->id, 'label' => $term->label, 'phase' => $term->phase()->value]),
            'term' => $term === null ? null : ['id' => $term->id, 'label' => $term->label, 'phase' => $term->phase()->value],
            'programs' => $programs,
            'sections' => $term === null ? [] : Section::with('program:id,code')
                ->where('term_id', $term->id)
                ->whereIn('program_id', $programs->pluck('id'))
                ->orderBy('program_id')->orderBy('year_level')->orderBy('name')
                ->get(['id', 'program_id', 'year_level', 'name']),
            'registrations' => $registrations,
            'unsectioned' => (clone $inCollege)->where('status', RegistrationStatus::Registered)->whereNull('section_id')->count(),
            'filters' => $filters,
        ]);
    }

    /**
     * One learner's term: section, subjects with their batches, and the
     * resulting week.
     */
    public function show(TermRegistration $registration, LearnerConflicts $conflicts): Response
    {
        Gate::authorize('view', $registration);

        $registration->load(['learner.user', 'learner.program:id,college_id,code,name', 'section:id,name,capacity', 'term']);
        $learner = $registration->learner;
        $termId = $registration->term_id;

        $enrollments = Enrollment::query()
            ->with(['subjectOffering.subject:id,code,title,lecture_units,laboratory_units', 'subjectOffering.section.program:id,code', 'subjectOffering.batches' => fn ($batches) => $batches->orderBy('name'), 'batch:id,name'])
            ->withCount('attendanceRecords')
            ->where('learner_id', $learner->id)
            ->whereHas('subjectOffering.section', fn ($query) => $query->where('term_id', $termId))
            ->get()
            ->sortBy(fn (Enrollment $enrollment): string => ($enrollment->status === EnrollmentStatus::Dropped ? '1' : '0').$enrollment->subjectOffering->subject->code)
            ->values();
        $taken = $enrollments->where('status', '!=', EnrollmentStatus::Dropped)->pluck('subject_offering_id');

        $offerings = SubjectOffering::query()
            ->with(['subject:id,code,title', 'section.program:id,code', 'batches' => fn ($batches) => $batches->orderBy('name')])
            ->whereHas('section', fn ($section) => $section->where('term_id', $termId)->whereHas('program', fn ($program) => $program->where('college_id', $learner->program->college_id)))
            ->whereNotIn('id', $taken)
            ->get()
            ->sortBy(fn (SubjectOffering $offering): string => $offering->subject->code.' '.$offering->section->program->code.' '.$offering->section->name)
            ->values();

        $sections = Section::query()
            ->withCount(['termRegistrations as members_count' => fn ($query) => $query->where('status', RegistrationStatus::Registered)])
            ->where('term_id', $termId)
            ->where('program_id', $learner->program_id)
            ->where('year_level', $registration->year_level)
            ->orderBy('name')
            ->get(['id', 'name', 'capacity']);

        $classes = $conflicts->classes($conflicts->subjects($learner, $termId));

        return Inertia::render('dean/learners/Show', [
            'registration' => [
                'id' => $registration->id,
                'year_level' => $registration->year_level,
                'is_irregular' => $registration->is_irregular,
                'status' => $registration->status->value,
                'section' => $registration->section,
                'term' => ['id' => $registration->term->id, 'label' => $registration->term->label, 'phase' => $registration->term->phase()->value],
                'learner' => [
                    'id' => $learner->id,
                    'full_name' => $learner->user->full_name,
                    'student_number' => $learner->student_number,
                    'program' => $learner->program->only(['id', 'code', 'name']),
                ],
            ],
            'enrollments' => $enrollments->map(fn (Enrollment $enrollment): array => [
                'id' => $enrollment->id,
                'status' => $enrollment->status->value,
                'batch_id' => $enrollment->batch_id,
                'attendance_count' => $enrollment->attendance_records_count,
                'offering' => self::offering($enrollment->subjectOffering),
            ]),
            'offerings' => $offerings->map(fn (SubjectOffering $offering): array => self::offering($offering)),
            'sections' => $sections,
            'classes' => $classes->map(fn (Schedule $schedule): array => ScheduleBlocks::block($schedule))->values(),
            'canEdit' => Gate::allows('place', $registration),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function offering(SubjectOffering $offering): array
    {
        return [
            'id' => $offering->id,
            'subject' => $offering->subject->only(['id', 'code', 'title', 'lecture_units', 'laboratory_units']),
            'section' => "{$offering->section->program->code} {$offering->section->name}",
            'batches' => $offering->batches->map(fn ($batch): array => ['id' => $batch->id, 'name' => $batch->name])->values(),
        ];
    }
}
