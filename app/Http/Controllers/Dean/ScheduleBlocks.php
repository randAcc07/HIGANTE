<?php

namespace App\Http\Controllers\Dean;

use App\Enums\DutyStatus;
use App\Models\Schedule;
use App\Models\Teacher;

/**
 * How schedules and teachers are shaped for the timetable screens.
 */
final class ScheduleBlocks
{
    /**
     * One class on the weekly grid.
     *
     * @return array<string, mixed>
     */
    public static function block(Schedule $schedule): array
    {
        $offering = $schedule->subjectOffering;

        return [
            'id' => $schedule->id,
            'subject_offering_id' => $schedule->subject_offering_id,
            'batch_id' => $schedule->batch_id,
            'weekday' => $schedule->weekday(),
            'start' => $schedule->starts_at->format('H:i'),
            'end' => $schedule->ends_at->format('H:i'),
            'mode' => $schedule->mode->value,
            'room_id' => $schedule->room_id,
            'teacher_id' => $schedule->teacher_id,
            'subject' => $offering->subject->code,
            'section' => $offering->section->name,
            'batch' => $schedule->batch?->name,
            'room' => $schedule->room?->label,
            'teacher' => $schedule->teacher->user->full_name,
        ];
    }

    /**
     * The college's teachers who can take classes: active accounts, those on
     * leave marked so the dean is warned.
     *
     * @return list<array{id: int, name: string, on_leave: bool}>
     */
    public static function teacherOptions(int $collegeId): array
    {
        return array_values(Teacher::query()
            ->with('user:id,first_name,middle_name,last_name,suffix')
            ->whereHas('user', fn ($user) => $user->where('college_id', $collegeId)->where('is_active', true))
            ->get()
            ->sortBy(fn (Teacher $teacher): string => $teacher->user->last_name.' '.$teacher->user->first_name)
            ->map(fn (Teacher $teacher): array => [
                'id' => $teacher->id,
                'name' => $teacher->user->full_name,
                'on_leave' => $teacher->duty_status === DutyStatus::OnLeave,
            ])
            ->all());
    }
}
