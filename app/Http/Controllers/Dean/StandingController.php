<?php

namespace App\Http\Controllers\Dean;

use App\Attendance\Standing;
use App\Enums\DropReason;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendancePolicy;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dean's at-risk list: their college's learners with an attendance
 * warning, flagged for dropping, or dropped for absences, and the decisions
 * on them (confirm or dismiss a flag, reinstate a drop).
 */
class StandingController extends Controller
{
    public function index(Request $request, Standing $standing): Response
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validate([
            'term' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['flagged', 'warned', 'dropped'])],
            'section' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]) + ['term' => null, 'status' => null, 'section' => null, 'search' => null];

        $terms = Term::orderByDesc('starts_on')->get();
        $term = $terms->firstWhere('id', $filters['term']) ?? Term::forViewing();
        $programIds = Program::where('college_id', $user->college_id)->pluck('id');
        $inCollege = fn (): Builder => Enrollment::query()->whereHas('subjectOffering.section', fn ($section) => $section
            ->where('term_id', $term?->id)
            ->whereIn('program_id', $programIds));
        $absenceDrop = fn (Builder $query) => $query->where('enrollments.status', EnrollmentStatus::Dropped)->where('drop_reason', DropReason::Absences);

        $page = $inCollege()
            ->with(['learner.user', 'subjectOffering.subject:id,code,title', 'subjectOffering.section.program:id,code'])
            ->where(fn ($query) => match ($filters['status']) {
                'flagged' => $query->where('enrollments.status', EnrollmentStatus::ForDropping),
                'warned' => $query->where('enrollments.status', EnrollmentStatus::Enrolled)->where('warning_level', '>', 0),
                'dropped' => $absenceDrop($query),
                default => $query
                    ->where('enrollments.status', EnrollmentStatus::ForDropping)
                    ->orWhere(fn ($query) => $query->where('enrollments.status', EnrollmentStatus::Enrolled)->where('warning_level', '>', 0))
                    ->orWhere(fn ($query) => $absenceDrop($query)),
            })
            ->when($filters['section'], fn ($query, int $section) => $query->whereHas('subjectOffering', fn ($offering) => $offering->where('section_id', $section)))
            ->when($filters['search'], fn ($query, string $search) => $query->whereHas('learner', fn ($learner) => $learner->matching($search)))
            ->join('learners', 'learners.id', '=', 'enrollments.learner_id')
            ->join('users', 'users.id', '=', 'learners.user_id')
            // Flags first (the dean's to decide), then the highest warnings.
            ->orderByRaw("CASE enrollments.status WHEN 'for_dropping' THEN 0 WHEN 'enrolled' THEN 1 ELSE 2 END")
            ->orderByDesc('warning_level')
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->select('enrollments.*')
            ->paginate(50)
            ->withQueryString();

        $registrations = TermRegistration::where('term_id', $term?->id)
            ->whereIn('learner_id', $page->getCollection()->pluck('learner_id'))
            ->pluck('id', 'learner_id');

        $rows = $page->getCollection()->map(function (Enrollment $enrollment) use ($standing, $registrations): array {
            $offering = $enrollment->subjectOffering;

            return [
                'id' => $enrollment->id,
                'registration_id' => $registrations[$enrollment->learner_id] ?? null,
                'name' => $enrollment->learner->user->last_name.', '.$enrollment->learner->user->first_name,
                'full_name' => $enrollment->learner->user->full_name,
                'student_number' => $enrollment->learner->student_number,
                'subject' => $offering->subject->only(['code', 'title']),
                'section' => "{$offering->section->program->code} {$offering->section->name}",
                'status' => $enrollment->status->value,
                'warning_level' => $enrollment->warning_level,
                'counts' => $standing->counts($enrollment),
                'periods' => $standing->periods($enrollment),
                'flagged_on' => $enrollment->flagged_for_drop_at?->toDateString(),
                'dropped_on' => $enrollment->drop_confirmed_at?->toDateString(),
                'dismissed_at_absences' => $enrollment->drop_dismissed_absences,
                'reinstated_on' => $enrollment->reinstated_at?->toDateString(),
            ];
        });

        return Inertia::render('dean/standing/Index', [
            'terms' => $terms->map(fn (Term $term): array => ['id' => $term->id, 'label' => $term->label, 'phase' => $term->phase()->value]),
            'term' => $term === null ? null : ['id' => $term->id, 'label' => $term->label, 'phase' => $term->phase()->value],
            // The term's policy, else the handbook's (what Standing counts by).
            'policy' => $term === null ? null : ($term->attendancePolicy?->only(['lates_per_absence', 'drop_at_absences', 'warn_at_absences']) ?? AttendancePolicy::HANDBOOK),
            'counts' => $term === null ? null : [
                'flagged' => $inCollege()->where('status', EnrollmentStatus::ForDropping)->count(),
                'warned' => $inCollege()->where('status', EnrollmentStatus::Enrolled)->where('warning_level', '>', 0)->count(),
                'dropped' => $inCollege()->where('status', EnrollmentStatus::Dropped)->where('drop_reason', DropReason::Absences)->count(),
            ],
            'sections' => $term === null ? [] : Section::with('program:id,code')
                ->where('term_id', $term->id)
                ->whereIn('program_id', $programIds)
                ->orderBy('program_id')->orderBy('year_level')->orderBy('name')
                ->get(['id', 'program_id', 'name']),
            'enrollments' => [...$page->toArray(), 'data' => $rows->values()->all()],
            'canDecide' => $term !== null && ! $term->isClosed(),
            'filters' => $filters,
        ]);
    }

    public function drop(Request $request, Enrollment $enrollment, Standing $standing): RedirectResponse
    {
        $this->authorizeDecision($enrollment, EnrollmentStatus::ForDropping);
        /** @var User $dean */
        $dean = $request->user();

        $standing->confirmDrop($enrollment, $dean);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is dropped from :subject.', self::names($enrollment))]);

        return back();
    }

    public function dismiss(Request $request, Enrollment $enrollment, Standing $standing): RedirectResponse
    {
        $this->authorizeDecision($enrollment, EnrollmentStatus::ForDropping);
        /** @var User $dean */
        $dean = $request->user();

        $standing->dismiss($enrollment, $dean);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name stays in :subject; another absence flags them again.', self::names($enrollment))]);

        return back();
    }

    public function reinstate(Request $request, Enrollment $enrollment, Standing $standing): RedirectResponse
    {
        $this->authorizeDecision($enrollment, EnrollmentStatus::Dropped);
        abort_unless($enrollment->drop_reason === DropReason::Absences, 403);
        /** @var User $dean */
        $dean = $request->user();

        $standing->reinstate($enrollment, $dean);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is back in :subject.', self::names($enrollment))]);

        return back();
    }

    /**
     * The dean of the class's college, while its term is open, deciding on
     * an enrollment in the expected state.
     */
    private function authorizeDecision(Enrollment $enrollment, EnrollmentStatus $expected): void
    {
        Gate::authorize('update', $enrollment->subjectOffering->section);

        abort_unless($enrollment->status === $expected, 409, __('This learner\'s standing changed. Reload the page.'));
    }

    /**
     * @return array{name: string, subject: string}
     */
    private static function names(Enrollment $enrollment): array
    {
        return ['name' => $enrollment->learner->user->full_name, 'subject' => $enrollment->subjectOffering->subject->code];
    }
}
