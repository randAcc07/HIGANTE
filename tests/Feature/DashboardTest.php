<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_the_dashboard_shows_the_users_role_and_college()
    {
        $college = College::factory()->create(['name' => 'College of Engineering']);

        $this->actingAs(User::factory()->dean($college)->create())
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('roleLabel', 'Dean')
                ->where('collegeName', 'College of Engineering'));

        $this->actingAs(User::factory()->registrar()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('roleLabel', 'Registrar')
                ->where('collegeName', null));
    }
}
