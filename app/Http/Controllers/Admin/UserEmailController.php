<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Email\SetEmailDirectly;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The admin's override of a user's email, for users who lost access to
 * their old inbox. The admin user screens (feature 5) put a form on it.
 */
class UserEmailController extends Controller
{
    public function update(Request $request, User $user, SetEmailDirectly $setEmail): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        /** @var User $admin */
        $admin = $request->user();

        $setEmail->handle($user, $validated['email'], $admin, $validated['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email updated.')]);

        return back();
    }
}
