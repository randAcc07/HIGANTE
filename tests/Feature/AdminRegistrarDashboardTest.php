<?php

namespace Tests\Feature;

use App\Enums\LearnerStatus;
use App\Enums\Semester;
use App\Models\CalendarEvent;
use App\Models\College;
use App\Models\CurriculumSubject;
use App\Models\Learner;
use App\Models\Program;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Today is Wed Jul 1, 2026, between last school year's 2nd semester
 * (Jan 12 – May 22) and the coming 1st semester (Aug 10 – Dec 18).
 */
class AdminRegistrarDashboardTest extends TestCase
{
    use RefreshDatabase;

    private College $cos;

    private Program $bscs;

    private Term $last;

    private Term $next;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-01 10:00');
        $this->cos = College::factory()->create(['code' => 'COS']);
        $this->bscs = Program::factory()->for($this->cos)->create(['code' => 'BSCS']);
        $this->last = Term::factory()->create(['school_year' => '2025-2026', 'semester' => Semester::Second, 'starts_on' => '2026-01-12', 'ends_on' => '2026-05-22']);
        $this->next = Term::factory()->create(['school_year' => '2026-2027', 'semester' => Semester::First, 'starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
    }

    public function test_signing_in_is_recorded_without_touching_the_account()
    {
        $user = User::factory()->create();
        $user->forceFill(['updated_at' => '2026-06-01 08:00'])->saveQuietly();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $user->refresh();
        $this->assertSame('2026-07-01 10:00:00', $user->last_login_at?->toDateTimeString());
        $this->assertSame('2026-06-01 08:00:00', $user->updated_at?->toDateTimeString());
    }

    public function test_the_admin_sees_a_month_of_campus_events_linked_to_their_term()
    {
        $holiday = CalendarEvent::factory()->create(['title' => 'Founding Day', 'starts_on' => '2026-08-12', 'ends_on' => '2026-08-12']);
        CalendarEvent::factory()->create(['title' => 'COS week', 'starts_on' => '2026-08-31', 'ends_on' => '2026-09-02', 'college_id' => $this->cos->id, 'suspends_classes' => false]);
        CalendarEvent::factory()->create(['title' => 'In September', 'starts_on' => '2026-09-15', 'ends_on' => '2026-09-15']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard', ['month' => '2026-08']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('admin.month.first', '2026-08-01')
                ->where('admin.month.previous', '2026-07')
                ->where('admin.month.next', '2026-09')
                ->has('admin.month.events', 2)
                ->where('admin.month.events.0', ['id' => $holiday->id, 'title' => 'Founding Day', 'starts_on' => '2026-08-12', 'ends_on' => '2026-08-12', 'college' => null, 'suspends' => true, 'term_id' => $this->next->id])
                ->where('admin.month.events.1.college', 'COS')
                ->where('admin.month.marks', [['date' => '2026-08-10', 'label' => '1st Semester 2026-2027 starts']])
                ->where('registrar', null));

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('admin.month.first', '2026-07-01'));
    }

    public function test_the_admin_sees_how_ready_each_college_is_for_the_next_term()
    {
        $empty = College::factory()->create(['code' => 'COE']);
        Program::factory()->for($empty)->create();
        CurriculumSubject::factory()->for($this->bscs)->create();
        $section = Section::factory()->for($this->next)->for($this->bscs)->create();
        SubjectOffering::factory()->for($section)->create(['teacher_id' => null]);
        SubjectOffering::factory()->for($section)->create();

        $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('admin.readiness.term.id', $this->next->id)
            ->where('admin.readiness.colleges.0', ['id' => $empty->id, 'code' => 'COE', 'name' => $empty->name, 'programs' => 1, 'without_curriculum' => 1, 'sections' => 0, 'subjects' => 0, 'without_teacher' => 0, 'unscheduled' => 0])
            ->where('admin.readiness.colleges.1.sections', 1)
            ->where('admin.readiness.colleges.1.without_curriculum', 0)
            ->where('admin.readiness.colleges.1.subjects', 2)
            ->where('admin.readiness.colleges.1.without_teacher', 1)
            ->where('admin.readiness.colleges.1.unscheduled', 2));
    }

    public function test_the_admin_sees_staff_who_never_signed_in()
    {
        $waiting = Teacher::factory()->forCollege($this->cos)->create()->user;
        User::factory()->dean($this->cos)->create(['last_login_at' => now()]);
        User::factory()->registrar()->create(['is_active' => false]);
        Learner::factory()->for($this->bscs)->create(); // the registrar's

        $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('admin.awaitingCount', 1)
            ->where('admin.awaiting.0.id', $waiting->id)
            ->where('admin.awaiting.0.role', 'Teacher'));
    }

    public function test_the_registrar_sees_registration_progress_and_what_waits()
    {
        $source = function (int $year, ?string $status = null): Learner {
            $learner = Learner::factory()->for($this->bscs)->create(['year_level' => $year, ...($status ? ['status' => $status] : [])]);
            $learner->forceFill(['created_at' => '2026-01-05'])->save();
            $learner->user->forceFill(['last_login_at' => '2026-02-01'])->save();
            TermRegistration::factory()->for($this->last)->create(['learner_id' => $learner->id, 'year_level' => $year, 'section_id' => null]);

            return $learner;
        };
        $registered = $source(1);
        $source(1);
        $source(1);
        $source(3);
        $source(1, LearnerStatus::Graduated->value);
        TermRegistration::factory()->for($this->next)->create(['learner_id' => $registered->id, 'year_level' => 2, 'section_id' => null]);
        $new = Learner::factory()->for($this->bscs)->create();

        $this->actingAs(User::factory()->registrar()->create())->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('registrar.term', ['id' => $this->next->id, 'label' => $this->next->label, 'open' => true])
            ->where('registrar.progress', [
                ['program_id' => $this->bscs->id, 'college' => 'COS', 'program' => 'BSCS', 'year_level' => 2, 'registered' => 1, 'waiting' => 2],
                ['program_id' => $this->bscs->id, 'college' => 'COS', 'program' => 'BSCS', 'year_level' => 4, 'registered' => 0, 'waiting' => 1],
            ])
            ->where('registrar.unsectionedCount', 1)
            ->where('registrar.recentCount', 1)
            ->where('registrar.recent.0.id', $new->id)
            ->where('registrar.awaitingCount', 1)
            ->where('registrar.awaiting.0.learner_id', $new->id)
            ->where('admin', null));
    }
}
