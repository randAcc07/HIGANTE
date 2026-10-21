<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;

/**
 * Keeps users.last_login_at, which tells who never signed in (still
 * waiting on their setup link). Written directly, so signing in doesn't
 * count as a change to the account.
 */
class RecordSignIn
{
    public function handle(Login $event): void
    {
        if ($event->user instanceof User) {
            DB::table('users')->where('id', $event->user->id)->update(['last_login_at' => now()]);
        }
    }
}
