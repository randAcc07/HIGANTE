<?php

namespace App\Console\Commands;

use App\Actions\Accounts\SendAccountSetupLink;
use App\Console\Commands\Concerns\ManagesAdmins;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * An admin who forgot their password: a new setup link, printed for
 * whoever runs the server to hand over (--send also emails it). The
 * current password keeps working until the link is used.
 */
#[Signature('app:reset-admin
    {email : The admin\'s login email}
    {--send : Also email the link to the admin}
    {--force : Don\'t ask for confirmation}')]
#[Description('Make a new password setup link for an admin')]
class ResetAdmin extends Command
{
    use ManagesAdmins;

    public function handle(SendAccountSetupLink $setupLink): int
    {
        $admin = $this->admin($this->stringArgument('email'));

        if ($admin === null) {
            return self::FAILURE;
        }

        if (! $admin->is_active) {
            $this->components->error("{$admin->email} is deactivated. Reactivate it first with `php artisan app:reactivate-admin {$admin->email}`.");

            return self::FAILURE;
        }

        if (! $this->confirmed("Make a new setup link for {$admin->full_name} ({$admin->email})?")) {
            return self::FAILURE;
        }

        $url = $setupLink->handle($admin, notify: (bool) $this->option('send'));
        $this->logChange('setup link made', $admin, ['emailed' => (bool) $this->option('send')]);

        $this->components->info('Setup link (valid for '.SendAccountSetupLink::LIFETIME_DAYS.' days, works once):');
        $this->line($url);

        if ($this->option('send')) {
            $this->components->info("Also emailed to {$admin->email}.");
        }

        return self::SUCCESS;
    }
}
