<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Program;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;

/**
 * Scheduling belongs to the dean of the section's college. A section's
 * subjects, batches and classes are all authorized through it, and a closed
 * term is read-only.
 */
class SectionPolicy
{
    public function view(User $user, Section $section): bool
    {
        return $user->role === UserRole::Dean
            && $user->college_id === $section->program->college_id;
    }

    public function create(User $user, Program $program, Term $term): bool
    {
        return $user->role === UserRole::Dean
            && $user->college_id === $program->college_id
            && ! $term->isClosed();
    }

    /**
     * Change the section, or its subjects, batches and classes.
     */
    public function update(User $user, Section $section): bool
    {
        return $this->view($user, $section) && ! $section->term->isClosed();
    }

    public function delete(User $user, Section $section): bool
    {
        return $this->update($user, $section);
    }
}
