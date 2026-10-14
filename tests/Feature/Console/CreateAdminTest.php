<?php

namespace Tests\Feature\Console;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_verified_admin()
    {
        $this->artisan('app:create-admin', ['--name' => 'Campus Admin', '--email' => 'admin@example.com'])
            ->expectsQuestion('Password', 'a-strong-password')
            ->expectsQuestion('Confirm password', 'a-strong-password')
            ->assertSuccessful();

        $admin = User::where('email', 'admin@example.com')->sole();

        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertNull($admin->college_id);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('a-strong-password', $admin->password));
    }

    public function test_it_rejects_a_mismatched_password()
    {
        $this->artisan('app:create-admin', ['--name' => 'Campus Admin', '--email' => 'admin@example.com'])
            ->expectsQuestion('Password', 'a-strong-password')
            ->expectsQuestion('Confirm password', 'something-else')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_rejects_an_email_already_in_use()
    {
        User::factory()->create(['email' => 'admin@example.com']);

        $this->artisan('app:create-admin', ['--name' => 'Campus Admin', '--email' => 'admin@example.com'])
            ->expectsQuestion('Password', 'a-strong-password')
            ->expectsQuestion('Confirm password', 'a-strong-password')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }
}
