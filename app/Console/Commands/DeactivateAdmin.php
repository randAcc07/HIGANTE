<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('app:deactivate-admin
    {email : The admin\'s login email}
    {--force : Don\'t ask for confirmation}')]
#[Description('Stop an admin from logging in')]
class DeactivateAdmin extends SetAdminActive
{
    protected function activate(): bool
    {
        return false;
    }
}
