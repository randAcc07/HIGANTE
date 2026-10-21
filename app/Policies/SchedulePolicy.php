<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Schedule;
use App\Models\User;

/**
 * A class's attendance belongs to the teacher who teaches it, and the dean
 * of its college can correct it. A closed term's attendance can be viewed
 * but not changed. (Changing the class itself goes through SectionPolicy.)
 */
class SchedulePolicy
{
    public function viewAttendance(User $user, Schedule $schedule): bool
    {
        return match ($user->role) {
            UserRole::Teacher => $user->teacher !== null && $schedule->teacher_id === $user->teacher->id,
            UserRole::Dean => $user->college_id === $schedule->subjectOffering->section->program->college_id,
            default => false,
        };
    }

    public function takeAttendance(User $user, Schedule $schedule): bool
    {
        return $this->viewAttendance($user, $schedule) && ! $schedule->term->isClosed();
    }
}
