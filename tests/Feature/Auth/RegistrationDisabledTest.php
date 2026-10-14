<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_does_not_exist()
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_nobody_can_register_by_posting_directly()
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    public function test_login_page_has_no_sign_up_link()
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('/register');
    }
}
