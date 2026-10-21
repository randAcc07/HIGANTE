<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Program;
use App\Models\User;

/**
 * Programs themselves are the admin's (role middleware); a program's
 * curriculum is kept by the dean of its college.
 */
class ProgramPolicy
{
    public function editCurriculum(User $user, Program $program): bool
    {
        return $user->role === UserRole::Dean && $user->college_id === $program->college_id;
    }
}
