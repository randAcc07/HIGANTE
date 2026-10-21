<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\AbsenceExcuse;
use App\Models\Batch;
use App\Models\CalendarEvent;
use App\Models\ClassCover;
use App\Models\College;
use App\Models\Enrollment;
use App\Models\Learner;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A term in session (Aug 10 – Dec 18, 2026; today is Wed Sep 16) with BSCS
 * 1-A: CS 101 on Mondays 8:00, and CS 102's labs for 1-AA on Tuesdays and
 * 1-AB on Thursdays. The learner is in 1-AA.
 */
class DashboardPanelsTest extends TestCase
{
    use RefreshDatabase;

    private College $college;

    private Term $term;

    private Section $section;

    private Teacher $teacher;

    private Schedule $lecture;

    private Learner $learner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-16 10:00');
        $this->college = College::factory()->create();
        $program = Program::factory()->for($this->college)->create(['code' => 'BSCS']);
        $this->term = Term::factory()->create(['starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        $this->section = Section::factory()->for($this->term)->for($program)->create(['year_level' => 1, 'name' => '1-A']);
        $this->teacher = Teacher::factory()->forCollege($this->college)->create();
        $offering = fn (string $code) => SubjectOffering::factory()->for($this->section)->create([
            'subject_id' => Subject::factory()->for($this->college)->create(['code' => $code])->id,
            'teacher_id' => $this->teacher->id,
        ]);
        $cs101 = $offering('CS 101');
        $cs102 = $offering('CS 102');
        $this->lecture = Schedule::factory()->for($cs101)->slot(1, '08:00', '09:30')->create(['teacher_id' => $this->teacher->id]);
        $aa = Batch::factory()->for($cs102)->create(['name' => '1-AA']);
        $ab = Batch::factory()->for($cs102)->create(['name' => '1-AB']);
        Schedule::factory()->for($cs102)->for($aa)->slot(2, '08:00', '11:00')->create(['teacher_id' => $this->teacher->id]);
        Schedule::factory()->for($cs102)->for($ab)->slot(4, '08:00', '11:00')->create(['teacher_id' => $this->teacher->id]);

        $this->learner = Learner::factory()->for($program)->create();
        Enrollment::factory()->for($cs101)->create(['learner_id' => $this->learner->id]);
        Enrollment::factory()->for($cs102)->create(['learner_id' => $this->learner->id, 'batch_id' => $aa->id]);
    }

    public function test_everyone_sees_the_current_term_beside_the_title()
    {
        $this->actingAs(User::factory()->registrar()->create())->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('term', ['label' => $this->term->label, 'phase' => 'in_session', 'week' => 6, 'weeks' => 19, 'starts_in' => null]));
    }

    public function test_the_teachers_week_has_dates_and_struck_out_suspended_classes()
    {
        CalendarEvent::factory()->create(['title' => 'Typhoon', 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-14']);

        $this->actingAs($this->teacher->user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('teacher.week.monday', '2026-09-14')
            ->where('teacher.week.previous', '2026-09-07')
            ->where('teacher.week.days.0', ['weekday' => 1, 'date' => '2026-09-14', 'events' => [['title' => 'Typhoon', 'suspends' => true]]])
            ->has('teacher.week.blocks', 3)
            ->where('teacher.week.blocks.0.date', '2026-09-14')
            ->where('teacher.week.blocks.0.cancelled', 'Typhoon')
            ->where('teacher.week.blocks.1.cancelled', null)
            ->where('dean', null)
            ->where('learner', null));

        // The first week has nothing before it.
        $this->get(route('dashboard', ['week' => '2026-08-12']))->assertInertia(fn (Assert $page) => $page
            ->where('teacher.week.monday', '2026-08-10')
            ->where('teacher.week.previous', null)
            ->where('teacher.week.next', '2026-08-17'));
    }

    public function test_a_substitute_sees_a_covered_class_only_in_the_weeks_they_cover()
    {
        $substitute = Teacher::factory()->forCollege($this->college)->create();
        ClassCover::factory()->create(['schedule_id' => $this->lecture->id, 'substitute_id' => $substitute->id, 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-18']);

        $this->actingAs($substitute->user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->has('teacher.week.blocks', 1)
            ->where('teacher.week.blocks.0.covering', true));
        $this->get(route('dashboard', ['week' => '2026-09-21']))
            ->assertInertia(fn (Assert $page) => $page->has('teacher.week.blocks', 0));

        $this->actingAs($this->teacher->user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('teacher.week.blocks.0.cover', $substitute->user->full_name));
    }

    public function test_the_learners_week_and_the_suspensions_that_catch_their_classes()
    {
        CalendarEvent::factory()->create(['title' => 'Foundation Day', 'starts_on' => '2026-09-21', 'ends_on' => '2026-09-21']);
        CalendarEvent::factory()->create(['title' => 'Senior job fair', 'starts_on' => '2026-09-22', 'ends_on' => '2026-09-22', 'year_levels' => [4]]);
        CalendarEvent::factory()->create(['title' => 'Far off', 'starts_on' => '2026-11-30', 'ends_on' => '2026-11-30']);
        CalendarEvent::factory()->create(['title' => 'Other college', 'starts_on' => '2026-09-21', 'ends_on' => '2026-09-21', 'college_id' => College::factory()->create()->id]);

        $this->actingAs($this->learner->user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            // CS 101 and their own batch's lab, not 1-AB's.
            ->has('learner.week.blocks', 2)
            ->where('learner.week.blocks.1.batch', '1-AA')
            ->has('learner.suspensions', 1)
            ->where('learner.suspensions.0.title', 'Foundation Day')
            ->where('learner.suspensions.0.classes', 1));
    }

    public function test_the_dean_sees_a_section_week_and_what_waits_on_them()
    {
        $dean = User::factory()->dean($this->college)->create();
        $other = Section::factory()->for($this->term)->for($this->section->program)->create(['year_level' => 2, 'name' => '2-A']);
        AbsenceExcuse::factory()->create(['learner_id' => $this->learner->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-01', 'created_at' => '2026-09-15 09:00']);
        Enrollment::where('learner_id', $this->learner->id)->first()?->forceFill(['status' => EnrollmentStatus::ForDropping, 'warning_level' => 4])->save();
        Enrollment::where('learner_id', $this->learner->id)->latest('id')->first()?->forceFill(['warning_level' => 1])->save();

        $this->actingAs($dean)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('dean.section', $this->section->id)
            ->has('dean.sections', 2)
            ->has('dean.week.blocks', 3)
            ->where('dean.excusesCount', 1)
            ->where('dean.excuses.0.days_late', 7)
            ->where('dean.atRiskCounts', ['flagged' => 1, 'warned' => 1])
            ->where('dean.atRisk.0.status', 'for_dropping')
            ->where('teacher', null));

        $this->get(route('dashboard', ['section' => $other->id]))
            ->assertInertia(fn (Assert $page) => $page->where('dean.section', $other->id)->has('dean.week.blocks', 0));
    }
}
