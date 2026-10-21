<?php

namespace App\Console\Commands;

use App\Actions\Email\SetEmailDirectly;
use App\Console\Commands\Concerns\ManagesAdmins;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

use function Laravel\Prompts\text;

/**
 * An admin who lost their inbox: their email changed directly (verified),
 * any pending change cancelled, audited in email_audits as 'server', and
 * the old address told.
 */
#[Signature('app:admin-email
    {current : The admin\'s current login email}
    {new : The new email}
    {--reason= : Why (kept in the audit)}
    {--force : Don\'t ask for confirmation}')]
#[Description('Change an admin\'s email')]
class ChangeAdminEmail extends Command
{
    use ManagesAdmins;

    public function handle(SetEmailDirectly $setEmail): int
    {
        $admin = $this->admin($this->stringArgument('current'));

        if ($admin === null) {
            return self::FAILURE;
        }

        $new = $this->stringArgument('new');
        $reason = $this->stringOption('reason') ?: text('Reason (kept in the audit)', required: true);

        $validator = Validator::make(['email' => $new, 'reason' => $reason], [
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        if (! $this->confirmed("Change {$admin->full_name}'s email from {$admin->email} to {$new}?")) {
            return self::FAILURE;
        }

        $old = $admin->email;
        $setEmail->handle($admin, $new, null, $reason);
        $this->logChange('email changed', $admin, ['old_email' => $old, 'reason' => $reason]);

        $this->components->info("Email changed to {$new}. The old address was told.");

        return self::SUCCESS;
    }
}
