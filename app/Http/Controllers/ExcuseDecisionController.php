<?php

namespace App\Http\Controllers;

use App\Attendance\Excuses;
use App\Attendance\ExcuseView;
use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Dean\ExcuseController as DeanExcuseController;
use App\Http\Controllers\Teacher\ExcuseController as TeacherExcuseController;
use App\Models\AttendanceRecord;
use App\Models\ExcuseDecision;
use App\Models\TermRegistration;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One class's decision on an excuse: the class's teacher approves,
 * declines or revokes it (the dean, for a class without a teacher); the
 * dean of the college can always look.
 */
class ExcuseDecisionController extends Controller
{
    public function show(Request $request, ExcuseDecision $decision, Excuses $excuses): Response
    {
        $user = self::user($request);
        $canDecide = $excuses->canDecide($user, $decision);
        $excuse = $decision->absenceExcuse;
        abort_unless($canDecide || self::isCollegeDean($user, $decision), 403);

        $excuse->load(['learner.user', 'learner.program:id,college_id,code']);
        $offeringId = $decision->subject_offering_id;

        // The learner's absences in this class on the excuse's days.
        $absences = $excuses->absences($excuse, $offeringId)
            ->with(['schedule.batch:id,name'])
            ->get()
            ->map(fn (AttendanceRecord $record): array => [
                'id' => $record->id,
                'date' => $record->class_date->toDateString(),
                'start' => $record->schedule->starts_at->format('H:i'),
                'batch' => $record->schedule->batch?->name,
                'excuse' => $record->absence_excuse_id === null ? 'none' : ($record->absence_excuse_id === $excuse->id ? 'this' : 'other'),
            ])
            ->sortBy(fn (array $absence): string => $absence['date'].' '.$absence['start'])
            ->values();

        $lates = AttendanceRecord::query()
            ->where('status', AttendanceStatus::Late)
            ->whereBetween('class_date', [$excuse->date_from->toDateString(), $excuse->date_to->toDateString()])
            ->whereHas('enrollment', fn ($query) => $query->where('learner_id', $excuse->learner_id)->where('subject_offering_id', $offeringId))
            ->count();

        return Inertia::render('excuses/Decision', [
            'excuse' => [
                ...ExcuseView::excuse($excuse),
                'learner' => [
                    'name' => $excuse->learner->user->full_name,
                    'student_number' => $excuse->learner->student_number,
                    'program' => $excuse->learner->program->code,
                    'registration_id' => $user->role === UserRole::Dean ? TermRegistration::where('learner_id', $excuse->learner_id)
                        ->whereHas('term', fn ($term) => $term->where('starts_on', '<=', $excuse->date_from->toDateString())->where('ends_on', '>=', $excuse->date_from->toDateString()))
                        ->value('id') : null,
                ],
                'needs_document' => $excuse->reason->requiresDocument(),
            ],
            'decision' => ExcuseView::decision($decision->load(['subjectOffering.subject', 'subjectOffering.section.program', 'decider'])),
            'absences' => $absences,
            'lates' => $lates,
            'lock' => $excuses->lock($excuse),
            'canDecide' => $canDecide,
            'dueWithinDays' => $excuse::DUE_WITHIN_DAYS,
            'back' => $user->role === UserRole::Dean
                ? action([DeanExcuseController::class, 'index'], [], false)
                : action([TeacherExcuseController::class, 'index'], [], false),
        ]);
    }

    public function approve(Request $request, ExcuseDecision $decision, Excuses $excuses): RedirectResponse
    {
        $user = $this->authorizeDecider($request, $decision, $excuses);
        $remarks = $request->validate(['remarks' => ['nullable', 'string', 'max:2000']])['remarks'] ?? null;

        $excuses->decide($decision, $user, true, $remarks);
        $count = $decision->absenceExcuse->attendanceRecords()->whereHas('enrollment', fn ($query) => $query->where('subject_offering_id', $decision->subject_offering_id))->count();

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} Approved for this class.|{1} Approved for this class; :count absence excused.|[2,*] Approved for this class; :count absences excused.', $count, ['count' => $count])]);

        return back();
    }

    public function decline(Request $request, ExcuseDecision $decision, Excuses $excuses): RedirectResponse
    {
        $user = $this->authorizeDecider($request, $decision, $excuses);
        $remarks = $request->validate(['remarks' => ['required', 'string', 'max:2000']], ['remarks.required' => __('Tell the learner why.')])['remarks'];

        $excuses->decide($decision, $user, false, $remarks);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Not approved for this class; the learner is told why.')]);

        return back();
    }

    public function revoke(Request $request, ExcuseDecision $decision, Excuses $excuses): RedirectResponse
    {
        $user = $this->authorizeDecider($request, $decision, $excuses);
        $remarks = $request->validate(['remarks' => ['required', 'string', 'max:2000']], ['remarks.required' => __('Tell the learner why.')])['remarks'];

        $excuses->revoke($decision, $user, $remarks);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Revoked for this class; its absences count again.')]);

        return back();
    }

    private function authorizeDecider(Request $request, ExcuseDecision $decision, Excuses $excuses): User
    {
        $user = self::user($request);
        abort_unless($excuses->canDecide($user, $decision), 403);

        return $user;
    }

    private static function isCollegeDean(User $user, ExcuseDecision $decision): bool
    {
        return $user->role === UserRole::Dean
            && $user->college_id === $decision->absenceExcuse->learner->program->college_id;
    }

    private static function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
