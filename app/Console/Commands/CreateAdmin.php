<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Public registration is off, so this is how the first admin account is
 * made. Every other account is created by an admin. The password is always
 * prompted for, so it never ends up in shell history.
 */
#[Signature('app:create-admin {--name= : Full name} {--email= : Login email address}')]
#[Description('Create an admin account')]
class CreateAdmin extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $input = [
            'name' => $this->option('name') ?: text('Name', required: true),
            'email' => $this->option('email') ?: text('Email', required: true),
            'password' => password('Password', required: true),
            'password_confirmation' => password('Confirm password', required: true),
        ];

        $validator = Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = new User($validator->safe()->only(['name', 'email', 'password']));
        $user->role = UserRole::Admin;
        // Created by whoever runs the server, so there is no inbox to verify.
        $user->email_verified_at = now();
        $user->save();

        $this->components->info("Admin account created for {$user->email}.");

        return self::SUCCESS;
    }
}
