<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('app:reactivate-admin
    {email : The admin\'s login email}
    {--force : Don\'t ask for confirmation}')]
#[Description('Let a deactivated admin log in again')]
class ReactivateAdmin extends SetAdminActive
{
    protected function activate(): bool
    {
        return true;
    }
}
