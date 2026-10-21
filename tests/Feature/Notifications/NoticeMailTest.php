<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\AttendanceNotice;
use App\Notifications\CoverNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class NoticeMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_notices_that_change_standing_or_need_action_are_emailed()
    {
        $user = User::factory()->create();
        $emailed = fn (string $class, string $kind): bool => in_array('mail', (new $class($kind, 'Message'))->via($user), true);

        foreach ([AttendanceNotice::WARNING, AttendanceNotice::FLAGGED, AttendanceNotice::DROPPED, AttendanceNotice::REINSTATED, AttendanceNotice::EXCUSE_DECLINED, AttendanceNotice::EXCUSE_REVOKED, AttendanceNotice::LEARNER_FLAGGED] as $kind) {
            $this->assertTrue($emailed(AttendanceNotice::class, $kind), $kind);
        }

        foreach ([AttendanceNotice::EXCUSE_APPROVED, AttendanceNotice::EXCUSE_TO_DECIDE] as $kind) {
            $this->assertFalse($emailed(AttendanceNotice::class, $kind), $kind);
        }

        $this->assertTrue($emailed(CoverNotice::class, CoverNotice::ASSIGNED));
        $this->assertTrue($emailed(CoverNotice::class, CoverNotice::REMOVED));
    }

    public function test_the_bell_gets_it_at_once_and_the_email_after_the_response()
    {
        $user = User::factory()->create(['first_name' => 'Ana']);

        $user->notify(new AttendanceNotice(AttendanceNotice::WARNING, 'Warning: you have 3 equivalent absences in CS 101.', '/learner/classes'));

        $this->assertSame(1, $user->notifications()->count());
        $this->assertCount(0, $this->sent());

        app(DeferredCallbackCollection::class)->invoke();

        $this->assertCount(1, $this->sent());
        $mail = $this->sent()->first()?->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $mail);
        $this->assertSame('Attendance warning', $mail->getSubject());
        $this->assertStringContainsString('Hello Ana,', (string) $mail->getTextBody());
        $this->assertStringContainsString('Warning: you have 3 equivalent absences in CS 101.', (string) $mail->getTextBody());
        $this->assertStringContainsString(url('/learner/classes'), (string) $mail->getTextBody());
    }

    public function test_an_in_app_only_notice_sends_no_email()
    {
        User::factory()->create()->notify(new AttendanceNotice(AttendanceNotice::EXCUSE_TO_DECIDE, 'Decide this.'));
        app(DeferredCallbackCollection::class)->invoke();

        $this->assertCount(0, $this->sent());
    }

    /**
     * @return Collection<int, SentMessage>
     */
    private function sent(): Collection
    {
        $transport = Mail::mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport->messages();
    }
}
