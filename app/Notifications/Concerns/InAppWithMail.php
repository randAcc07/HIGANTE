<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A notice shown in the bell, and emailed too when its kind has a mail
 * subject. The email goes out after the response (the deferred queue
 * connection: no worker and no polling), so the request that caused it
 * never waits on the mail server; a failed send is logged, and the notice
 * is still in the bell.
 *
 * Uses $kind, $message and $url (relative) from the notice.
 */
trait InAppWithMail
{
    /**
     * Mail subjects by kind; kinds not listed stay in-app only.
     *
     * @return array<string, string>
     */
    abstract protected static function mailSubjects(): array;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return isset(static::mailSubjects()[$this->kind]) ? ['database', 'mail'] : ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync', 'mail' => 'deferred'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(static::mailSubjects()[$this->kind] ?? config('app.name'))
            ->greeting($notifiable instanceof User ? __('Hello :name,', ['name' => $notifiable->first_name]) : __('Hello,'))
            ->line($this->message);

        return $mail->action(__('Open the :app', ['app' => config('app.name')]), url($this->url ?? route('notifications.index', [], false)));
    }

    /**
     * @return array{kind: string, message: string, url: string|null}
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'message' => $this->message, 'url' => $this->url];
    }
}
