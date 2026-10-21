<?php

namespace App\Http\Controllers\Registrar;

use App\Actions\Accounts\AdmitLearner;
use App\Actions\Accounts\SendAccountSetupLink;
use App\Enums\LearnerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registrar\LearnerRequest;
use App\Models\College;
use App\Models\Learner;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use App\Registration\TermRegistrar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The registrar's learners: admission (account and student record) and the
 * record's upkeep, including the learner's name.
 */
class LearnerController extends Controller
{
    public function __construct(private SendAccountSetupLink $setupLink, private AdmitLearner $admit) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'college' => ['nullable', 'integer'],
            'program' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer', 'between:1,6'],
            'status' => ['nullable', Rule::enum(LearnerStatus::class)],
        ]) + ['search' => null, 'college' => null, 'program' => null, 'year' => null, 'status' => null];

        $learners = Learner::query()
            ->with(['user', 'program:id,college_id,code', 'program.college:id,code'])
            ->when($filters['search'], fn ($query, string $search) => $query->matching($search))
            ->when($filters['college'], fn ($query, int $college) => $query->whereHas('program', fn ($program) => $program->where('college_id', $college)))
            ->when($filters['program'], fn ($query, int $program) => $query->where('program_id', $program))
            ->when($filters['year'], fn ($query, int $year) => $query->where('year_level', $year))
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            ->join('users', 'users.id', '=', 'learners.user_id')
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->select('learners.*')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('registrar/learners/Index', [
            'learners' => $learners,
            'colleges' => College::with(['programs' => fn ($programs) => $programs->orderBy('code')->select(['id', 'college_id', 'code', 'name'])])
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'filters' => $filters,
            // The terms a new learner can be registered for as they are admitted.
            'registrationTerms' => Term::orderBy('starts_on')->get()
                ->filter(fn (Term $term): bool => TermRegistrar::isOpen($term))
                ->map(fn (Term $term): array => ['id' => $term->id, 'label' => $term->label])
                ->values(),
            'defaultRegistrationTerm' => Term::forPreparation()?->id,
        ]);
    }

    /**
     * Admit a learner, optionally registering them for a term right away.
     */
    public function store(LearnerRequest $request): RedirectResponse
    {
        $program = $request->program();
        /** @var User $registrar */
        $registrar = $request->user();
        $term = $request->filled('register_term_id') ? Term::findOrFail($request->integer('register_term_id')) : null;

        if ($term !== null) {
            Gate::authorize('create', [TermRegistration::class, $term]);
        }

        /** @var array{last_name: string, first_name: string, middle_name?: string|null, suffix?: string|null, email: string, student_number: string, year_level: int, status: string} $data */
        $data = $request->safe()->only(['last_name', 'first_name', 'middle_name', 'suffix', 'email', 'student_number', 'year_level', 'status']);
        ['learner' => $learner, 'registered' => $registered] = DB::transaction(fn (): array => $this->admit->handle($data, $program, $registrar, $term, $request->boolean('is_irregular')));

        $this->flashSetupLink($learner->user);

        if ($registered !== null) {
            $section = $registered['registration']->section;
            Inertia::flash('toast', [
                'type' => $section === null || $registered['warning'] !== null ? 'warning' : 'success',
                'message' => trim(($section === null
                    ? __('Registered for :term; no section has room, so the dean places them.', ['term' => $term?->label])
                    : __('Registered for :term in :section.', ['term' => $term?->label, 'section' => "{$program->code} {$section->name}"])).' '.$registered['warning']),
            ]);
        }

        return back();
    }

    public function update(LearnerRequest $request, Learner $learner): RedirectResponse
    {
        $program = $request->program();

        DB::transaction(function () use ($request, $learner, $program): void {
            $learner->user->update($request->safe()->only(['last_name', 'first_name', 'middle_name', 'suffix']));
            $learner->user->forceFill(['college_id' => $program->college_id])->save();
            $learner->update([
                ...$request->safe()->only(['student_number', 'year_level', 'status']),
                'program_id' => $program->id,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Learner updated.')]);

        return back();
    }

    /**
     * Let the learner log in, or stop them. Their records stay either way.
     */
    public function status(Request $request, Learner $learner): RedirectResponse
    {
        $active = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];

        $learner->user->forceFill(['is_active' => $active])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $active
            ? __(':name can log in again.', ['name' => $learner->user->full_name])
            : __(':name can no longer log in.', ['name' => $learner->user->full_name])]);

        return back();
    }

    public function setupLink(Learner $learner): RedirectResponse
    {
        if (! $learner->user->is_active) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Reactivate the account first.')]);

            return back();
        }

        $this->flashSetupLink($learner->user);

        return back();
    }

    private function flashSetupLink(User $account): void
    {
        Inertia::flash('setupLink', [
            'name' => $account->full_name,
            'email' => $account->email,
            'url' => $this->setupLink->handle($account),
            'days' => SendAccountSetupLink::LIFETIME_DAYS,
        ]);
    }
}
