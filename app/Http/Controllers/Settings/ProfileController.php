<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'canEditName' => $request->user()->role->isStaff(),
            'pendingEmailChange' => $this->pendingEmailChange($request->user()),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated())->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * @return array{new_email: string, new_confirmed: bool, old_approved: bool, expires_at: string}|null
     */
    private function pendingEmailChange(User $user): ?array
    {
        $change = $user->emailChange;

        if ($change === null || $change->isExpired()) {
            return null;
        }

        return [
            'new_email' => $change->new_email,
            'new_confirmed' => $change->new_confirmed_at !== null,
            'old_approved' => $change->old_approved_at !== null,
            'expires_at' => $change->expires_at->toIso8601String(),
        ];
    }
}
