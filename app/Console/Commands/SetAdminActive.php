<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ManagesAdmins;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Stop an admin from logging in, or let them back in. Their records stay.
 * The last active admin can't be deactivated, so the app always has one.
 */
abstract class SetAdminActive extends Command
{
    use ManagesAdmins;

    abstract protected function activate(): bool;

    public function handle(): int
    {
        $admin = $this->admin($this->stringArgument('email'));

        if ($admin === null) {
            return self::FAILURE;
        }

        $activate = $this->activate();

        if ($admin->is_active === $activate) {
            $this->components->info("{$admin->email} is already ".($activate ? 'active' : 'deactivated').'.');

            return self::SUCCESS;
        }

        if (! $activate && User::where('role', UserRole::Admin)->where('is_active', true)->count() <= 1) {
            $this->components->error('This is the only active admin. Make or reactivate another admin first.');

            return self::FAILURE;
        }

        $question = $activate
            ? "Let {$admin->full_name} ({$admin->email}) log in again?"
            : "Stop {$admin->full_name} ({$admin->email}) from logging in? They are signed out too.";

        if (! $this->confirmed($question)) {
            return self::FAILURE;
        }

        $admin->forceFill(['is_active' => $activate])->save();
        $this->logChange($activate ? 'reactivated' : 'deactivated', $admin);

        $this->components->info($activate ? "{$admin->email} can log in again." : "{$admin->email} can no longer log in.");

        return self::SUCCESS;
    }
}
