<?php

namespace App\Notifications;

use App\Actions\Accounts\SendAccountSetupLink;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an account is made for someone, or when they are sent a new
 * link to set their password.
 */
class AccountSetup extends Notification
{
    public function __construct(public string $url) {}

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
            ->subject('Set up your '.config('app.name').' account')
            ->line('An account was made for you. Choose a password to start using it.')
            ->action('Set your password', $this->url)
            ->line('The link works once and expires in '.SendAccountSetupLink::LIFETIME_DAYS.' days. If it has expired, ask the school for a new one.');
    }
}
