<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\AbsenceExcuse;
use App\Models\User;

/**
 * An excuse is the learner's own, and the dean of the learner's college
 * reviews it (the service refuses anything touching a closed term).
 */
class AbsenceExcusePolicy
{
    public function view(User $user, AbsenceExcuse $excuse): bool
    {
        return match ($user->role) {
            UserRole::Learner => $user->learner?->id === $excuse->learner_id,
            UserRole::Dean => $this->review($user, $excuse),
            default => false,
        };
    }

    public function review(User $user, AbsenceExcuse $excuse): bool
    {
        return $user->role === UserRole::Dean
            && $user->college_id === $excuse->learner->program->college_id;
    }
}
