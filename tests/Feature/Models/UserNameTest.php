<?php

namespace Tests\Feature\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_name_uses_the_middle_initial_and_suffix()
    {
        $user = User::factory()->make([
            'first_name' => 'Juan', 'middle_name' => 'Pablo', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.',
        ]);

        $this->assertSame('Juan P. Dela Cruz Jr.', $user->full_name);
        $this->assertSame('Juan P. Dela Cruz Jr.', $user->getPasskeyDisplayName());
    }

    public function test_full_name_skips_missing_parts()
    {
        $user = User::factory()->make([
            'first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Santos', 'suffix' => null,
        ]);

        $this->assertSame('Maria Santos', $user->full_name);
    }

    public function test_full_name_is_sent_to_the_frontend()
    {
        $user = User::factory()->create(['first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Santos']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('auth.user.full_name', 'Maria Santos'));
    }
}
