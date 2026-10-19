<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the old address after an admin changed the account's email.
 */
class EmailChangedByAdmin extends Notification
{
    public function __construct(public User $user, public string $oldEmail) {}

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
            ->subject('Your account email was changed')
            ->line('A school administrator changed the email of your '.config('app.name')." account from {$this->oldEmail} to {$this->user->email}.")
            ->line('If you did not ask for this, contact the school administrator right away.');
    }
}
