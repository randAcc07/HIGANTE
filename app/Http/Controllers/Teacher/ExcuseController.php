<?php

namespace App\Http\Controllers\Teacher;

use App\Attendance\Excuses;
use App\Attendance\ExcuseView;
use App\Enums\DecisionStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExcuseReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExcuseRequest;
use App\Http\Requests\TeacherExcuseRequest;
use App\Models\AbsenceExcuse;
use App\Models\ExcuseDecision;
use App\Models\Learner;
use App\Models\Schedule;
use App\Models\SubjectOffering;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A teacher's excuses: the class decisions they may make (whoever teaches
 * the learner's meetings of a class now decides it), and excuses they file
 * for a learner in one of their classes.
 */
class ExcuseController extends Controller
{
    public function index(Request $request): Response
    {
        $teacherId = self::user($request)->teacher->id ?? abort(403);
        $filters = $request->validate([
            'term' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(DecisionStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]) + ['term' => null, 'status' => null, 'search' => null];
        $terms = Term::orderByDesc('starts_on')->get();
        $term = $terms->firstWhere('id', $filters['term']) ?? Term::forViewing();
        $filters['term'] = $term?->id;
        $filters['status'] ??= DecisionStatus::Pending->value;

        // The term's classes: a decision belongs to its class's term.
        $mine = fn (): Builder => Excuses::decidableBy($teacherId)
            ->when($term, fn ($query, Term $term) => $query->whereRelation('subjectOffering.section', 'term_id', $term->id));

        $page = $mine()
            ->with(['absenceExcuse.learner.user', 'absenceExcuse.learner.program:id,code', 'absenceExcuse.documents', 'subjectOffering.subject:id,code,title', 'subjectOffering.section.program:id,code', 'decider'])
            ->where('status', $filters['status'])
            ->when($filters['search'], fn ($query, string $search) => $query->whereHas('absenceExcuse.learner', fn ($learner) => $learner->matching($search)))
            ->orderBy('created_at', $filters['status'] === DecisionStatus::Pending->value ? 'asc' : 'desc')
            ->paginate(25)
            ->withQueryString();

        $rows = $page->getCollection()->map(fn (ExcuseDecision $decision): array => [
            ...ExcuseView::decision($decision),
            'excuse' => [
                'date_from' => $decision->absenceExcuse->date_from->toDateString(),
                'date_to' => $decision->absenceExcuse->date_to->toDateString(),
                'reason' => $decision->absenceExcuse->reason->value,
                'documents' => $decision->absenceExcuse->documents->count(),
                'days_late' => $decision->absenceExcuse->daysLate(),
                'submitted_on' => $decision->absenceExcuse->created_at?->toDateString(),
            ],
            'learner' => [
                'name' => $decision->absenceExcuse->learner->user->full_name,
                'student_number' => $decision->absenceExcuse->learner->student_number,
                'program' => $decision->absenceExcuse->learner->program->code,
            ],
        ]);

        return Inertia::render('teacher/excuses/Index', [
            'terms' => $terms->map(fn (Term $term): array => ['id' => $term->id, 'label' => $term->label]),
            'term' => $term === null ? null : ['id' => $term->id, 'label' => $term->label],
            'decisions' => [...$page->toArray(), 'data' => $rows->values()->all()],
            'counts' => collect(DecisionStatus::cases())->mapWithKeys(fn (DecisionStatus $status): array => [$status->value => $mine()->where('status', $status)->count()]),
            'filters' => $filters,
            'learners' => self::learnerOptions($teacherId),
            'reasons' => ExcuseReason::cases(),
            'maxDays' => ExcuseRequest::MAX_DAYS,
            'dueWithinDays' => AbsenceExcuse::DUE_WITHIN_DAYS,
        ]);
    }

    /**
     * File an excuse for a learner in one of the teacher's classes; it is
     * approved in the teacher's own classes, and the learner's other
     * teachers decide theirs.
     */
    public function store(TeacherExcuseRequest $request, Excuses $excuses): RedirectResponse
    {
        $user = self::user($request);
        $teacherId = $user->teacher->id ?? abort(403);
        $learner = $request->learner() ?? abort(404);

        if (! collect(self::learnerOptions($teacherId))->contains('id', $learner->id)) {
            throw ValidationException::withMessages(['learner_id' => __('Choose a learner in one of your classes.')]);
        }

        /** @var list<UploadedFile> $files */
        $files = array_values($request->file('files', []));
        /** @var array{date_from: string, date_to: string, reason: string, remarks?: string|null} $data */
        $data = $request->safe()->only(['date_from', 'date_to', 'reason', 'remarks']);
        $excuse = $excuses->submit($learner, $data, $files, $user);

        $mine = $excuse->decisions()->where('decided_by', $user->id)->count();
        $others = $excuse->decisions()->where('status', DecisionStatus::Pending)->count();

        Inertia::flash('toast', ['type' => 'success', 'message' => trim(trans_choice('{0} Excuse filed.|{1} Excuse filed and approved for your class.|[2,*] Excuse filed and approved for your :count classes.', $mine, ['count' => $mine])
            .' '.($others > 0 ? trans_choice('{1} :count other class waits for its teacher.|[2,*] :count other classes wait for their teachers.', $others, ['count' => $others]) : ''))]);

        return back();
    }

    /**
     * The subjects the teacher teaches (a class, a batch's lab, or by default).
     *
     * @return list<int>
     */
    public static function offeringIds(int $teacherId): array
    {
        return array_values(Schedule::where('teacher_id', $teacherId)->distinct()->pluck('subject_offering_id')
            ->merge(SubjectOffering::where('teacher_id', $teacherId)->pluck('id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all());
    }

    /**
     * Learners in the teacher's classes this term, for filing.
     *
     * @return list<array{id: int, name: string, student_number: string}>
     */
    private static function learnerOptions(int $teacherId): array
    {
        $term = Term::forViewing();

        return array_values(Learner::query()
            ->with('user')
            ->whereHas('enrollments', fn ($query) => $query
                ->whereIn('subject_offering_id', self::offeringIds($teacherId))
                ->where('status', '!=', EnrollmentStatus::Dropped)
                ->whereHas('subjectOffering.section', fn ($section) => $section->where('term_id', $term?->id)))
            ->get()
            ->sortBy(fn (Learner $learner): string => $learner->user->last_name.' '.$learner->user->first_name)
            ->map(fn (Learner $learner): array => ['id' => $learner->id, 'name' => $learner->user->last_name.', '.$learner->user->first_name, 'student_number' => $learner->student_number])
            ->all());
    }

    private static function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
