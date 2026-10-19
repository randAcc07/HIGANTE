<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'role:admin,registrar'])
            ->get('/_test/admin-or-registrar', fn () => 'ok');
    }

    public function test_listed_roles_are_let_through()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/_test/admin-or-registrar')
            ->assertOk();

        $this->actingAs(User::factory()->registrar()->create())
            ->get('/_test/admin-or-registrar')
            ->assertOk();
    }

    public function test_other_roles_are_forbidden()
    {
        foreach (['dean', 'teacher', 'learner'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())
                ->get('/_test/admin-or-registrar')
                ->assertForbidden();
        }
    }

    public function test_guests_are_sent_to_log_in()
    {
        $this->get('/_test/admin-or-registrar')->assertRedirect(route('login'));
    }
}
