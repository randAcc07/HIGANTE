<?php

namespace App\Notifications;

use App\Models\AbsenceExcuse;
use App\Models\Enrollment;
use App\Models\ExcuseDecision;
use Illuminate\Notifications\Notification;

/**
 * An in-app notice about attendance: a warning, a drop flag, a confirmed
 * drop, a reinstatement or a class's excuse decision (to the learner), a
 * learner flagged for dropping (to the dean), or an excuse to decide for a
 * class (to its teacher, or the dean for a class without one). Email comes later, once a mail server exists.
 */
class AttendanceNotice extends Notification
{
    public const WARNING = 'warning';

    public const FLAGGED = 'flagged';

    public const DROPPED = 'dropped';

    public const REINSTATED = 'reinstated';

    public const LEARNER_FLAGGED = 'learner_flagged';

    public const EXCUSE_TO_DECIDE = 'excuse_to_decide';

    public const EXCUSE_APPROVED = 'excuse_approved';

    public const EXCUSE_DECLINED = 'excuse_declined';

    public const EXCUSE_REVOKED = 'excuse_revoked';

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
     * To a class's teacher (or the dean, for a class without one), linking
     * to the decision to make.
     */
    public static function excuseToDecide(ExcuseDecision $decision): self
    {
        $excuse = $decision->absenceExcuse;
        $excuse->loadMissing('learner.user');

        return new self(
            self::EXCUSE_TO_DECIDE,
            __(':name asks to excuse :days (:reason) in :subject.', ['name' => $excuse->learner->user->full_name, 'days' => self::days($excuse), 'reason' => $excuse->reason->label(), 'subject' => self::subject($decision)]),
            route('excuses.decisions.show', $decision, false),
        );
    }

    public static function excuseApproved(ExcuseDecision $decision): self
    {
        return new self(self::EXCUSE_APPROVED, __('Your excuse for :days was approved in :subject; those absences are excused.', ['days' => self::days($decision->absenceExcuse), 'subject' => self::subject($decision)]), route('learner.excuses.index', [], false));
    }

    public static function excuseDeclined(ExcuseDecision $decision): self
    {
        return new self(self::EXCUSE_DECLINED, __('Your excuse for :days was not approved in :subject: :remarks', ['days' => self::days($decision->absenceExcuse), 'subject' => self::subject($decision), 'remarks' => $decision->remarks]), route('learner.excuses.index', [], false));
    }

    public static function excuseRevoked(ExcuseDecision $decision): self
    {
        return new self(self::EXCUSE_REVOKED, __('Your excuse for :days was revoked in :subject, so those absences count again: :remarks', ['days' => self::days($decision->absenceExcuse), 'subject' => self::subject($decision), 'remarks' => $decision->revoke_remarks]), route('learner.excuses.index', [], false));
    }

    /**
     * "CS 101" (the subject the decision is for).
     */
    private static function subject(ExcuseDecision $decision): string
    {
        $decision->loadMissing('subjectOffering.subject');

        return $decision->subjectOffering->subject->code;
    }

    /**
     * "Oct 5", "Oct 5–9" or "Oct 30–Nov 2".
     */
    public static function days(AbsenceExcuse $excuse): string
    {
        $from = $excuse->date_from;
        $to = $excuse->date_to;

        if ($from->isSameDay($to)) {
            return $from->format('M j');
        }

        return $from->format('M j').'–'.($from->isSameMonth($to) ? $to->format('j') : $to->format('M j'));
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
