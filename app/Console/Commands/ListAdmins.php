<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:list-admins')]
#[Description('List the admin accounts')]
class ListAdmins extends Command
{
    public function handle(): int
    {
        $admins = User::where('role', UserRole::Admin)->orderBy('last_name')->orderBy('first_name')->get();

        if ($admins->isEmpty()) {
            $this->components->warn('There are no admin accounts. Make one with `php artisan app:create-admin`.');

            return self::SUCCESS;
        }

        $this->table(['Name', 'Email', 'Status', 'Since'], $admins->map(fn (User $admin): array => [
            $admin->full_name,
            $admin->email,
            $admin->is_active ? 'Active' : 'Deactivated',
            $admin->created_at?->toDateString() ?? '',
        ])->all());

        return self::SUCCESS;
    }
}
