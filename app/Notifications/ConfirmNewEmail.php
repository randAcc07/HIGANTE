<?php

namespace App\Notifications;

use App\Models\EmailChange;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the new address: confirm that it receives mail.
 */
class ConfirmNewEmail extends Notification
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
            ->subject('Confirm your new email address')
            ->line("{$this->change->user->full_name} asked to use this address for their ".config('app.name').' account.')
            ->action('Confirm this address', $this->change->signedUrl('new'))
            ->line('The change also needs approval from the account\'s current email address. The link expires '.$this->change->expires_at->toDayDateTimeString().'.')
            ->line('If you did not expect this, you can ignore this email.');
    }
}
