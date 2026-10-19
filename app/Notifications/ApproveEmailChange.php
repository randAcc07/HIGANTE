<?php

namespace App\Notifications;

use App\Models\EmailChange;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the current address: approve moving the account to the new one,
 * or stop the change.
 */
class ApproveEmailChange extends Notification
{
    public function __construct(public EmailChange $change) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Approve your email change')
            ->line('Someone asked to change the email of your '.config('app.name')." account to {$this->change->new_email}.")
            ->action('Review the change', $this->change->signedUrl('current'))
            ->line('If this was not you, open the link and cancel the change, then change your password. The link expires '.$this->change->expires_at->toDayDateTimeString().'.');
    }
}
