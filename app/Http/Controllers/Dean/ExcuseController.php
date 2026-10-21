<?php

namespace App\Http\Controllers\Dean;

use App\Attendance\Excuses;
use App\Attendance\ExcuseView;
use App\Enums\DecisionStatus;
use App\Enums\ExcuseStatus;
use App\Http\Controllers\Controller;
use App\Models\AbsenceExcuse;
use App\Models\ExcuseDecision;
use App\Models\Program;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dean's oversight of their college's excuses: every excuse and how
 * each class decided. Teachers decide; the dean decides only for classes
 * without a teacher (from the class's decision page).
 */
class ExcuseController extends Controller
{
    public function index(Request $request, Excuses $excuses): Response
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
            ->with(['learner.user', 'learner.program:id,code'])
            ->where('status', $filters['status'])
            ->when($filters['search'], fn ($query, string $search) => $query->whereHas('learner', fn ($learner) => $learner->matching($search)))
            ->orderBy('created_at', $filters['status'] === ExcuseStatus::Pending->value ? 'asc' : 'desc')
            ->paginate(25)
            ->withQueryString();

        $rows = $page->getCollection()->map(fn (AbsenceExcuse $excuse): array => [
            ...ExcuseView::excuse($excuse),
            'learner' => ['name' => $excuse->learner->user->full_name, 'student_number' => $excuse->learner->student_number, 'program' => $excuse->learner->program->code],
            // Pending classes the dean must decide (no teacher), if any.
            'dean_decides' => $excuse->decisions
                ->filter(fn (ExcuseDecision $decision): bool => $decision->status === DecisionStatus::Pending && $excuses->canDecide($user, $decision))
                ->count(),
        ]);

        return Inertia::render('dean/excuses/Index', [
            'excuses' => [...$page->toArray(), 'data' => $rows->values()->all()],
            'counts' => collect(ExcuseStatus::cases())->mapWithKeys(fn (ExcuseStatus $status): array => [$status->value => $inCollege()->where('status', $status)->count()]),
            'filters' => $filters,
        ]);
    }

    public function show(AbsenceExcuse $excuse, Request $request, Excuses $excuses): Response
    {
        Gate::authorize('review', $excuse);
        /** @var User $user */
        $user = $request->user();
        $excuse->load(['learner.user', 'learner.program:id,code']);

        return Inertia::render('dean/excuses/Show', [
            'excuse' => [
                ...ExcuseView::excuse($excuse),
                'learner' => ['name' => $excuse->learner->user->full_name, 'student_number' => $excuse->learner->student_number, 'program' => $excuse->learner->program->code],
            ],
            // Who decides each pending class (teachers, or the dean).
            'deciders' => $excuse->decisions->mapWithKeys(fn (ExcuseDecision $decision): array => [
                $decision->id => [
                    'names' => $excuses->deciders($decision)->map(fn (User $decider): string => $decider->full_name)->values(),
                    'dean' => $excuses->canDecide($user, $decision),
                ],
            ]),
            'lock' => $excuses->lock($excuse),
        ]);
    }
}
