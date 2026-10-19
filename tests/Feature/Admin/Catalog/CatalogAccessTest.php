<?php

namespace Tests\Feature\Admin\Catalog;

use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_admin_can_open_or_change_the_catalog()
    {
        $college = College::factory()->create();

        foreach (['registrar', 'dean', 'teacher', 'learner'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create());

            foreach (['admin.colleges.index', 'admin.subjects.index', 'admin.rooms.index'] as $route) {
                $this->get(route($route))->assertForbidden();
            }

            $this->get(route('admin.colleges.show', $college))->assertForbidden();
            $this->post(route('admin.colleges.store'), ['code' => 'X', 'name' => 'X'])->assertForbidden();
            $this->post(route('admin.rooms.store'), ['code' => 'X', 'room_type' => 'lecture', 'is_active' => true])->assertForbidden();
        }

        // The role factories create colleges of their own, so look for the
        // submitted code rather than counting rows.
        $this->assertDatabaseMissing('colleges', ['code' => 'X']);
        $this->assertDatabaseMissing('rooms', ['code' => 'X']);
    }

    public function test_guests_are_sent_to_log_in()
    {
        $this->get(route('admin.colleges.index'))->assertRedirect(route('login'));
    }

    public function test_the_admin_menu_lists_the_catalog()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.colleges.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/colleges/Index'));
    }
}
