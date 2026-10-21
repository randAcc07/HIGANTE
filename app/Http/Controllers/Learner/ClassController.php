<?php

namespace App\Http\Controllers\Learner;

use App\Attendance\ClassDays;
use App\Attendance\Standing;
use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dean\ScheduleBlocks;
use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\Learner;
use App\Models\Schedule;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use App\Scheduling\LearnerConflicts;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A learner's own classes in a term: their week, and their standing and
 * marks in each class. Only the mark is shown, not who recorded it.
 */
class ClassController extends Controller
{
    public function index(Request $request, Standing $standing, LearnerConflicts $conflicts): Response
    {
        $learner = self::learner($request);
        $terms = Term::whereHas('termRegistrations', fn ($query) => $query->where('learner_id', $learner->id))
            ->orderByDesc('starts_on')
            ->get();
        $viewing = Term::forViewing();
        $term = $terms->firstWhere('id', $request->integer('term'))
            ?? $terms->firstWhere('id', $viewing?->id)
            ?? $terms->first();

        $registration = $term === null ? null : TermRegistration::with('section:id,name')
            ->where('learner_id', $learner->id)
            ->where('term_id', $term->id)
            ->first();

        $enrollments = $term === null ? collect() : Enrollment::query()
            ->with(['subjectOffering.subject:id,code,title', 'subjectOffering.section.program:id,code', 'subjectOffering.teacher.user', 'batch:id,name'])
            ->where('learner_id', $learner->id)
            ->whereHas('subjectOffering.section', fn ($query) => $query->where('term_id', $term->id))
            ->get()
            ->sortBy(fn (Enrollment $enrollment): string => ($enrollment->status === EnrollmentStatus::Dropped ? '1' : '0').$enrollment->subjectOffering->subject->code)
            ->values();

        return Inertia::render('learner/classes/Index', [
            'terms' => $terms->map(fn (Term $term): array => ['id' => $term->id, 'label' => $term->label, 'phase' => $term->phase()->value]),
            'term' => $term === null ? null : ['id' => $term->id, 'label' => $term->label, 'phase' => $term->phase()->value],
            'registration' => $registration === null ? null : [
                'year_level' => $registration->year_level,
                'section' => $registration->section?->name,
                'status' => $registration->status->value,
            ],
            'policy' => $enrollments->isEmpty() ? null : Standing::policy($enrollments->first()),
            'classes' => $enrollments->map(fn (Enrollment $enrollment): array => [
                ...self::summary($enrollment),
                'counts' => $standing->counts($enrollment),
                'periods' => $standing->periods($enrollment),
            ]),
            'week' => $term === null ? [] : $conflicts->classes($conflicts->subjects($learner, $term->id))
                ->map(fn (Schedule $schedule): array => ScheduleBlocks::block($schedule))
                ->values(),
        ]);
    }

    /**
     * One class: the days the learner was marked late or absent (everyone
     * is present otherwise), and whether each counts.
     */
    public function show(Request $request, Enrollment $enrollment, Standing $standing, ClassDays $days): Response
    {
        $learner = self::learner($request);
        abort_unless($enrollment->learner_id === $learner->id, 403);
        $enrollment->load(['subjectOffering.subject:id,code,title', 'subjectOffering.section.program:id,code', 'subjectOffering.section.term', 'subjectOffering.teacher.user', 'batch:id,name']);

        $marks = AttendanceRecord::query()
            ->with(['schedule.term', 'schedule.subjectOffering.section.program'])
            ->where('enrollment_id', $enrollment->id)
            ->where('status', '!=', AttendanceStatus::Present)
            ->orderByDesc('class_date')
            ->get()
            ->map(function (AttendanceRecord $record) use ($days): array {
                $suspension = $days->suspension($record->schedule, $record->class_date);

                return [
                    'id' => $record->id,
                    'date' => $record->class_date->toDateString(),
                    'start' => $record->schedule->starts_at->format('H:i'),
                    'end' => $record->schedule->ends_at->format('H:i'),
                    'status' => $record->status->value,
                    'excused' => $record->absence_excuse_id !== null,
                    // Classes on this day were suspended after the mark: it doesn't count.
                    'suspended' => $suspension,
                ];
            })
            ->values();

        $term = $enrollment->subjectOffering->section->term;

        return Inertia::render('learner/classes/Show', [
            'item' => self::summary($enrollment),
            'term' => ['id' => $term->id, 'label' => $term->label],
            'policy' => Standing::policy($enrollment),
            'counts' => $standing->counts($enrollment),
            'periods' => $standing->periods($enrollment),
            'marks' => $marks,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(Enrollment $enrollment): array
    {
        $offering = $enrollment->subjectOffering;

        return [
            'enrollment_id' => $enrollment->id,
            'subject' => $offering->subject->only(['code', 'title']),
            'section' => "{$offering->section->program->code} {$offering->section->name}",
            'batch' => $enrollment->batch?->name,
            'teacher' => $offering->teacher?->user->full_name,
            'status' => $enrollment->status->value,
            'warning_level' => $enrollment->warning_level,
            'flagged_on' => $enrollment->flagged_for_drop_at?->toDateString(),
            'dropped_on' => $enrollment->drop_confirmed_at?->toDateString(),
            'drop_reason' => $enrollment->drop_reason?->value,
        ];
    }

    private static function learner(Request $request): Learner
    {
        /** @var User $user */
        $user = $request->user();

        return $user->learner ?? abort(403);
    }
}
