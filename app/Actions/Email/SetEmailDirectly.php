<?php

namespace App\Actions\Email;

use App\Enums\EmailChangeMethod;
use App\Models\User;
use App\Notifications\EmailChangedByAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The admin's override for a user who lost access to their old inbox: sets
 * the email at once (verified), cancels any pending change, audits it with
 * the admin's reason, and tells the old address what happened.
 */
class SetEmailDirectly
{
    public function handle(User $user, string $newEmail, User $admin, string $reason): void
    {
        $oldEmail = $user->email;

        DB::transaction(function () use ($user, $newEmail, $admin, $reason, $oldEmail): void {
            $user->emailChange()->delete();

            $user->emailAudits()->create([
                'old_email' => $oldEmail,
                'new_email' => $newEmail,
                'method' => EmailChangeMethod::Admin,
                'changed_by' => $admin->id,
                'reason' => $reason,
                'changed_at' => now(),
            ]);

            $user->forceFill(['email' => $newEmail, 'email_verified_at' => now()])->save();
        });

        Notification::route('mail', $oldEmail)->notify(new EmailChangedByAdmin($user, $oldEmail));
    }
}
