<?php

namespace App\Http\Controllers\Registrar;

use App\Actions\Accounts\SendAccountSetupLink;
use App\Enums\LearnerStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registrar\LearnerRequest;
use App\Models\College;
use App\Models\Learner;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The registrar's learners: admission (account and student record) and the
 * record's upkeep, including the learner's name.
 */
class LearnerController extends Controller
{
    public function __construct(private SendAccountSetupLink $setupLink) {}

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
            ->when($filters['search'], fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('student_number', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($user) => $user
                    ->where('last_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))))
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
        ]);
    }

    public function store(LearnerRequest $request): RedirectResponse
    {
        $program = $request->program();

        $learner = DB::transaction(function () use ($request, $program): Learner {
            $account = new User($request->safe()->only(['last_name', 'first_name', 'middle_name', 'suffix', 'email']));
            $account->forceFill([
                'role' => UserRole::Learner,
                'college_id' => $program->college_id,
                // Nobody knows this password; the setup link replaces it.
                'password' => Hash::make(Str::random(40)),
                // The registrar vouches for the address; see SendAccountSetupLink.
                'email_verified_at' => now(),
            ])->save();

            return $account->learner()->create([
                ...$request->safe()->only(['student_number', 'year_level', 'status']),
                'program_id' => $program->id,
            ]);
        });

        $this->flashSetupLink($learner->user);

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
