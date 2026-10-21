<?php

namespace App\Notifications;

use App\Models\ClassCover;
use App\Notifications\Concerns\InAppWithMail;
use App\Scheduling\ScheduleConflicts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A notice to a substitute, in-app and emailed: classes they cover for a
 * teacher, or a cover the dean removed.
 */
class CoverNotice extends Notification implements ShouldQueue
{
    use InAppWithMail, Queueable;

    public const ASSIGNED = 'cover_assigned';

    public const REMOVED = 'cover_removed';

    public function __construct(public string $kind, public string $message, public ?string $url = null) {}

    /**
     * Covers arranged together (one range, one teacher), to their substitute.
     *
     * @param  list<ClassCover>  $covers
     */
    public static function assigned(array $covers): self
    {
        $first = $covers[0];

        return new self(
            self::ASSIGNED,
            __('You cover :teacher\'s :classes on :range.', [
                'teacher' => $first->schedule->teacher->user->full_name,
                'classes' => implode(', ', array_map(fn (ClassCover $cover): string => self::className($cover), $covers)),
                'range' => $first->range(),
            ]),
            route('teacher.classes.index', ['date' => $first->starts_on->toDateString()], false),
        );
    }

    public static function removed(ClassCover $cover): self
    {
        return new self(self::REMOVED, __('You no longer cover :class on :range.', ['class' => self::className($cover), 'range' => $cover->range()]));
    }

    /**
     * "CS 101 (BSCS 1-A, Mon 8:00–9:30 AM)".
     */
    private static function className(ClassCover $cover): string
    {
        $cover->loadMissing(['schedule.subjectOffering.subject', 'schedule.subjectOffering.section.program']);
        $offering = $cover->schedule->subjectOffering;

        return "{$offering->subject->code} ({$offering->section->program->code} {$offering->section->name}, ".ScheduleConflicts::when($cover->schedule).')';
    }

    /**
     * Both are emailed: the classes aren't on the substitute's own week.
     *
     * @return array<string, string>
     */
    protected static function mailSubjects(): array
    {
        return [
            self::ASSIGNED => __('Class cover arranged'),
            self::REMOVED => __('Class cover removed'),
        ];
    }
}
