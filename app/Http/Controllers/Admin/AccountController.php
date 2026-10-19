<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Accounts\SendAccountSetupLink;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffAccountRequest;
use App\Models\College;
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
 * Everyone's accounts, as the admin sees them. The admin makes and edits
 * registrar, dean and teacher accounts; learners are the registrar's, so on
 * their rows the admin only has the email override (for a lost inbox) and
 * new setup links. Admin accounts are peers with the same permissions, so
 * no admin controls another: they are kept on the server instead
 * (`php artisan app:create-admin`).
 */
class AccountController extends Controller
{
    public function __construct(private SendAccountSetupLink $setupLink) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'college' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]) + ['search' => null, 'role' => null, 'college' => null, 'status' => null];

        $accounts = User::query()
            ->with(['college:id,code', 'teacher:id,user_id,employee_number,duty_status', 'learner:id,user_id,student_number'])
            ->when($filters['search'], fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('last_name', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhereHas('teacher', fn ($teacher) => $teacher->where('employee_number', 'like', "%{$search}%"))
                ->orWhereHas('learner', fn ($learner) => $learner->where('student_number', 'like', "%{$search}%"))))
            ->when($filters['role'], fn ($query, string $role) => $query->where('role', $role))
            ->when($filters['college'], fn ($query, int $college) => $query->where('college_id', $college))
            ->when($filters['status'], fn ($query, string $status) => $query->where('is_active', $status === 'active'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/accounts/Index', [
            'accounts' => $accounts,
            'colleges' => College::orderBy('code')->get(['id', 'code', 'name']),
            'filters' => $filters,
        ]);
    }

    public function store(StaffAccountRequest $request): RedirectResponse
    {
        $role = UserRole::from($request->string('role')->value());

        $account = DB::transaction(function () use ($request, $role): User {
            $account = new User($request->safe()->only(['last_name', 'first_name', 'middle_name', 'suffix', 'email']));
            $account->forceFill([
                'role' => $role,
                'college_id' => $role->belongsToCollege() ? $request->integer('college_id') : null,
                // Nobody knows this password; the setup link replaces it.
                'password' => Hash::make(Str::random(40)),
                // The admin vouches for the address; see SendAccountSetupLink.
                'email_verified_at' => now(),
            ])->save();

            if ($role === UserRole::Teacher) {
                $account->teacher()->create($request->safe()->only(['employee_number', 'duty_status']));
            }

            return $account;
        });

        $this->flashSetupLink($account);

        return back();
    }

    public function update(StaffAccountRequest $request, User $account): RedirectResponse
    {
        self::ensureManageable($account);

        DB::transaction(function () use ($request, $account): void {
            $account->update($request->safe()->only(['last_name', 'first_name', 'middle_name', 'suffix']));
            $account->teacher?->update($request->safe()->only(['employee_number', 'duty_status']));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account updated.')]);

        return back();
    }

    /**
     * Deactivate or reactivate a staff account. A deactivated user cannot
     * log in; their records stay.
     */
    public function status(Request $request, User $account): RedirectResponse
    {
        self::ensureManageable($account);

        $active = $request->validate(['active' => ['required', 'boolean']])['active'];

        $account->forceFill(['is_active' => (bool) $active])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $active
            ? __(':name can log in again.', ['name' => $account->full_name])
            : __(':name can no longer log in.', ['name' => $account->full_name])]);

        return back();
    }

    /**
     * A new setup link, for someone who never set a password or lost it
     * without access to their email.
     */
    public function setupLink(User $account): RedirectResponse
    {
        self::ensureNotAdmin($account);

        if (! $account->is_active) {
            return $this->refuse(__('Reactivate the account first.'));
        }

        $this->flashSetupLink($account);

        return back();
    }

    /**
     * Registrar, dean and teacher accounts only.
     */
    private static function ensureManageable(User $account): void
    {
        self::ensureNotAdmin($account);
        abort_if($account->role === UserRole::Learner, 403, __('Learners are managed by the registrar.'));
    }

    /**
     * Admins are peers; their accounts are kept on the server.
     */
    public static function ensureNotAdmin(User $account): void
    {
        abort_if($account->role === UserRole::Admin, 403, __('Admin accounts are managed on the server.'));
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

    private function refuse(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }
}
