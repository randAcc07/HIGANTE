<?php

namespace Tests\Feature\Admin;

use App\Enums\EmailChangeMethod;
use App\Models\EmailChange;
use App\Models\User;
use App\Notifications\EmailChangedByAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_the_admin_can_set_a_users_email_directly()
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->learner()->unverified()->create(['email' => 'lost@example.com']);
        EmailChange::factory()->for($user)->create();

        $this->actingAs($admin)
            ->put(route('admin.users.email.update', $user), ['email' => 'found@example.com', 'reason' => 'Lost access to the old inbox.'])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('found@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->emailChange);
        $this->assertDatabaseHas('email_audits', [
            'user_id' => $user->id,
            'old_email' => 'lost@example.com',
            'new_email' => 'found@example.com',
            'method' => EmailChangeMethod::Admin->value,
            'changed_by' => $admin->id,
            'reason' => 'Lost access to the old inbox.',
        ]);

        Notification::assertSentOnDemand(EmailChangedByAdmin::class, fn ($notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'lost@example.com');
    }

    public function test_a_reason_and_an_unused_address_are_required()
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->teacher()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($admin)
            ->put(route('admin.users.email.update', $user), ['email' => 'new@example.com'])
            ->assertSessionHasErrors('reason');

        $this->put(route('admin.users.email.update', $user), ['email' => 'taken@example.com', 'reason' => 'x'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('email_audits', 0);
    }

    public function test_only_the_admin_can_override_an_email()
    {
        $user = User::factory()->teacher()->create(['email' => 'keep@example.com']);

        foreach (['registrar', 'dean', 'teacher', 'learner'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())
                ->put(route('admin.users.email.update', $user), ['email' => 'new@example.com', 'reason' => 'x'])
                ->assertForbidden();
        }

        $this->assertSame('keep@example.com', $user->refresh()->email);
    }
}
