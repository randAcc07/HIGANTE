<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ClassCover;
use App\Models\Schedule;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A class's attendance belongs to the teacher who teaches it, and the dean
 * of its college can correct it; a substitute covering it may too, on the
 * days they cover. A closed term's attendance can be viewed but not
 * changed. (Changing the class itself goes through SectionPolicy.)
 */
class SchedulePolicy
{
    /**
     * With a date, a substitute covering the class that day may too.
     */
    public function viewAttendance(User $user, Schedule $schedule, ?CarbonImmutable $date = null): bool
    {
        return match ($user->role) {
            UserRole::Teacher => $user->teacher !== null && ($schedule->teacher_id === $user->teacher->id
                || ($date !== null && ClassCover::where('schedule_id', $schedule->id)->where('substitute_id', $user->teacher->id)->onDate($date)->exists())),
            UserRole::Dean => $user->college_id === $schedule->subjectOffering->section->program->college_id,
            default => false,
        };
    }

    public function takeAttendance(User $user, Schedule $schedule, ?CarbonImmutable $date = null): bool
    {
        return $this->viewAttendance($user, $schedule, $date) && ! $schedule->term->isClosed();
    }
}
