<?php

namespace Tests\Feature\Admin;

use App\Enums\DutyStatus;
use App\Enums\UserRole;
use App\Models\College;
use App\Models\Learner;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\AccountSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = User::factory()->admin()->create(['last_name' => 'Admin']);
        $this->actingAs($this->admin);
    }

    /**
     * @return array<string, mixed>
     */
    private function teacher(College $college, array $override = []): array
    {
        return [
            'role' => 'teacher', 'college_id' => $college->id,
            'first_name' => 'Teresa', 'middle_name' => null, 'last_name' => 'Garcia', 'suffix' => null,
            'email' => 'teresa@example.com', 'employee_number' => 'URS-P-00001', 'duty_status' => 'active',
            ...$override,
        ];
    }

    public function test_accounts_can_be_searched_and_filtered()
    {
        $college = College::factory()->create();
        Teacher::factory()->forCollege($college)->create(['employee_number' => 'URS-P-12345']);
        Learner::factory()->create();
        User::factory()->registrar()->create(['is_active' => false]);

        $this->get(route('admin.accounts.index', ['role' => 'teacher']))
            ->assertInertia(fn (Assert $page) => $page->component('admin/accounts/Index')->has('accounts.data', 1));
        $this->get(route('admin.accounts.index', ['search' => '12345']))
            ->assertInertia(fn (Assert $page) => $page->has('accounts.data', 1)->where('accounts.data.0.teacher.employee_number', 'URS-P-12345'));
        $this->get(route('admin.accounts.index', ['status' => 'inactive']))
            ->assertInertia(fn (Assert $page) => $page->has('accounts.data', 1)->where('accounts.data.0.role', 'registrar'));
        $this->get(route('admin.accounts.index', ['college' => $college->id]))
            ->assertInertia(fn (Assert $page) => $page->has('accounts.data', 1));
    }

    public function test_a_teacher_account_is_made_with_its_profile_and_a_setup_link()
    {
        $college = College::factory()->create();

        $this->post(route('admin.accounts.store'), $this->teacher($college))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('setupLink.email', 'teresa@example.com');

        $account = User::where('email', 'teresa@example.com')->sole();
        $this->assertSame(UserRole::Teacher, $account->role);
        $this->assertSame($college->id, $account->college_id);
        $this->assertNotNull($account->email_verified_at);
        $this->assertSame('URS-P-00001', $account->teacher?->employee_number);
        Notification::assertSentTo($account, AccountSetup::class);
    }

    public function test_deans_and_teachers_need_a_college_and_campus_roles_never_get_one()
    {
        $college = College::factory()->create();

        $this->post(route('admin.accounts.store'), [...$this->teacher($college), 'role' => 'dean', 'college_id' => null])
            ->assertSessionHasErrors(['college_id' => 'Deans and teachers belong to a college.']);

        $this->post(route('admin.accounts.store'), [...$this->teacher($college), 'role' => 'registrar', 'email' => 'registrar@example.com'])
            ->assertSessionHasNoErrors();
        $registrar = User::where('email', 'registrar@example.com')->sole();
        $this->assertNull($registrar->college_id);
        $this->assertNull($registrar->teacher);
    }

    public function test_learners_are_not_made_here()
    {
        $this->post(route('admin.accounts.store'), [...$this->teacher(College::factory()->create()), 'role' => 'learner'])
            ->assertSessionHasErrors(['role' => 'Learners are admitted by the registrar.']);

        $this->assertDatabaseMissing('users', ['email' => 'teresa@example.com']);
    }

    public function test_editing_changes_names_and_teacher_details_but_never_role_college_or_email()
    {
        $teacher = Teacher::factory()->create(['employee_number' => 'URS-P-00001']);
        $account = $teacher->user;
        $other = College::factory()->create();

        $this->put(route('admin.accounts.update', $account), [
            'first_name' => 'Teresa', 'last_name' => 'Garcia-Reyes',
            'employee_number' => 'URS-P-00002', 'duty_status' => 'on_leave',
            'role' => 'admin', 'college_id' => $other->id, 'email' => 'new@example.com',
        ])->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame('Garcia-Reyes', $account->last_name);
        $this->assertSame(UserRole::Teacher, $account->role);
        $this->assertNotSame($other->id, $account->college_id);
        $this->assertNotSame('new@example.com', $account->email);
        $this->assertSame('URS-P-00002', $account->teacher?->employee_number);
        $this->assertSame(DutyStatus::OnLeave, $account->teacher->duty_status);
    }

    public function test_learner_accounts_are_left_to_the_registrar()
    {
        $learner = Learner::factory()->create();

        $this->put(route('admin.accounts.update', $learner->user), ['first_name' => 'X', 'last_name' => 'Y'])->assertForbidden();
        $this->put(route('admin.accounts.status', $learner->user), ['active' => false])->assertForbidden();
        $this->assertTrue($learner->user->refresh()->is_active);
    }

    public function test_staff_can_be_deactivated_and_reactivated()
    {
        $teacher = Teacher::factory()->create()->user;

        $this->put(route('admin.accounts.status', $teacher), ['active' => false])->assertInertiaFlash('toast.type', 'success');
        $this->assertFalse($teacher->refresh()->is_active);

        $this->post(route('admin.accounts.setup-link', $teacher))->assertInertiaFlash('toast.message', 'Reactivate the account first.');

        $this->put(route('admin.accounts.status', $teacher), ['active' => true]);
        $this->assertTrue($teacher->refresh()->is_active);
    }

    public function test_admins_have_no_controls_over_admin_accounts()
    {
        $this->post(route('admin.accounts.store'), [...$this->teacher(College::factory()->create()), 'role' => 'admin', 'email' => 'peer@example.com'])
            ->assertSessionHasErrors(['role' => 'Admin accounts are made on the server.']);
        $this->assertDatabaseMissing('users', ['email' => 'peer@example.com']);

        foreach ([User::factory()->admin()->create(), $this->admin] as $admin) {
            $this->put(route('admin.accounts.update', $admin), ['first_name' => 'X', 'last_name' => 'Y'])->assertForbidden();
            $this->put(route('admin.accounts.status', $admin), ['active' => false])->assertForbidden();
            $this->post(route('admin.accounts.setup-link', $admin))->assertForbidden();
            $this->put(route('admin.users.email.update', $admin), ['email' => 'taken-over@example.com', 'reason' => 'x'])->assertForbidden();

            $admin->refresh();
            $this->assertTrue($admin->is_active);
            $this->assertNotSame('Y', $admin->last_name);
            $this->assertNotSame('taken-over@example.com', $admin->email);
        }

        Notification::assertNothingSent();
    }

    public function test_a_new_setup_link_can_be_made_for_anyone_active()
    {
        $learner = Learner::factory()->create();

        $this->post(route('admin.accounts.setup-link', $learner->user))
            ->assertInertiaFlash('setupLink.name', $learner->user->full_name);

        Notification::assertSentTo($learner->user, AccountSetup::class);
    }

    public function test_only_the_admin_manages_accounts()
    {
        foreach (['registrar', 'dean', 'teacher', 'learner'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create());
            $this->get(route('admin.accounts.index'))->assertForbidden();
            $this->post(route('admin.accounts.store'), $this->teacher(College::factory()->create()))->assertForbidden();
        }

        $this->assertDatabaseMissing('users', ['email' => 'teresa@example.com']);
    }
}
