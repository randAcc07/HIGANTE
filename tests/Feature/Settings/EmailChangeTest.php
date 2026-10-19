<?php

namespace Tests\Feature\Settings;

use App\Enums\EmailChangeMethod;
use App\Models\EmailChange;
use App\Models\User;
use App\Notifications\ApproveEmailChange;
use App\Notifications\ConfirmNewEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_requesting_a_change_emails_both_addresses_and_changes_nothing_yet()
    {
        $user = User::factory()->learner()->create(['email' => 'old@example.com']);

        $this->actingAs($user)
            ->post(route('email-change.store'), ['new_email' => 'new@example.com', 'password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('old@example.com', $user->refresh()->email);
        $this->assertSame('new@example.com', $user->emailChange?->new_email);

        Notification::assertSentOnDemand(ConfirmNewEmail::class, fn ($notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'new@example.com');
        Notification::assertSentTo($user, ApproveEmailChange::class);
    }

    public function test_requesting_a_change_needs_the_current_password_and_a_new_unused_address()
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($user)
            ->post(route('email-change.store'), ['new_email' => 'new@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('password');

        $this->post(route('email-change.store'), ['new_email' => 'old@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['new_email' => 'That is already your email address.']);

        $this->post(route('email-change.store'), ['new_email' => 'taken@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('new_email');

        $this->assertDatabaseCount('email_changes', 0);
        Notification::assertNothingSent();
    }

    public function test_a_new_request_replaces_the_pending_one_and_voids_its_links()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('email-change.store'), ['new_email' => 'first@example.com', 'password' => 'password']);
        $oldLink = $user->refresh()->emailChange?->signedUrl('new');

        $this->post(route('email-change.store'), ['new_email' => 'second@example.com', 'password' => 'password']);

        $this->assertDatabaseCount('email_changes', 1);
        $this->assertSame('second@example.com', $user->refresh()->emailChange?->new_email);
        $this->get((string) $oldLink)->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));
    }

    public function test_opening_a_link_only_shows_a_page()
    {
        $change = EmailChange::factory()->create();

        $this->get($change->signedUrl('new'))->assertInertia(fn (Assert $page) => $page
            ->component('auth/EmailChange')
            ->where('state', 'confirm-new')
            ->where('newEmail', $change->new_email)
            ->has('confirmUrl'));

        $this->get($change->signedUrl('current'))->assertInertia(fn (Assert $page) => $page
            ->where('state', 'review-current')
            ->has('approveUrl')
            ->has('denyUrl'));

        $change->refresh();
        $this->assertNull($change->new_confirmed_at);
        $this->assertNull($change->old_approved_at);
    }

    public function test_the_email_changes_once_both_addresses_agree_in_either_order()
    {
        foreach ([['new', 'confirm', 'current', 'approve'], ['current', 'approve', 'new', 'confirm']] as [$firstSide, $firstAction, $secondSide, $secondAction]) {
            $user = User::factory()->unverified()->create();
            $oldEmail = $user->email;
            $change = EmailChange::factory()->for($user)->create();

            $this->followingRedirects()->post($change->signedUrl($firstSide, $firstAction))
                ->assertInertia(fn (Assert $page) => $page->where('state', $firstSide === 'new' ? 'waiting-for-current' : 'waiting-for-new'));
            $this->assertSame($oldEmail, $user->refresh()->email);

            $this->followingRedirects()->post($change->signedUrl($secondSide, $secondAction))
                ->assertInertia(fn (Assert $page) => $page->where('state', 'completed'));

            $user->refresh();
            $this->assertSame($change->new_email, $user->email);
            $this->assertNotNull($user->email_verified_at);
            $this->assertNull($user->emailChange);
            $this->assertDatabaseHas('email_audits', [
                'user_id' => $user->id,
                'old_email' => $oldEmail,
                'new_email' => $change->new_email,
                'method' => EmailChangeMethod::SelfService->value,
                'changed_by' => $user->id,
            ]);
        }
    }

    public function test_the_current_address_can_stop_the_change()
    {
        $user = User::factory()->create();
        $oldEmail = $user->email;
        $change = EmailChange::factory()->for($user)->newConfirmed()->create();

        $this->followingRedirects()->post($change->signedUrl('current', 'deny'))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'denied'));

        $this->assertSame($oldEmail, $user->refresh()->email);
        $this->assertDatabaseCount('email_changes', 0);
        $this->assertDatabaseCount('email_audits', 0);
    }

    public function test_tampered_and_expired_links_are_refused()
    {
        $change = EmailChange::factory()->create();

        $this->get($change->signedUrl('new').'x')->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));
        $this->followingRedirects()->post(str_replace("/{$change->id}/", '/'.($change->id + 1).'/', $change->signedUrl('new', 'confirm')))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));

        $this->travel(EmailChange::LIFETIME_HOURS + 1)->hours();

        $this->get($change->signedUrl('new'))->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));
        $this->assertNull($change->refresh()->new_confirmed_at);
    }

    public function test_the_change_stops_if_someone_else_took_the_address_first()
    {
        $user = User::factory()->create();
        $oldEmail = $user->email;
        $change = EmailChange::factory()->for($user)->newConfirmed()->create();
        User::factory()->create(['email' => $change->new_email]);

        $this->followingRedirects()->post($change->signedUrl('current', 'approve'))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'taken'));

        $this->assertSame($oldEmail, $user->refresh()->email);
        $this->assertDatabaseCount('email_changes', 0);
    }

    public function test_users_can_cancel_their_pending_change()
    {
        $change = EmailChange::factory()->create();

        $this->actingAs($change->user)
            ->delete(route('email-change.destroy'))
            ->assertRedirect(route('profile.edit'));

        $this->assertDatabaseCount('email_changes', 0);
    }

    public function test_the_profile_page_shows_a_pending_change()
    {
        $change = EmailChange::factory()->newConfirmed()->create(['new_email' => 'next@example.com']);

        $this->actingAs($change->user)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingEmailChange.new_email', 'next@example.com')
                ->where('pendingEmailChange.new_confirmed', true)
                ->where('pendingEmailChange.old_approved', false));
    }

    public function test_the_profile_form_cannot_change_the_email()
    {
        $user = User::factory()->teacher()->create(['email' => 'old@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => 'sneaky@example.com',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame('old@example.com', $user->refresh()->email);
    }

    public function test_expired_requests_are_pruned()
    {
        $expired = EmailChange::factory()->expired()->create();
        $fresh = EmailChange::factory()->create();

        $this->artisan('model:prune', ['--model' => [EmailChange::class]])->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelExists($fresh);
    }
}
