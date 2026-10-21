<?php

namespace App\Http\Controllers\Dean;

use App\Attendance\Excuses;
use App\Enums\AttendanceStatus;
use App\Enums\ExcuseStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Learner\ExcuseController as LearnerExcuseController;
use App\Models\AbsenceExcuse;
use App\Models\AttendanceRecord;
use App\Models\Program;
use App\Models\TermRegistration;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dean's excuse reviews for their college's learners: the queue, one
 * excuse with its files and the absences it covers, and the decisions.
 */
class ExcuseController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ExcuseStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]) + ['status' => null, 'search' => null];
        $filters['status'] ??= ExcuseStatus::Pending->value;

        $programIds = Program::where('college_id', $user->college_id)->pluck('id');
        $inCollege = fn () => AbsenceExcuse::query()->whereHas('learner', fn ($learner) => $learner->whereIn('program_id', $programIds));

        $page = $inCollege()
            ->with(['learner.user', 'learner.program:id,code', 'documents'])
            ->withCount('attendanceRecords')
            ->where('status', $filters['status'])
            ->when($filters['search'], fn ($query, string $search) => $query->whereHas('learner', fn ($learner) => $learner->matching($search)))
            // Pending: oldest first, as a queue; decided: latest first.
            ->orderBy('created_at', $filters['status'] === ExcuseStatus::Pending->value ? 'asc' : 'desc')
            ->paginate(25)
            ->withQueryString();

        $rows = $page->getCollection()->map(fn (AbsenceExcuse $excuse): array => [
            ...LearnerExcuseController::row($excuse),
            'learner' => self::learner($excuse),
        ]);

        return Inertia::render('dean/excuses/Index', [
            'excuses' => [...$page->toArray(), 'data' => $rows->values()->all()],
            'counts' => collect(ExcuseStatus::cases())->mapWithKeys(fn (ExcuseStatus $status): array => [$status->value => $inCollege()->where('status', $status)->count()]),
            'filters' => $filters,
        ]);
    }

    public function show(AbsenceExcuse $excuse, Excuses $service): Response
    {
        Gate::authorize('review', $excuse);
        $excuse->load(['learner.user', 'learner.program:id,code', 'documents', 'reviewer', 'submitter'])->loadCount('attendanceRecords');

        // The learner's absences on the excuse's days, and which excuse (if
        // any) each is linked to; lates in the range stay lates.
        $absences = $service->absences($excuse)
            ->with(['schedule.subjectOffering.subject:id,code', 'schedule.subjectOffering.section.program:id,code', 'schedule.batch:id,name'])
            ->orderBy('class_date')
            ->get()
            ->map(fn (AttendanceRecord $record): array => [
                'id' => $record->id,
                'date' => $record->class_date->toDateString(),
                'start' => $record->schedule->starts_at->format('H:i'),
                'subject' => $record->schedule->subjectOffering->subject->code,
                'section' => "{$record->schedule->subjectOffering->section->program->code} {$record->schedule->subjectOffering->section->name}",
                'batch' => $record->schedule->batch?->name,
                'excuse' => $record->absence_excuse_id === null ? 'none' : ($record->absence_excuse_id === $excuse->id ? 'this' : 'other'),
            ])
            ->sortBy(fn (array $absence): string => $absence['date'].' '.$absence['start'])
            ->values();

        return Inertia::render('dean/excuses/Show', [
            'excuse' => [
                ...LearnerExcuseController::row($excuse),
                'learner' => self::learner($excuse),
                'reviewed_by' => $excuse->reviewer?->full_name,
                'needs_document' => $excuse->reason->requiresDocument(),
            ],
            'absences' => $absences,
            'lates' => AttendanceRecord::query()
                ->where('status', AttendanceStatus::Late)
                ->whereBetween('class_date', [$excuse->date_from->toDateString(), $excuse->date_to->toDateString()])
                ->whereHas('enrollment', fn ($query) => $query->where('learner_id', $excuse->learner_id))
                ->count(),
            'lock' => $service->lock($excuse),
            'dueWithinDays' => AbsenceExcuse::DUE_WITHIN_DAYS,
        ]);
    }

    public function approve(Request $request, AbsenceExcuse $excuse, Excuses $service): RedirectResponse
    {
        Gate::authorize('review', $excuse);
        $remarks = $request->validate(['remarks' => ['nullable', 'string', 'max:2000']])['remarks'] ?? null;

        $service->approve($excuse, self::dean($request), $remarks);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} Excuse approved.|{1} Excuse approved; :count absence excused.|[2,*] Excuse approved; :count absences excused.', $excuse->attendanceRecords()->count(), ['count' => $excuse->attendanceRecords()->count()])]);

        return back();
    }

    public function reject(Request $request, AbsenceExcuse $excuse, Excuses $service): RedirectResponse
    {
        Gate::authorize('review', $excuse);
        $remarks = $request->validate(['remarks' => ['required', 'string', 'max:2000']], ['remarks.required' => __('Tell the learner why.')])['remarks'];

        $service->reject($excuse, self::dean($request), $remarks);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Excuse rejected; the learner is told why.')]);

        return back();
    }

    public function revoke(Request $request, AbsenceExcuse $excuse, Excuses $service): RedirectResponse
    {
        Gate::authorize('review', $excuse);
        $remarks = $request->validate(['remarks' => ['required', 'string', 'max:2000']], ['remarks.required' => __('Tell the learner why.')])['remarks'];

        $service->revoke($excuse, self::dean($request), $remarks);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Excuse revoked; its absences count again.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private static function learner(AbsenceExcuse $excuse): array
    {
        $learner = $excuse->learner;

        return [
            'name' => $learner->user->full_name,
            'student_number' => $learner->student_number,
            'program' => $learner->program->code,
            // The dean's page for the learner in the term the excuse starts in.
            'registration_id' => TermRegistration::where('learner_id', $learner->id)
                ->whereHas('term', fn ($term) => $term->where('starts_on', '<=', $excuse->date_from->toDateString())->where('ends_on', '>=', $excuse->date_from->toDateString()))
                ->value('id'),
        ];
    }

    private static function dean(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
