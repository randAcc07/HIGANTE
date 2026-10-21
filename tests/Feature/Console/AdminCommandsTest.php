<?php

namespace Tests\Feature\Console;

use App\Enums\EmailChangeMethod;
use App\Models\EmailAudit;
use App\Models\User;
use App\Notifications\AccountSetup;
use App\Notifications\EmailChangedByAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The server's commands for admin accounts, which no admin can change in
 * the app.
 */
class AdminCommandsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Log::spy();
        $this->admin = User::factory()->admin()->create(['email' => 'admin@example.com', 'first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Cruz', 'suffix' => null]);
    }

    public function test_it_lists_the_admins()
    {
        $second = User::factory()->admin()->create(['email' => 'second@example.com', 'first_name' => 'Ben', 'last_name' => 'Dizon']);
        $second->forceFill(['is_active' => false])->save();
        User::factory()->registrar()->create(['email' => 'registrar@example.com']);

        $this->assertSame(0, Artisan::call('app:list-admins'));
        $rows = collect(explode("\n", Artisan::output()));

        $this->assertNotNull($rows->first(fn (string $row) => str_contains($row, 'admin@example.com') && str_contains($row, 'Active')));
        $this->assertNotNull($rows->first(fn (string $row) => str_contains($row, 'second@example.com') && str_contains($row, 'Deactivated')));
        $this->assertNull($rows->first(fn (string $row) => str_contains($row, 'registrar@example.com')));
    }

    public function test_reset_prints_a_setup_link_and_emails_it_only_when_asked()
    {
        $this->artisan('app:reset-admin', ['email' => 'admin@example.com', '--force' => true])
            ->expectsOutputToContain('/account-setup/'.$this->admin->id)
            ->assertSuccessful();
        Notification::assertNothingSent();

        $this->artisan('app:reset-admin', ['email' => 'admin@example.com', '--force' => true, '--send' => true])->assertSuccessful();
        Notification::assertSentTo($this->admin, AccountSetup::class);

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'Admin account setup link made from the server.' && $context['admin_email'] === 'admin@example.com');
    }

    public function test_commands_only_touch_admin_accounts_and_ask_first()
    {
        User::factory()->registrar()->create(['email' => 'registrar@example.com']);

        $this->artisan('app:reset-admin', ['email' => 'registrar@example.com', '--force' => true])
            ->expectsOutputToContain('No admin account has the email registrar@example.com.')
            ->assertFailed();

        $this->artisan('app:reset-admin', ['email' => 'admin@example.com'])
            ->expectsConfirmation('Make a new setup link for Ana Cruz (admin@example.com)?', 'no')
            ->assertFailed();
    }

    public function test_an_admins_email_changes_on_the_server_and_is_audited()
    {
        $this->artisan('app:admin-email', ['current' => 'admin@example.com', 'new' => 'ana@example.com', '--reason' => 'Lost access to the old inbox', '--force' => true])
            ->assertSuccessful();

        $this->assertSame('ana@example.com', $this->admin->refresh()->email);
        $audit = EmailAudit::sole();
        $this->assertSame(EmailChangeMethod::Server, $audit->method);
        $this->assertNull($audit->changed_by);
        $this->assertSame('Lost access to the old inbox', $audit->reason);
        Notification::assertSentOnDemand(EmailChangedByAdmin::class);

        User::factory()->create(['email' => 'taken@example.com']);
        $this->artisan('app:admin-email', ['current' => 'ana@example.com', 'new' => 'taken@example.com', '--reason' => 'x', '--force' => true])
            ->assertFailed();
    }

    public function test_deactivating_keeps_at_least_one_active_admin()
    {
        $this->artisan('app:deactivate-admin', ['email' => 'admin@example.com', '--force' => true])
            ->expectsOutputToContain('This is the only active admin.')
            ->assertFailed();
        $this->assertTrue($this->admin->refresh()->is_active);

        User::factory()->admin()->create();
        $this->artisan('app:deactivate-admin', ['email' => 'admin@example.com', '--force' => true])->assertSuccessful();
        $this->assertFalse($this->admin->refresh()->is_active);

        $this->artisan('app:reset-admin', ['email' => 'admin@example.com', '--force' => true])
            ->expectsOutputToContain('is deactivated')
            ->assertFailed();

        $this->artisan('app:reactivate-admin', ['email' => 'admin@example.com', '--force' => true])->assertSuccessful();
        $this->assertTrue($this->admin->refresh()->is_active);
    }
}
