<?php

namespace App\Http\Controllers;

use App\Attendance\Excuses;
use App\Attendance\Standing;
use App\Dashboard\AdminPanel;
use App\Dashboard\RegistrarPanel;
use App\Dashboard\Suspensions;
use App\Dashboard\Week;
use App\Enums\DecisionStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExcuseStatus;
use App\Enums\TermPhase;
use App\Enums\UserRole;
use App\Models\AbsenceExcuse;
use App\Models\ClassCover;
use App\Models\Enrollment;
use App\Models\ExcuseDecision;
use App\Models\Learner;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Each role's dashboard: a main panel (a dated week of classes for deans,
 * teachers and learners; a month of events for the admin; registration
 * progress for the registrar) and side lists, under the current term.
 */
class DashboardController extends Controller
{
    /** How many items a side list shows before "See all". */
    private const SIDE_ITEMS = 6;

    private const SCHEDULE_RELATIONS = ['subjectOffering.subject', 'subjectOffering.section.program', 'batch', 'room', 'teacher.user', 'term'];

    public function __invoke(Request $request, Standing $standing, Week $week, Excuses $excuses, AdminPanel $admin, RegistrarPanel $registrar): Response
    {
        /** @var User $user */
        $user = $request->user();
        $term = Term::forViewing();
        $monday = Week::monday($request->string('week')->value() ?: null, $term);

        return Inertia::render('Dashboard', [
            'roleLabel' => $user->role->label(),
            'collegeName' => $user->college?->name,
            'term' => $term === null ? null : self::termBadge($term),
            'today' => CarbonImmutable::today()->toDateString(),
            'admin' => $user->role === UserRole::Admin ? $admin->build($request->string('month')->value() ?: null) : null,
            'registrar' => $user->role === UserRole::Registrar ? $registrar->build() : null,
            'dean' => $user->role === UserRole::Dean ? $this->deanPanel($request, $user, $term, $monday, $week, $standing, $excuses) : null,
            'teacher' => $user->teacher === null ? null : $this->teacherPanel($user, $term, $monday, $week),
            'learner' => $user->learner === null ? null : $this->learnerPanel($user->learner, $term, $monday, $week, $standing),
        ]);
    }

    /**
     * The term beside the title: its phase, and which week it is (or when
     * it starts).
     *
     * @return array{label: string, phase: string, week: int|null, weeks: int, starts_in: int|null}
     */
    private static function termBadge(Term $term): array
    {
        $today = CarbonImmutable::today();
        $first = $term->starts_on->startOfWeek(CarbonImmutable::MONDAY);
        $phase = $term->phase();

        return [
            'label' => $term->label,
            'phase' => $phase->value,
            'week' => $phase === TermPhase::InSession ? (int) $first->diffInWeeks($today->startOfWeek(CarbonImmutable::MONDAY)) + 1 : null,
            'weeks' => (int) $first->diffInWeeks($term->ends_on->startOfWeek(CarbonImmutable::MONDAY)) + 1,
            'starts_in' => $phase === TermPhase::Upcoming ? (int) $today->diffInDays($term->starts_on) : null,
        ];
    }

    /**
     * A section's week (picked by program, then section), the excuses
     * waiting in the college, and its learners at risk.
     *
     * @return array<string, mixed>
     */
    private function deanPanel(Request $request, User $dean, ?Term $term, CarbonImmutable $monday, Week $week, Standing $standing, Excuses $excuses): array
    {
        $programs = Program::where('college_id', $dean->college_id)->orderBy('code')->get(['id', 'code', 'name']);
        $sections = $term === null ? new Collection : Section::query()
            ->where('term_id', $term->id)
            ->whereIn('program_id', $programs->modelKeys())
            ->orderBy('year_level')
            ->orderBy('name')
            ->get(['id', 'program_id', 'year_level', 'name']);
        $section = $sections->firstWhere('id', $request->integer('section')) ?? $sections->first();
        $schedules = $section === null ? new Collection : Schedule::with(self::SCHEDULE_RELATIONS)
            ->whereRelation('subjectOffering', 'section_id', $section->id)
            ->get();

        $pending = AbsenceExcuse::query()
            ->whereHas('learner', fn ($learner) => $learner->whereIn('program_id', $programs->modelKeys()))
            ->when($term, fn ($query, Term $term) => $query->duringTerm($term))
            ->where('status', ExcuseStatus::Pending);
        $atRisk = $term === null ? null : Enrollment::query()
            ->whereHas('subjectOffering.section', fn ($section) => $section->where('term_id', $term->id)->whereIn('program_id', $programs->modelKeys()))
            ->where(fn ($query) => $query
                ->where('status', EnrollmentStatus::ForDropping)
                ->orWhere(fn ($query) => $query->where('status', EnrollmentStatus::Enrolled)->where('warning_level', '>', 0)));

        return [
            'programs' => $programs,
            'sections' => $sections,
            'section' => $section?->id,
            'week' => $week->build($schedules, $monday, $term, $dean->college_id),
            'excuses' => (clone $pending)
                ->with(['learner.user', 'learner.program:id,code', 'decisions'])
                ->oldest()
                ->limit(self::SIDE_ITEMS)
                ->get()
                ->map(fn (AbsenceExcuse $excuse): array => [
                    'id' => $excuse->id,
                    'learner' => $excuse->learner->user->last_name.', '.$excuse->learner->user->first_name,
                    'program' => $excuse->learner->program->code,
                    'date_from' => $excuse->date_from->toDateString(),
                    'date_to' => $excuse->date_to->toDateString(),
                    'reason' => $excuse->reason->value,
                    'days_late' => $excuse->daysLate(),
                    // Classes only the dean can decide (no active teacher).
                    'dean_decides' => $excuse->decisions
                        ->filter(fn (ExcuseDecision $decision): bool => $decision->status === DecisionStatus::Pending && $excuses->canDecide($dean, $decision))
                        ->count(),
                ]),
            'excusesCount' => $pending->count(),
            'atRisk' => $atRisk === null ? [] : (clone $atRisk)
                ->with(['learner.user', 'subjectOffering.subject:id,code', 'subjectOffering.section.program:id,code'])
                ->orderByRaw('status = ? desc', [EnrollmentStatus::ForDropping->value])
                ->orderByDesc('warning_level')
                ->limit(self::SIDE_ITEMS)
                ->get()
                ->map(fn (Enrollment $enrollment): array => [
                    'enrollment_id' => $enrollment->id,
                    'learner' => $enrollment->learner->user->last_name.', '.$enrollment->learner->user->first_name,
                    'subject' => $enrollment->subjectOffering->subject->code,
                    'section' => "{$enrollment->subjectOffering->section->program->code} {$enrollment->subjectOffering->section->name}",
                    'status' => $enrollment->status->value,
                    'warning_level' => $enrollment->warning_level,
                    'equivalent' => $standing->counts($enrollment)['equivalent'],
                    'drop_at' => Standing::policy($enrollment)['drop_at_absences'],
                ]),
            'atRiskCounts' => $atRisk === null ? ['flagged' => 0, 'warned' => 0] : [
                'flagged' => (clone $atRisk)->where('status', EnrollmentStatus::ForDropping)->count(),
                'warned' => (clone $atRisk)->where('status', EnrollmentStatus::Enrolled)->count(),
            ],
        ];
    }

    /**
     * Their week (with classes they cover), suspensions coming up for their
     * classes, and the excuse decisions waiting on them.
     *
     * @return array<string, mixed>
     */
    private function teacherPanel(User $user, ?Term $term, CarbonImmutable $monday, Week $week): array
    {
        $teacherId = $user->teacher->id ?? 0;
        $saturday = $monday->addDays(5);
        $schedules = Schedule::with(self::SCHEDULE_RELATIONS)
            ->where(fn ($query) => $query
                ->where('teacher_id', $teacherId)
                ->orWhereIn('id', ClassCover::where('substitute_id', $teacherId)
                    ->where('starts_on', '<=', $saturday->toDateString())
                    ->where('ends_on', '>=', $monday->toDateString())
                    ->select('schedule_id')))
            ->whereHas('term', fn (Builder $terms) => self::overlapping($terms, $monday, $saturday))
            ->get();
        $own = $term === null ? new Collection : Schedule::with(self::SCHEDULE_RELATIONS)->where('term_id', $term->id)->where('teacher_id', $teacherId)->get();
        $pending = Excuses::decidableBy($teacherId)
            ->when($term, fn ($query, Term $term) => $query->whereRelation('subjectOffering.section', 'term_id', $term->id))
            ->where('status', DecisionStatus::Pending);

        return [
            'week' => $week->build($schedules, $monday, $term, $user->college_id, $teacherId),
            'suspensions' => Suspensions::upcoming($own),
            'excuses' => (clone $pending)
                ->with(['absenceExcuse.learner.user', 'subjectOffering.subject:id,code', 'subjectOffering.section.program:id,code'])
                ->oldest()
                ->limit(self::SIDE_ITEMS)
                ->get()
                ->map(fn (ExcuseDecision $decision): array => [
                    'id' => $decision->id,
                    'learner' => $decision->absenceExcuse->learner->user->last_name.', '.$decision->absenceExcuse->learner->user->first_name,
                    'subject' => $decision->subjectOffering->subject->code,
                    'section' => "{$decision->subjectOffering->section->program->code} {$decision->subjectOffering->section->name}",
                    'date_from' => $decision->absenceExcuse->date_from->toDateString(),
                    'date_to' => $decision->absenceExcuse->date_to->toDateString(),
                    'reason' => $decision->absenceExcuse->reason->value,
                ]),
            'excusesCount' => $pending->count(),
        ];
    }

    /**
     * Their week (their batches' labs), suspensions coming up for their
     * classes, their recent excuses, and classes needing attention.
     *
     * @return array<string, mixed>
     */
    private function learnerPanel(Learner $learner, ?Term $term, CarbonImmutable $monday, Week $week, Standing $standing): array
    {
        $saturday = $monday->addDays(5);
        $enrollments = Enrollment::query()
            ->where('learner_id', $learner->id)
            ->where('status', '!=', EnrollmentStatus::Dropped)
            ->get(['id', 'subject_offering_id', 'batch_id']);
        $schedules = $enrollments->isEmpty() ? new Collection : self::learnersClasses($enrollments)
            ->whereHas('term', fn (Builder $terms) => self::overlapping($terms, $monday, $saturday))
            ->get();
        $current = $enrollments->isEmpty() || $term === null ? new Collection : self::learnersClasses($enrollments)->where('term_id', $term->id)->get();

        $attention = $term === null ? collect() : Enrollment::query()
            ->with(['subjectOffering.subject:id,code,title', 'subjectOffering.section.program:id,code'])
            ->where('learner_id', $learner->id)
            ->whereHas('subjectOffering.section', fn ($query) => $query->where('term_id', $term->id))
            ->where(fn ($query) => $query->where('warning_level', '>', 0)->orWhere('status', '!=', EnrollmentStatus::Enrolled))
            ->get()
            ->sortByDesc(fn (Enrollment $enrollment): int => ($enrollment->status === EnrollmentStatus::ForDropping ? 100 : 0) + $enrollment->warning_level)
            ->values();

        return [
            'week' => $week->build($schedules, $monday, $term, $learner->user->college_id),
            'suspensions' => Suspensions::upcoming($current),
            'excuses' => AbsenceExcuse::query()
                ->where('learner_id', $learner->id)
                ->when($term, fn ($query, Term $term) => $query->duringTerm($term))
                ->orderByRaw('status = ? desc', [ExcuseStatus::Pending->value])
                ->latest()
                ->limit(self::SIDE_ITEMS)
                ->get()
                ->map(fn (AbsenceExcuse $excuse): array => [
                    'id' => $excuse->id,
                    'date_from' => $excuse->date_from->toDateString(),
                    'date_to' => $excuse->date_to->toDateString(),
                    'reason' => $excuse->reason->value,
                    'status' => $excuse->status->value,
                ]),
            'attention' => $attention->map(fn (Enrollment $enrollment): array => [
                'enrollment_id' => $enrollment->id,
                'subject' => $enrollment->subjectOffering->subject->only(['code', 'title']),
                'section' => "{$enrollment->subjectOffering->section->program->code} {$enrollment->subjectOffering->section->name}",
                'status' => $enrollment->status->value,
                'warning_level' => $enrollment->warning_level,
                'drop_reason' => $enrollment->drop_reason?->value,
                'equivalent' => $standing->counts($enrollment)['equivalent'],
                'drop_at' => Standing::policy($enrollment)['drop_at_absences'],
            ]),
        ];
    }

    /**
     * A learner's classes: each enrollment's whole-offering classes and
     * their own batch's.
     *
     * @param  Collection<int, Enrollment>  $enrollments
     * @return Builder<Schedule>
     */
    private static function learnersClasses(Collection $enrollments): Builder
    {
        return Schedule::with(self::SCHEDULE_RELATIONS)->where(function ($query) use ($enrollments): void {
            foreach ($enrollments as $enrollment) {
                $query->orWhere(fn ($query) => $query
                    ->where('subject_offering_id', $enrollment->subject_offering_id)
                    ->where(fn ($query) => $query->whereNull('batch_id')->orWhere('batch_id', $enrollment->batch_id)));
            }
        });
    }

    /**
     * Terms that overlap the dates.
     *
     * @param  Builder<Term>  $terms
     * @return Builder<Term>
     */
    private static function overlapping(Builder $terms, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $terms->where('starts_on', '<=', $to->toDateString())->where('ends_on', '>=', $from->toDateString());
    }
}
