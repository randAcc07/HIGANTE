<?php

namespace Tests\Feature\Attendance;

use App\Attendance\ClassDays;
use App\Attendance\Standing;
use App\Enums\DropReason;
use App\Enums\EnrollmentStatus;
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
use App\Models\User;
use App\Notifications\AttendanceNotice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A term in session since Mon Aug 10 (today is Wed Sep 30, so the Monday
 * 8:00 class has met six times so far: Aug 10 – Sep 14 and Sep 21, 28) under
 * the handbook: 3 lates = 1 absence, warned at 3 and 4, flagged at 5.
 */
class StandingTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAYS = ['2026-08-10', '2026-08-17', '2026-08-24', '2026-08-31', '2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28'];

    private College $college;

    private Term $term;

    private Section $section;

    private Schedule $class;

    private Teacher $teacher;

    private User $dean;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-30 10:00');
        $this->college = College::factory()->create();
        $program = Program::factory()->for($this->college)->create(['code' => 'BSCS']);
        $this->term = Term::factory()->create(['starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        $this->section = Section::factory()->for($this->term)->for($program)->create(['year_level' => 1, 'name' => '1-A']);
        $this->teacher = Teacher::factory()->forCollege($this->college)->create();
        $offering = SubjectOffering::factory()->for($this->section)->create([
            'subject_id' => Subject::factory()->for($this->college)->create(['code' => 'CS 101'])->id,
            'teacher_id' => $this->teacher->id,
        ]);
        $this->class = Schedule::factory()->for($offering)->slot(1, '08:00', '09:30')->create(['teacher_id' => $this->teacher->id]);
        $this->dean = User::factory()->dean($this->college)->create();

        $learner = Learner::factory()->for($program)->create();
        $this->enrollment = Enrollment::factory()->for($offering)->create(['learner_id' => $learner->id]);
        $this->enrollment->forceFill(['created_at' => '2026-08-07 09:00'])->save();

        $this->actingAs($this->teacher->user);
    }

    /**
     * Mark the learner on the nth Monday through the class list.
     */
    private function mark(int $monday, string $status): void
    {
        $this->put(route('attendance.update', ['schedule' => $this->class, 'date' => self::MONDAYS[$monday]]), [
            'marks' => [['enrollment_id' => $this->enrollment->id, 'status' => $status]],
        ])->assertSessionHasNoErrors();
    }

    /**
     * @return list<string>
     */
    private function notices(User $user): array
    {
        return $user->notifications()->oldest()->get()->map(fn ($notification): string => $notification->data['kind'])->all();
    }

    private function learner(): User
    {
        return $this->enrollment->learner->user;
    }

    public function test_warnings_come_once_at_each_warning_count()
    {
        $this->mark(0, 'absent');
        $this->mark(1, 'late');
        $this->mark(2, 'late');
        $this->assertSame(0, $this->enrollment->refresh()->warning_level);

        // 1 absence + 3 lates = 2; one more absence makes 3.
        $this->mark(3, 'late');
        $this->mark(4, 'absent');
        $this->assertSame(3, $this->enrollment->refresh()->warning_level);
        $this->assertSame([AttendanceNotice::WARNING], $this->notices($this->learner()));

        $this->mark(5, 'absent');
        $this->assertSame(4, $this->enrollment->refresh()->warning_level);
        $this->assertSame([AttendanceNotice::WARNING, AttendanceNotice::WARNING], $this->notices($this->learner()));
        $this->assertSame(EnrollmentStatus::Enrolled, $this->enrollment->status);
    }

    public function test_reaching_the_drop_count_flags_for_the_dean_and_corrections_lift_it()
    {
        foreach (range(0, 4) as $monday) {
            $this->mark($monday, 'absent');
        }

        $this->enrollment->refresh();
        $this->assertSame(EnrollmentStatus::ForDropping, $this->enrollment->status);
        $this->assertNotNull($this->enrollment->flagged_for_drop_at);
        $this->assertContains(AttendanceNotice::FLAGGED, $this->notices($this->learner()));
        $this->assertSame([AttendanceNotice::LEARNER_FLAGGED], $this->notices($this->dean));

        // The teacher corrects one absence: back under the drop count.
        $this->mark(4, 'present');
        $this->enrollment->refresh();
        $this->assertSame(EnrollmentStatus::Enrolled, $this->enrollment->status);
        $this->assertNull($this->enrollment->flagged_for_drop_at);
        $this->assertSame(4, $this->enrollment->warning_level);

        // Reaching it again flags (and tells) again.
        $this->mark(5, 'absent');
        $this->assertSame(EnrollmentStatus::ForDropping, $this->enrollment->refresh()->status);
        $this->assertCount(2, array_keys($this->notices($this->dean)));
    }

    public function test_excused_absences_and_suspended_days_do_not_count()
    {
        foreach (range(0, 2) as $monday) {
            $this->mark($monday, 'absent');
        }

        $excuse = AbsenceExcuse::factory()->create(['learner_id' => $this->enrollment->learner_id]);
        AttendanceRecord::where('class_date', self::MONDAYS[0])->update(['absence_excuse_id' => $excuse->id]);
        $standing = app(Standing::class);
        $this->assertSame(['absences' => 2, 'lates' => 0, 'excused' => 1, 'equivalent' => 2], $standing->counts($this->enrollment));

        // A suspension entered afterwards: that day's mark stays but stops counting.
        $this->actingAs(User::factory()->admin()->create());
        $this->post(route('calendar.events.store'), [
            'title' => 'Typhoon', 'starts_on' => self::MONDAYS[1], 'ends_on' => self::MONDAYS[1], 'suspends_classes' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, app(Standing::class)->counts($this->enrollment)['equivalent']);
        $this->assertSame(3, AttendanceRecord::count());

        $this->actingAs($this->teacher->user);
        $this->get(route('attendance.show', ['schedule' => $this->class, 'date' => self::MONDAYS[1]]))
            ->assertInertia(fn (Assert $page) => $page->where('suspendedAfter', true)->where('canEdit', false)->has('learners', 1));

        // Lifting the suspension counts it again.
        $this->actingAs(User::factory()->admin()->create());
        $this->delete(route('calendar.events.destroy', CalendarEvent::sole()))->assertSessionHasNoErrors();
        $this->assertSame(2, app(Standing::class)->counts($this->enrollment)['equivalent']);
    }

    public function test_a_partial_day_suspension_catches_classes_starting_in_its_window()
    {
        $afternoon = Schedule::factory()->for($this->class->subjectOffering)->slot(1, '13:00', '14:30')->create(['teacher_id' => $this->teacher->id]);
        $lab = Schedule::factory()->for($this->class->subjectOffering)->slot(1, '12:00', '15:00')->create(['teacher_id' => Teacher::factory()->forCollege($this->college)->create()->id]);
        CalendarEvent::factory()->create(['college_id' => $this->college->id, 'title' => 'Memo 42', 'starts_on' => self::MONDAYS[6], 'ends_on' => self::MONDAYS[6], 'suspends_classes' => true, 'suspends_from' => '13:00']);
        $days = new ClassDays;
        $monday = CarbonImmutable::parse(self::MONDAYS[6]);

        $this->assertNull($days->refusal($this->class, $monday));
        $this->assertNull($days->refusal($lab, $monday), 'A lab that began at noon is held.');
        $this->assertSame('Classes are suspended on Sep 21 (Memo 42).', $days->refusal($afternoon, $monday));
        $this->assertNotContains(self::MONDAYS[6], array_map(fn ($day) => $day->toDateString(), $days->pastDates($afternoon)));

        // Periods: the morning class met 8 times, the afternoon one 7.
        $this->assertSame(['held' => 8 + 7 + 8, 'total' => 19 + 18 + 19], app(Standing::class)->periods($this->enrollment));
    }

    public function test_the_dean_confirms_dismisses_and_reinstates()
    {
        foreach (range(0, 4) as $monday) {
            $this->mark($monday, 'absent');
        }

        $this->actingAs($this->dean);
        $this->get(route('dean.standing.index', ['status' => 'flagged']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('dean/standing/Index')
                ->has('enrollments.data', 1)
                ->where('enrollments.data.0.counts.equivalent', 5)
                ->where('counts', ['flagged' => 1, 'warned' => 0, 'dropped' => 0]));

        // Dismissed: held until one more absence.
        $this->post(route('dean.standing.dismiss', $this->enrollment))->assertSessionHasNoErrors();
        $this->enrollment->refresh();
        $this->assertSame(EnrollmentStatus::Enrolled, $this->enrollment->status);
        $this->assertSame(5, $this->enrollment->drop_dismissed_absences);
        $this->actingAs($this->teacher->user);
        $this->mark(5, 'absent');
        $this->assertSame(EnrollmentStatus::ForDropping, $this->enrollment->refresh()->status);

        // Confirmed: dropped for absences, told.
        $this->actingAs($this->dean);
        $this->post(route('dean.standing.drop', $this->enrollment))->assertSessionHasNoErrors();
        $this->enrollment->refresh();
        $this->assertSame(EnrollmentStatus::Dropped, $this->enrollment->status);
        $this->assertSame(DropReason::Absences, $this->enrollment->drop_reason);
        $this->assertContains(AttendanceNotice::DROPPED, $this->notices($this->learner()));
        $this->post(route('dean.standing.dismiss', $this->enrollment))->assertStatus(409);

        // Reinstated: back in, held at today's count.
        $this->post(route('dean.standing.reinstate', $this->enrollment))->assertSessionHasNoErrors();
        $this->enrollment->refresh();
        $this->assertSame(EnrollmentStatus::Enrolled, $this->enrollment->status);
        $this->assertSame($this->dean->id, $this->enrollment->reinstated_by);
        $this->assertNull($this->enrollment->drop_reason);
        $this->assertContains(AttendanceNotice::REINSTATED, $this->notices($this->learner()));
    }

    public function test_only_absence_drops_are_reinstated_and_only_by_the_colleges_dean_while_open()
    {
        $this->enrollment->forceFill(['status' => EnrollmentStatus::Dropped, 'drop_reason' => DropReason::Withdrawn, 'drop_confirmed_at' => now()])->save();
        $this->actingAs($this->dean);
        $this->post(route('dean.standing.reinstate', $this->enrollment))->assertForbidden();

        $this->enrollment->forceFill(['status' => EnrollmentStatus::ForDropping, 'drop_reason' => null])->save();
        $this->actingAs(User::factory()->dean(College::factory()->create())->create());
        $this->post(route('dean.standing.drop', $this->enrollment))->assertForbidden();

        $this->actingAs($this->teacher->user);
        $this->post(route('dean.standing.drop', $this->enrollment))->assertForbidden();

        $this->term->forceFill(['closed_at' => now()])->save();
        $this->actingAs($this->dean);
        $this->post(route('dean.standing.drop', $this->enrollment))->assertForbidden();
    }

    public function test_teachers_see_standing_on_their_class_list()
    {
        $this->enrollment->forceFill(['warning_level' => 4])->save();

        $this->get(route('attendance.show', ['schedule' => $this->class, 'date' => self::MONDAYS[7]]))
            ->assertInertia(fn (Assert $page) => $page->where('learners.0.warning_level', 4)->where('learners.0.standing', null));
    }

    public function test_the_bell_shows_and_clears_unread_notices()
    {
        foreach (range(0, 2) as $monday) {
            $this->mark($monday, 'absent');
        }

        $this->actingAs($this->learner());
        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('notifications.unread', 1)->where('notifications.latest.0.kind', AttendanceNotice::WARNING));

        $this->post(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(0, $this->learner()->unreadNotifications()->count());
    }

    public function test_a_suspension_can_carry_its_memo_and_class_times()
    {
        Storage::fake();
        $this->actingAs($this->dean);

        $this->post(route('calendar.events.store'), [
            'title' => 'Afternoon suspension', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05',
            'suspends_classes' => true, 'suspends_from' => '13:00', 'suspends_until' => '12:00',
        ])->assertSessionHasErrors(['suspends_until' => 'The suspension must end after it starts.']);
        $this->post(route('calendar.events.store'), [
            'title' => 'Program', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05', 'suspends_classes' => false, 'suspends_from' => '13:00',
        ])->assertSessionHasErrors('suspends_from');
        $this->post(route('calendar.events.store'), [
            'title' => 'Memo', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05', 'suspends_classes' => true,
            'memo' => UploadedFile::fake()->create('memo.docx', 10),
        ])->assertSessionHasErrors(['memo' => 'The memo must be a PDF.']);

        $this->post(route('calendar.events.store'), [
            'title' => 'Afternoon suspension', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05',
            'suspends_classes' => true, 'suspends_from' => '13:00', 'memo_number' => 'Memo No. 42, s. 2026',
            'memo' => UploadedFile::fake()->create('memo-42.pdf', 120, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $event = CalendarEvent::sole();
        $this->assertSame('13:00:00', $event->suspends_from);
        $this->assertSame('memo-42.pdf', $event->memo_name);
        Storage::assertExists((string) $event->memo_path);

        $this->actingAs($this->teacher->user);
        $this->get(route('calendar.events.memo', $event))->assertOk();
        $this->actingAs(User::factory()->teacher(College::factory()->create())->create());
        $this->get(route('calendar.events.memo', $event))->assertForbidden();

        // Removing the memo deletes the file.
        $this->actingAs($this->dean);
        $this->put(route('calendar.events.update', $event), [
            'title' => 'Afternoon suspension', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05',
            'suspends_classes' => true, 'suspends_from' => '13:00', 'remove_memo' => true,
        ])->assertSessionHasNoErrors();
        Storage::assertMissing((string) $event->memo_path);
        $this->assertNull($event->refresh()->memo_path);
    }
}
