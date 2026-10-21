<?php

namespace App\Policies;

use App\Attendance\Excuses;
use App\Enums\UserRole;
use App\Models\AbsenceExcuse;
use App\Models\User;

/**
 * An excuse is the learner's own; the teachers who decide one of its
 * classes see it (with its files), and the dean of the learner's college
 * oversees it.
 */
class AbsenceExcusePolicy
{
    public function view(User $user, AbsenceExcuse $excuse): bool
    {
        return match ($user->role) {
            UserRole::Learner => $user->learner?->id === $excuse->learner_id,
            UserRole::Teacher => $user->teacher !== null && Excuses::decidableBy($user->teacher->id)->where('absence_excuse_id', $excuse->id)->exists(),
            UserRole::Dean => $this->review($user, $excuse),
            default => false,
        };
    }

    /**
     * Oversee the excuse (the dean of the learner's college).
     */
    public function review(User $user, AbsenceExcuse $excuse): bool
    {
        return $user->role === UserRole::Dean
            && $user->college_id === $excuse->learner->program->college_id;
    }
}
