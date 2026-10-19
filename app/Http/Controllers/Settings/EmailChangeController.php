<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Email\RequestEmailChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\EmailChangeRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmailChangeController extends Controller
{
    /**
     * Start (or restart) a change of the signed-in user's email.
     */
    public function store(EmailChangeRequest $request, RequestEmailChange $requestEmailChange): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $requestEmailChange->handle($user, $request->string('new_email')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Check both inboxes to finish changing your email.')]);

        return to_route('profile.edit');
    }

    /**
     * Cancel the signed-in user's pending email change.
     */
    public function destroy(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->emailChange()->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email change cancelled.')]);

        return to_route('profile.edit');
    }
}
