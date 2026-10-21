<?php

namespace App\Http\Controllers\Dean;

use App\Http\Controllers\Controller;
use App\Models\ClassCover;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\User;
use App\Notifications\CoverNotice;
use App\Scheduling\Covers;
use App\Scheduling\ScheduleConflicts;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Substitutes for a teacher's classes over some days (a teacher on leave):
 * the dean picks the teacher and the dates, then a substitute for each of
 * their classes meeting then. The classes keep their teacher.
 */
class CoverController extends Controller
{
    // Covers is injected per action: it uses ClassDays, which caches suspensions.
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $collegeId = (int) $user->college_id;
        $terms = Term::orderByDesc('starts_on')->get();
        $term = $terms->firstWhere('id', $request->integer('term')) ?? Term::forViewing();

        $covers = $term === null ? collect() : ClassCover::query()
            ->with(['schedule.subjectOffering.subject', 'schedule.subjectOffering.section.program', 'schedule.batch', 'schedule.teacher.user', 'substitute.user'])
            ->whereHas('schedule', fn ($schedules) => $schedules->where('term_id', $term->id)->whereRelation('subjectOffering.section.program', 'college_id', $collegeId))
            ->orderByDesc('starts_on')
            ->get();
        $today = CarbonImmutable::today();

        return Inertia::render('dean/covers/Index', [
            'terms' => $terms->map(fn (Term $term): array => ['id' => $term->id, 'label' => $term->label]),
            'term' => $term === null ? null : ['id' => $term->id, 'label' => $term->label, 'starts_on' => $term->starts_on->toDateString(), 'ends_on' => $term->ends_on->toDateString()],
            'covers' => $covers->map(fn (ClassCover $cover): array => [
                'id' => $cover->id,
                'range' => $cover->range(),
                'state' => $cover->ends_on->lt($today) ? 'past' : ($cover->starts_on->gt($today) ? 'upcoming' : 'current'),
                'class' => self::className($cover->schedule),
                'when' => ScheduleConflicts::when($cover->schedule),
                'teacher' => $cover->schedule->teacher->user->full_name,
                'substitute' => $cover->substitute->user->full_name,
            ])->values(),
            'teachers' => ScheduleBlocks::teacherOptions($collegeId),
            'canEdit' => $term !== null && ! $term->isClosed(),
            // The arrange dialog's classes, once it has a teacher and dates.
            'plan' => Inertia::optional(fn (): array => $term === null ? [] : $this->plan($request, $term, $collegeId, app(Covers::class))),
        ]);
    }

    public function store(Request $request, Covers $covers): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $collegeId = (int) $user->college_id;
        $validated = $request->validate([
            'term_id' => ['required', 'integer', Rule::exists(Term::class, 'id')],
            'teacher_id' => ['required', 'integer'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'covers' => ['required', 'array', 'min:1'],
            'covers.*.schedule_id' => ['required', 'integer', 'distinct'],
            'covers.*.substitute_id' => ['nullable', 'integer'],
        ]);

        $term = Term::findOrFail($request->integer('term_id'));
        $teacher = self::collegeTeacher($request->integer('teacher_id'), $collegeId) ?? throw ValidationException::withMessages(['teacher_id' => __('Choose one of your college\'s teachers.')]);
        $from = CarbonImmutable::parse($request->string('starts_on')->value());
        $to = CarbonImmutable::parse($request->string('ends_on')->value());

        if ($from->lt($term->starts_on) || $to->gt($term->ends_on)) {
            throw ValidationException::withMessages(['starts_on' => __('Pick dates within :term.', ['term' => $term->label])]);
        }

        $schedules = Schedule::with(['subjectOffering.section.program', 'subjectOffering.subject', 'batch', 'term'])
            ->where('term_id', $term->id)
            ->where('teacher_id', $teacher->id)
            ->findMany(array_column($validated['covers'], 'schedule_id'))
            ->keyBy('id');
        $plans = [];
        $rows = [];

        foreach ($validated['covers'] as $i => $row) {
            $schedule = $schedules->get((int) $row['schedule_id']);

            if ($schedule === null) {
                throw ValidationException::withMessages(["covers.{$i}.schedule_id" => __('That class isn\'t :name\'s this term.', ['name' => $teacher->user->full_name])]);
            }

            Gate::authorize('update', $schedule->subjectOffering->section);

            if (($row['substitute_id'] ?? null) === null) {
                continue; // left without cover
            }

            $substitute = self::collegeTeacher((int) $row['substitute_id'], $collegeId);

            if ($substitute === null) {
                throw ValidationException::withMessages(["covers.{$i}.substitute_id" => __('Choose one of your college\'s teachers.')]);
            }

            $plans[] = ['schedule' => $schedule, 'substitute' => $substitute];
            $rows[] = $i;
        }

        if ($plans === []) {
            throw ValidationException::withMessages(['covers' => __('Pick a substitute for at least one class.')]);
        }

        $errors = [];

        foreach ($plans as $at => $plan) {
            $others = array_values(array_filter($plans, fn (array $other, int $key): bool => $key < $at, ARRAY_FILTER_USE_BOTH));
            $clashes = $covers->clashes($plan['schedule'], $plan['substitute'], $from, $to, $others);

            if ($clashes !== []) {
                $errors["covers.{$rows[$at]}.substitute_id"] = implode(' ', $clashes);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $made = $covers->arrange($plans, $from, $to, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{1} Cover arranged for :count class; the substitute was notified.|[2,*] Cover arranged for :count classes; the substitutes were notified.', $made->count(), ['count' => $made->count()])]);

        return to_route('dean.covers.index', ['term' => $term->id]);
    }

    /**
     * Remove a cover; marks the substitute made stay (they are audited).
     */
    public function destroy(ClassCover $cover): RedirectResponse
    {
        $cover->load(['schedule.subjectOffering.section', 'substitute.user']);
        Gate::authorize('update', $cover->schedule->subjectOffering->section);

        $cover->delete();
        $cover->substitute->user->notify(CoverNotice::removed($cover));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cover removed; :name was told.', ['name' => $cover->substitute->user->full_name])]);

        return back();
    }

    /**
     * The chosen teacher's classes meeting in the chosen dates.
     *
     * @return list<array{schedule_id: int, class: string, when: string, dates: list<string>, covered_by: string|null}>
     */
    private function plan(Request $request, Term $term, int $collegeId, Covers $covers): array
    {
        $teacher = self::collegeTeacher($request->integer('teacher'), $collegeId);
        $from = self::date($request->string('from')->value());
        $to = self::date($request->string('to')->value());

        if ($teacher === null || $from === null || $to === null || $to->lt($from)) {
            return [];
        }

        $from = $from->max($term->starts_on);
        $to = $to->min($term->ends_on);

        return array_map(function (array $meeting) use ($from, $to): array {
            $schedule = $meeting['schedule'];
            $taken = ClassCover::with('substitute.user')
                ->where('schedule_id', $schedule->id)
                ->where('starts_on', '<=', $to->toDateString())
                ->where('ends_on', '>=', $from->toDateString())
                ->first();

            return [
                'schedule_id' => $schedule->id,
                'class' => self::className($schedule),
                'when' => ScheduleConflicts::when($schedule),
                'dates' => array_map(fn (CarbonImmutable $date): string => $date->toDateString(), $meeting['dates']),
                'covered_by' => $taken === null ? null : $taken->substitute->user->full_name.' ('.$taken->range().')',
            ];
        }, $covers->meetings($teacher, $term, $from, $to));
    }

    /**
     * "CS 101 · BSCS 1-A (1-AA)".
     */
    private static function className(Schedule $schedule): string
    {
        $offering = $schedule->subjectOffering;

        return "{$offering->subject->code} · {$offering->section->program->code} {$offering->section->name}".($schedule->batch ? " ({$schedule->batch->name})" : '');
    }

    private static function collegeTeacher(int $id, int $collegeId): ?Teacher
    {
        return Teacher::with('user')->whereKey($id)->whereRelation('user', 'college_id', $collegeId)->first();
    }

    private static function date(string $value): ?CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::parse($value) : null;
    }
}
