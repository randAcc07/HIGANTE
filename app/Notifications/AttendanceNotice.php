<?php

namespace App\Notifications;

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
