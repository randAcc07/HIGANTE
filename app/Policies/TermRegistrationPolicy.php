<?php

namespace App\Policies;

use App\Enums\RegistrationStatus;
use App\Enums\UserRole;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use App\Registration\TermRegistrar;

/**
 * Registration is the registrar's, campus-wide, until the term ends.
 * Placement (section, subjects, batches) is the dean's of the learner's
 * college until the term is closed, for learners still registered.
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

    public function view(User $user, TermRegistration $registration): bool
    {
        return $user->role === UserRole::Dean
            && $user->college_id === $registration->learner->program->college_id;
    }

    public function place(User $user, TermRegistration $registration): bool
    {
        return $this->view($user, $registration)
            && $registration->status === RegistrationStatus::Registered
            && ! $registration->term->isClosed();
    }
}
