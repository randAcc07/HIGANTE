<?php

namespace App\Http\Controllers;

use App\Attendance\Standing;
use App\Enums\EnrollmentStatus;
use App\Enums\ExcuseStatus;
use App\Models\AbsenceExcuse;
use App\Models\Enrollment;
use App\Models\Learner;
use App\Models\Term;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the signed-in user's dashboard. Each role's panels are added by
     * the feature that needs them.
     */
    public function __invoke(Request $request, Standing $standing): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'roleLabel' => $user->role->label(),
            'collegeName' => $user->college?->name,
            'learner' => $user->learner === null ? null : $this->learnerPanel($user->learner, $standing),
        ]);
    }

    /**
     * What a learner should look at: classes with a warning, a drop flag or
     * a drop in the term being viewed, and their excuses awaiting the dean.
     *
     * @return array<string, mixed>
     */
    private function learnerPanel(Learner $learner, Standing $standing): array
    {
        $term = Term::forViewing();

        $attention = $term === null ? collect() : Enrollment::query()
            ->with(['subjectOffering.subject:id,code,title', 'subjectOffering.section.program:id,code'])
            ->where('learner_id', $learner->id)
            ->whereHas('subjectOffering.section', fn ($query) => $query->where('term_id', $term->id))
            ->where(fn ($query) => $query->where('warning_level', '>', 0)->orWhere('status', '!=', EnrollmentStatus::Enrolled))
            ->get()
            ->sortByDesc(fn (Enrollment $enrollment): int => ($enrollment->status === EnrollmentStatus::ForDropping ? 100 : 0) + $enrollment->warning_level)
            ->values();

        return [
            'term' => $term?->label,
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
            'pendingExcuses' => AbsenceExcuse::where('learner_id', $learner->id)->where('status', ExcuseStatus::Pending)->count(),
        ];
    }
}
