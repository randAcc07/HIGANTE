<?php

namespace Tests\Feature\Settings;

use App\Models\EmailChange;
use App\Notifications\ApproveEmailChange;
use App\Notifications\ConfirmNewEmail;
use App\Notifications\EmailChangedByAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The other email change tests fake notifications; this one renders the
 * actual emails, so a broken template or link fails here.
 */
class EmailChangeMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_emails_render_with_working_links()
    {
        $change = EmailChange::factory()->create(['new_email' => 'new@example.com']);
        $user = $change->user;

        $confirm = (new ConfirmNewEmail($change))->toMail($user);
        $this->assertSame($change->signedUrl('new'), $confirm->actionUrl);
        $this->assertStringContainsString($user->full_name, (string) $confirm->render());

        $approve = (new ApproveEmailChange($change))->toMail($user);
        $this->assertSame($change->signedUrl('current'), $approve->actionUrl);
        $this->assertStringContainsString('new@example.com', (string) $approve->render());

        $this->get((string) $confirm->actionUrl)->assertOk();
        $this->get((string) $approve->actionUrl)->assertOk();

        $notice = (new EmailChangedByAdmin($user, 'lost@example.com'))->toMail($user);
        $this->assertStringContainsString('lost@example.com', (string) $notice->render());
    }
}
