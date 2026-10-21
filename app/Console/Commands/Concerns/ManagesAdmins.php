<?php

namespace App\Console\Commands\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Shared by the server's admin-account commands: admins are peers, so no
 * admin manages another in the app; whoever runs the server does, and each
 * run is logged.
 */
trait ManagesAdmins
{
    /**
     * The admin account with this email, or null (with the error shown).
     */
    protected function admin(string $email): ?User
    {
        $user = User::where('email', $email)->first();

        if ($user === null || $user->role !== UserRole::Admin) {
            $this->components->error("No admin account has the email {$email}.");

            return null;
        }

        return $user;
    }

    protected function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    protected function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }

    /**
     * Ask before changing anything, unless --force.
     */
    protected function confirmed(string $question): bool
    {
        return (bool) $this->option('force') || $this->confirm($question);
    }

    /**
     * One line in the application log per change: what, to whom, by which
     * server user.
     *
     * @param  array<string, mixed>  $details
     */
    protected function logChange(string $action, User $admin, array $details = []): void
    {
        Log::info("Admin account {$action} from the server.", [
            'command' => $this->getName(),
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'server_user' => get_current_user(),
            ...$details,
        ]);
    }
}
