<?php

namespace Tests\Feature\Registrar;

use App\Enums\LearnerStatus;
use App\Enums\UserRole;
use App\Models\College;
use App\Models\Learner;
use App\Models\Program;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use App\Notifications\AccountSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LearnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->actingAs(User::factory()->registrar()->create());
    }

    /**
     * @return array<string, mixed>
     */
    private function learner(Program $program, array $override = []): array
    {
        return [
            'first_name' => 'Juan', 'middle_name' => 'Pablo', 'last_name' => 'Dela Cruz', 'suffix' => null,
            'email' => 'juan@example.com', 'student_number' => 'M2026-04821',
            'program_id' => $program->id, 'year_level' => 1, 'status' => 'active',
            ...$override,
        ];
    }

    public function test_admitting_a_learner_makes_the_account_and_record_in_the_programs_college()
    {
        $program = Program::factory()->create();

        $this->post(route('registrar.learners.store'), $this->learner($program))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('setupLink.email', 'juan@example.com');

        $account = User::where('email', 'juan@example.com')->sole();
        $this->assertSame(UserRole::Learner, $account->role);
        $this->assertSame($program->college_id, $account->college_id);
        $this->assertNotNull($account->email_verified_at);
        $this->assertSame('M2026-04821', $account->learner?->student_number);
        Notification::assertSentTo($account, AccountSetup::class);
    }

    public function test_student_numbers_and_emails_are_unique()
    {
        $program = Program::factory()->create();
        Learner::factory()->create(['student_number' => 'M2026-04821']);
        User::factory()->create(['email' => 'juan@example.com']);

        $this->post(route('registrar.learners.store'), $this->learner($program))
            ->assertSessionHasErrors(['student_number', 'email']);
    }

    public function test_learners_can_be_searched_and_filtered()
    {
        $science = College::factory()->create();
        $bscs = Program::factory()->for($science)->create();
        Learner::factory()->for($bscs)->create(['year_level' => 2, 'student_number' => 'M2025-11111']);
        Learner::factory()->for($bscs)->create(['year_level' => 1]);
        Learner::factory()->create(['status' => LearnerStatus::Graduated]);

        $this->get(route('registrar.learners.index', ['college' => $science->id]))
            ->assertInertia(fn (Assert $page) => $page->component('registrar/learners/Index')->has('learners.data', 2));
        $this->get(route('registrar.learners.index', ['program' => $bscs->id, 'year' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('learners.data', 1)->where('learners.data.0.student_number', 'M2025-11111'));
        $this->get(route('registrar.learners.index', ['status' => 'graduated']))
            ->assertInertia(fn (Assert $page) => $page->has('learners.data', 1));
        $this->get(route('registrar.learners.index', ['search' => '11111']))
            ->assertInertia(fn (Assert $page) => $page->has('learners.data', 1));
    }

    public function test_the_registrar_edits_the_record_and_name_but_not_the_email()
    {
        $learner = Learner::factory()->create();
        $email = $learner->user->email;
        $shift = Program::factory()->create();

        $this->put(route('registrar.learners.update', $learner), [
            ...$this->learner($shift, ['last_name' => 'Santos', 'student_number' => 'M2026-00001', 'year_level' => 2, 'status' => 'inactive']),
            'email' => 'changed@example.com',
        ])->assertSessionHasNoErrors();

        $learner->refresh();
        $this->assertSame('Santos', $learner->user->last_name);
        $this->assertSame($email, $learner->user->email);
        $this->assertSame($shift->id, $learner->program_id);
        $this->assertSame($shift->college_id, $learner->user->college_id);
        $this->assertSame(LearnerStatus::Inactive, $learner->status);
    }

    public function test_a_program_shift_waits_until_the_learners_terms_are_over()
    {
        $this->travelTo('2026-10-19');
        $learner = Learner::factory()->create();
        $running = Term::factory()->create(['starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        TermRegistration::factory()->for($learner)->for($running)->create();
        $shift = Program::factory()->create();
        $data = $this->learner($shift, ['student_number' => $learner->student_number, 'year_level' => $learner->year_level]);

        $this->put(route('registrar.learners.update', $learner), $data)
            ->assertSessionHasErrors(['program_id' => 'The learner is registered for a term that has not ended. Change the program after it ends.']);

        // Same program: other edits still go through.
        $this->put(route('registrar.learners.update', $learner), [...$data, 'program_id' => $learner->program_id, 'last_name' => 'Santos'])
            ->assertSessionHasNoErrors();

        $this->travelTo('2026-12-19');
        $this->put(route('registrar.learners.update', $learner), $data)->assertSessionHasNoErrors();
        $this->assertSame($shift->id, $learner->refresh()->program_id);
    }

    public function test_a_learner_can_be_stopped_from_logging_in_and_let_back()
    {
        $learner = Learner::factory()->create();

        $this->put(route('registrar.learners.status', $learner), ['active' => false]);
        $this->assertFalse($learner->user->refresh()->is_active);

        $this->post(route('registrar.learners.setup-link', $learner))->assertInertiaFlash('toast.message', 'Reactivate the account first.');

        $this->put(route('registrar.learners.status', $learner), ['active' => true]);
        $this->assertTrue($learner->user->refresh()->is_active);
        $this->post(route('registrar.learners.setup-link', $learner))->assertInertiaFlash('setupLink.email', $learner->user->email);
    }

    public function test_only_the_registrar_admits_learners()
    {
        $program = Program::factory()->create();

        foreach (['admin', 'dean', 'teacher', 'learner'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create());
            $this->get(route('registrar.learners.index'))->assertForbidden();
            $this->post(route('registrar.learners.store'), $this->learner($program))->assertForbidden();
        }

        $this->assertDatabaseMissing('users', ['email' => 'juan@example.com']);
    }
}
