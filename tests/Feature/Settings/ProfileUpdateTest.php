<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_staff_can_update_their_name_and_email()
    {
        $user = User::factory()->teacher()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'first_name' => 'Juan',
                'middle_name' => 'Pablo',
                'last_name' => 'Dela Cruz',
                'suffix' => 'Jr.',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Juan P. Dela Cruz Jr.', $user->full_name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_learners_can_update_their_email_but_not_their_name()
    {
        $user = User::factory()->learner()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);

        $this
            ->actingAs($user)
            ->patch(route('profile.update'), ['email' => 'learner@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame('learner@example.com', $user->refresh()->email);

        $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'first_name' => 'Changed',
                'last_name' => 'Name',
                'email' => 'learner@example.com',
            ])
            ->assertSessionHasErrors(['first_name', 'last_name']);

        $user->refresh();
        $this->assertSame('Maria', $user->first_name);
        $this->assertSame('Santos', $user->last_name);
    }

    public function test_learners_see_their_name_as_read_only()
    {
        $this
            ->actingAs(User::factory()->learner()->create())
            ->get(route('profile.edit'))
            ->assertInertia(fn ($page) => $page->where('canEditName', false));

        $this
            ->actingAs(User::factory()->registrar()->create())
            ->get(route('profile.edit'))
            ->assertInertia(fn ($page) => $page->where('canEditName', true));
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_users_cannot_delete_their_own_account()
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertMethodNotAllowed();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh());
    }

    public function test_profile_page_has_no_delete_account_section()
    {
        $this
            ->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('delete-user-button');
    }
}
