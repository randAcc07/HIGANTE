<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\SendAccountSetupLink;
use App\Models\User;
use App\Notifications\AccountSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $link;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->user = User::factory()->teacher()->create(['email' => 'teresa@example.com']);
        $this->link = app(SendAccountSetupLink::class)->handle($this->user);
    }

    public function test_the_link_is_emailed_and_opens_the_password_form()
    {
        Notification::assertSentTo($this->user, AccountSetup::class, fn (AccountSetup $notification) => $notification->url === $this->link);

        $this->get($this->link)->assertInertia(fn (Assert $page) => $page
            ->component('auth/AccountSetup')
            ->where('valid', true)
            ->where('email', 'teresa@example.com'));
    }

    public function test_setting_a_password_lets_the_user_log_in()
    {
        $this->post($this->link, ['password' => 'a-new-password', 'password_confirmation' => 'a-new-password'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check('a-new-password', $this->user->refresh()->password));

        $this->post(route('login.store'), ['email' => 'teresa@example.com', 'password' => 'a-new-password']);
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_the_password_must_be_confirmed()
    {
        $this->post($this->link, ['password' => 'a-new-password', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');
    }

    public function test_a_link_works_once()
    {
        $this->post($this->link, ['password' => 'a-new-password', 'password_confirmation' => 'a-new-password']);

        $this->get($this->link)->assertInertia(fn (Assert $page) => $page->where('valid', false));
        $this->post($this->link, ['password' => 'another-password', 'password_confirmation' => 'another-password']);
        $this->assertTrue(Hash::check('a-new-password', $this->user->refresh()->password));
    }

    public function test_expired_tampered_and_deactivated_links_are_refused()
    {
        $this->get($this->link.'x')->assertInertia(fn (Assert $page) => $page->where('valid', false));

        $this->user->forceFill(['is_active' => false])->save();
        $this->get($this->link)->assertInertia(fn (Assert $page) => $page->where('valid', false));
        $this->user->forceFill(['is_active' => true])->save();

        $this->travel(SendAccountSetupLink::LIFETIME_DAYS)->days();
        $this->travel(1)->minutes();
        $this->get($this->link)->assertInertia(fn (Assert $page) => $page->where('valid', false));
    }
}
