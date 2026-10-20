<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use App\Registration\TermRegistrar;

/**
 * Registration is the registrar's, campus-wide, until the term ends.
 */
class TermRegistrationPolicy
{
    public function create(User $user, Term $term): bool
    {
        return $user->role === UserRole::Registrar && TermRegistrar::isOpen($term);
    }

    /**
     * Mark irregular, withdraw or undo.
     */
    public function update(User $user, TermRegistration $registration): bool
    {
        return $this->create($user, $registration->term);
    }
}
