<?php

namespace App\Http\Controllers;

use App\Actions\Accounts\SendAccountSetupLink;
use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page behind an account setup link: choose a password. The signed,
 * expiring URL is the authorization; it stops working once the password
 * changes or the account is deactivated.
 */
class AccountSetupController extends Controller
{
    use PasswordValidationRules;

    public function show(Request $request, int $user): Response
    {
        $account = $this->resolve($request, $user);

        return Inertia::render('auth/AccountSetup', [
            'valid' => $account !== null,
            'name' => $account?->full_name,
            'email' => $account?->email,
            'submitUrl' => $account === null ? null : $request->fullUrl(),
        ]);
    }

    public function store(Request $request, int $user): RedirectResponse
    {
        $account = $this->resolve($request, $user);

        if ($account === null) {
            return to_route('account-setup.show', ['user' => $user]);
        }

        $validated = $request->validate(['password' => $this->passwordRules()]);

        $account->forceFill([
            'password' => $validated['password'],
            'remember_token' => Str::random(60),
        ])->save();

        // Anyone still signed in with the old password is let go.
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $account->id)->delete();
        }

        return to_route('login')->with('status', __('Your password is set. Log in with your email and the new password.'));
    }

    private function resolve(Request $request, int $id): ?User
    {
        if (! $request->hasValidSignature()) {
            return null;
        }

        $account = User::find($id);

        if ($account === null || ! $account->is_active || ! hash_equals(SendAccountSetupLink::fingerprint($account), $request->string('key')->value())) {
            return null;
        }

        return $account;
    }
}
