<?php

namespace App\Actions\Email;

use App\Models\EmailChange;
use App\Models\User;
use App\Notifications\ApproveEmailChange;
use App\Notifications\ConfirmNewEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Starts an email change: replaces any pending request, then emails the new
 * address a confirmation link and the current address an approval link.
 */
class RequestEmailChange
{
    public function handle(User $user, string $newEmail): EmailChange
    {
        $change = DB::transaction(function () use ($user, $newEmail): EmailChange {
            // One pending request per user; deleting the old row voids its links.
            $user->emailChange()->delete();

            return $user->emailChange()->create([
                'new_email' => $newEmail,
                'expires_at' => now()->addHours(EmailChange::LIFETIME_HOURS),
            ]);
        });

        Notification::route('mail', $newEmail)->notify(new ConfirmNewEmail($change));
        $user->notify(new ApproveEmailChange($change));

        return $change;
    }
}
