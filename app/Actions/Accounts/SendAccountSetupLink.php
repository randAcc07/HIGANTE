<?php

namespace App\Actions\Accounts;

use App\Models\User;
use App\Notifications\AccountSetup;
use Illuminate\Support\Facades\URL;

/**
 * A link for setting an account's password: emailed to the user and handed
 * back so whoever made the account can pass it on another way. It is a
 * signed URL that lasts LIFETIME_DAYS and stops working once the password
 * changes (it carries a fingerprint of the current password hash), so a
 * used or leaked link cannot be used again. The same link doubles as an
 * admin-assisted password reset.
 */
class SendAccountSetupLink
{
    public const LIFETIME_DAYS = 7;

    public function handle(User $user): string
    {
        $url = URL::temporarySignedRoute('account-setup.show', now()->addDays(self::LIFETIME_DAYS), [
            'user' => $user->id,
            'key' => self::fingerprint($user),
        ]);

        $user->notify(new AccountSetup($url));

        return $url;
    }

    /**
     * Changes whenever the password does, which voids earlier links.
     */
    public static function fingerprint(User $user): string
    {
        return substr(hash('sha256', $user->password), 0, 32);
    }
}
