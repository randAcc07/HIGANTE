<?php

namespace App\Notifications;

use App\Models\Enrollment;
use Illuminate\Notifications\Notification;

/**
 * An in-app notice about attendance standing: a warning, a drop flag, a
 * confirmed drop or a reinstatement (to the learner), or a learner flagged
 * for dropping (to the dean). Email comes later, once a mail server exists.
 */
class AttendanceNotice extends Notification
{
    public const WARNING = 'warning';

    public const FLAGGED = 'flagged';

    public const DROPPED = 'dropped';

    public const REINSTATED = 'reinstated';

    public const LEARNER_FLAGGED = 'learner_flagged';

    public function __construct(public string $kind, public string $message, public ?string $url = null) {}

    /*
     * The notices, worded in one place. Build them through these (Standing
     * does, and so can the demo seeders), never by hand.
     */

    public static function warning(Enrollment $enrollment, int $count, int $dropAt): self
    {
        return new self(self::WARNING, __('Warning: you have :count equivalent absences in :class. At :drop you will be flagged for dropping.', ['count' => $count, 'class' => self::className($enrollment), 'drop' => $dropAt]));
    }

    public static function flagged(Enrollment $enrollment, int $count): self
    {
        return new self(self::FLAGGED, __('You have :count equivalent absences in :class and are flagged for dropping. Your dean will decide; see them if you have an excuse.', ['count' => $count, 'class' => self::className($enrollment)]));
    }

    /**
     * To the dean, linking to the term's flagged learners.
     */
    public static function learnerFlagged(Enrollment $enrollment, int $count): self
    {
        $enrollment->loadMissing('learner.user');

        return new self(
            self::LEARNER_FLAGGED,
            __(':name has :count equivalent absences in :class and is flagged for dropping.', ['name' => $enrollment->learner->user->full_name, 'count' => $count, 'class' => self::className($enrollment)]),
            route('dean.standing.index', ['term' => $enrollment->subjectOffering->section->term_id, 'status' => 'flagged'], false),
        );
    }

    public static function dropped(Enrollment $enrollment): self
    {
        return new self(self::DROPPED, __('You have been dropped from :class for absences.', ['class' => self::className($enrollment)]));
    }

    public static function reinstated(Enrollment $enrollment): self
    {
        return new self(self::REINSTATED, __('You have been reinstated in :class.', ['class' => self::className($enrollment)]));
    }

    /**
     * "CS 101 (BSCS 1-A)".
     */
    public static function className(Enrollment $enrollment): string
    {
        $enrollment->loadMissing(['subjectOffering.subject', 'subjectOffering.section.program']);
        $offering = $enrollment->subjectOffering;

        return "{$offering->subject->code} ({$offering->section->program->code} {$offering->section->name})";
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{kind: string, message: string, url: string|null}
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'message' => $this->message, 'url' => $this->url];
    }
}
