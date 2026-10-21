<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ClassCover;
use App\Models\Schedule;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A class's attendance belongs to the teacher who teaches it (and a
 * substitute covering it, on the days they cover); the dean of its college
 * sees it, and corrects it only where no teacher can. A closed term's attendance can be viewed but not
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

    /**
     * Teachers mark their classes (and the classes they cover, those days).
     * The dean only corrects where no teacher can: a class whose teacher
     * (or that day's substitute) isn't active, or a day past the term's
     * teacher edit limit. As with excuses, the class's teacher decides.
     */
    public function takeAttendance(User $user, Schedule $schedule, ?CarbonImmutable $date = null): bool
    {
        if (! $this->viewAttendance($user, $schedule, $date) || $schedule->term->isClosed()) {
            return false;
        }

        return $user->role !== UserRole::Dean || ($date !== null && self::onlyDeanCan($schedule, $date));
    }

    /**
     * Whether no teacher can change the class's marks for that day.
     */
    public static function onlyDeanCan(Schedule $schedule, CarbonImmutable $date): bool
    {
        $schedule->loadMissing(['teacher.user', 'term.attendancePolicy']);

        if ($schedule->term->attendancePolicy?->lockedForTeachers($date)) {
            return true;
        }

        $substitute = ClassCover::with('substitute.user')->where('schedule_id', $schedule->id)->onDate($date)->first()?->substitute;

        return ! $schedule->teacher->user->is_active && ! ($substitute?->user->is_active ?? false);
    }
}
