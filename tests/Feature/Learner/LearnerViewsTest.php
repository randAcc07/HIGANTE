<?php

namespace Tests\Feature\Learner;

use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExcuseStatus;
use App\Enums\Semester;
use App\Models\AbsenceExcuse;
use App\Models\AttendanceRecord;
use App\Models\CalendarEvent;
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
use App\Models\TermRegistration;
use App\Models\User;
use App\Notifications\AttendanceNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A learner registered in a term in session (today is Wed Sep 30) with a
 * Monday CS 101 class, and last school year's term on record.
 */
class LearnerViewsTest extends TestCase
{
    use RefreshDatabase;

    private Learner $learner;

    private Term $term;

    private Schedule $class;

    private Enrollment $enrollment;

    private College $college;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-30 10:00');
        $this->college = College::factory()->create();
        $program = Program::factory()->for($this->college)->create(['code' => 'BSCS']);
        $this->term = Term::factory()->create(['school_year' => '2026-2027', 'semester' => Semester::First, 'starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        $section = Section::factory()->for($this->term)->for($program)->create(['year_level' => 1, 'name' => '1-A']);
        $teacher = Teacher::factory()->forCollege($this->college)->create();
        $offering = SubjectOffering::factory()->for($section)->create(['subject_id' => Subject::factory()->for($this->college)->create(['code' => 'CS 101'])->id, 'teacher_id' => $teacher->id]);
        $this->class = Schedule::factory()->for($offering)->slot(1, '08:00', '09:30')->create(['teacher_id' => $teacher->id]);

        $this->learner = Learner::factory()->for($program)->create();
        TermRegistration::factory()->for($this->learner)->for($this->term)->create(['section_id' => $section->id]);
        $this->enrollment = Enrollment::factory()->for($offering)->create(['learner_id' => $this->learner->id]);
        $this->enrollment->forceFill(['created_at' => '2026-08-07'])->save();

        $this->actingAs($this->learner->user);
    }

    private function record(string $date, AttendanceStatus $status, array $override = []): AttendanceRecord
    {
        return AttendanceRecord::factory()->for($this->enrollment)->for($this->class)->create(['class_date' => $date, 'status' => $status, ...$override]);
    }

    public function test_the_learner_sees_their_classes_week_and_standing()
    {
        $this->record('2026-08-10', AttendanceStatus::Absent);
        $this->record('2026-08-17', AttendanceStatus::Late);
        $this->enrollment->forceFill(['warning_level' => 3])->save();

        $this->get(route('learner.classes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('learner/classes/Index')
                ->where('term.id', $this->term->id)
                ->where('registration.section', '1-A')
                ->has('classes', 1)
                ->where('classes.0.counts', ['absences' => 1, 'lates' => 1, 'excused' => 0, 'equivalent' => 1])
                ->where('classes.0.periods', ['held' => 8, 'total' => 19])
                ->where('classes.0.warning_level', 3)
                ->where('policy.drop_at_absences', 5)
                ->has('week', 1));
    }

    public function test_past_terms_stay_viewable()
    {
        $past = Term::factory()->create(['school_year' => '2025-2026', 'semester' => Semester::Second, 'starts_on' => '2026-01-12', 'ends_on' => '2026-05-22']);
        TermRegistration::factory()->for($this->learner)->for($past)->create();

        $this->get(route('learner.classes.index', ['term' => $past->id]))
            ->assertInertia(fn (Assert $page) => $page->where('term.id', $past->id)->has('terms', 2)->has('classes', 0));
    }

    public function test_a_class_lists_only_late_and_absent_days_and_whether_they_count()
    {
        $excuse = AbsenceExcuse::factory()->create(['learner_id' => $this->learner->id, 'status' => ExcuseStatus::Approved]);
        $this->record('2026-08-10', AttendanceStatus::Absent, ['absence_excuse_id' => $excuse->id]);
        $this->record('2026-08-17', AttendanceStatus::Late);
        $this->record('2026-08-24', AttendanceStatus::Present);
        $this->record('2026-08-31', AttendanceStatus::Absent);
        CalendarEvent::factory()->create(['college_id' => null, 'title' => 'Typhoon', 'starts_on' => '2026-08-31', 'ends_on' => '2026-08-31', 'suspends_classes' => true]);

        $this->get(route('learner.classes.show', $this->enrollment))
            ->assertInertia(fn (Assert $page) => $page
                ->component('learner/classes/Show')
                ->has('marks', 3)
                ->where('marks.0.date', '2026-08-31')
                ->where('marks.0.suspended', 'Typhoon')
                ->where('marks.2.excused', true)
                ->where('counts.equivalent', 0)
                ->missing('marks.0.recorded_by'));
    }

    public function test_a_learner_sees_only_their_own_classes()
    {
        $other = Enrollment::factory()->for($this->class->subjectOffering)->create();

        $this->get(route('learner.classes.show', $other))->assertForbidden();
        $this->actingAs(User::factory()->dean($this->college)->create())->get(route('learner.classes.index'))->assertForbidden();
    }

    public function test_the_dashboard_points_to_what_needs_attention()
    {
        $this->enrollment->forceFill(['status' => EnrollmentStatus::ForDropping, 'warning_level' => 4])->save();
        AbsenceExcuse::factory()->create(['learner_id' => $this->learner->id, 'status' => ExcuseStatus::Pending]);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('learner.attention', 1)
                ->where('learner.attention.0.status', 'for_dropping')
                ->where('learner.excuses.0.status', 'pending'));

        $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('learner', null));
    }

    public function test_everyone_has_a_notifications_page_of_their_own()
    {
        $this->learner->user->notify(AttendanceNotice::dropped($this->enrollment));
        $this->learner->user->notify(AttendanceNotice::reinstated($this->enrollment));
        $this->learner->user->notifications()->latest()->firstOrFail()->markAsRead();
        User::factory()->dean($this->college)->create()->notify(AttendanceNotice::learnerFlagged($this->enrollment, 5));

        $this->get(route('notifications.index'))
            ->assertInertia(fn (Assert $page) => $page->component('notifications/Index')->has('notifications.data', 2));
        $this->get(route('notifications.index', ['unread' => 1]))
            ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1)->where('unreadOnly', true));
    }
}
