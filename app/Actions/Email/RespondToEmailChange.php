<?php

namespace App\Actions\Email;

use App\Enums\EmailChangeMethod;
use App\Models\EmailChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records one address's answer to a pending email change. Whichever answer
 * completes the pair applies the change: the user's email becomes the new
 * address (verified, since it just proved it receives mail) and the change
 * is audited.
 */
class RespondToEmailChange
{
    /** Waiting for the other address. */
    public const PENDING = 'pending';

    /** Both addresses agreed; the email has changed. */
    public const COMPLETED = 'completed';

    /** Someone else took the new address first; the request was dropped. */
    public const TAKEN = 'taken';

    /** The old address said it was not them; the request was dropped. */
    public const DENIED = 'denied';

    /**
     * @return self::PENDING|self::COMPLETED|self::TAKEN
     */
    public function confirmNew(EmailChange $change): string
    {
        $change->new_confirmed_at ??= now();
        $change->save();

        return $this->completeIfReady($change);
    }

    /**
     * @return self::PENDING|self::COMPLETED|self::TAKEN
     */
    public function approveOld(EmailChange $change): string
    {
        $change->old_approved_at ??= now();
        $change->save();

        return $this->completeIfReady($change);
    }

    /**
     * @return self::DENIED
     */
    public function deny(EmailChange $change): string
    {
        $change->delete();

        return self::DENIED;
    }

    /**
     * @return self::PENDING|self::COMPLETED|self::TAKEN
     */
    private function completeIfReady(EmailChange $change): string
    {
        if (! $change->isComplete()) {
            return self::PENDING;
        }

        return DB::transaction(function () use ($change): string {
            /** @var User $user */
            $user = User::lockForUpdate()->findOrFail($change->user_id);

            if (User::where('email', $change->new_email)->whereKeyNot($user->id)->exists()) {
                $change->delete();

                return self::TAKEN;
            }

            $user->emailAudits()->create([
                'old_email' => $user->email,
                'new_email' => $change->new_email,
                'method' => EmailChangeMethod::SelfService,
                'changed_by' => $user->id,
                'changed_at' => now(),
            ]);

            $user->forceFill(['email' => $change->new_email, 'email_verified_at' => now()])->save();
            $change->delete();

            return self::COMPLETED;
        });
    }
}
