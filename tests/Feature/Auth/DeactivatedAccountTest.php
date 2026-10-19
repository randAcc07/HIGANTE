<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Tests\TestCase;

class DeactivatedAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_users_cannot_log_in_with_a_password()
    {
        $user = User::factory()->inactive()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => 'This account has been deactivated. Contact the school administrator.']);

        $this->assertGuest();
    }

    public function test_a_wrong_password_does_not_reveal_that_an_account_is_deactivated()
    {
        $user = User::factory()->inactive()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
    }

    public function test_active_users_can_still_log_in()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_deactivated_users_cannot_log_in_with_a_passkey()
    {
        $passkey = new Passkey;

        $passkey->setRelation('user', User::factory()->inactive()->create());
        $this->assertFalse(Passkeys::allowsLogin(new Request, $passkey));

        $passkey->setRelation('user', User::factory()->create());
        $this->assertTrue(Passkeys::allowsLogin(new Request, $passkey));
    }

    public function test_a_signed_in_user_who_is_deactivated_is_logged_out()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertGuest();
    }
}
