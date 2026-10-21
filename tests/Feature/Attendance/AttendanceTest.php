<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AbsenceExcuse;
use App\Models\AttendanceAudit;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\Batch;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A term in session (Aug 10 – Dec 18, 2026; today is Wed Sep 16) and a
 * Monday 8:00 class of CS 101 for section 1-A, with three learners.
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private College $college;

    private Term $term;

    private Section $section;

    private SubjectOffering $offering;

    private Schedule $class;

    private Teacher $teacher;

    /** @var list<Enrollment> */
    private array $enrolled = [];

    private const MONDAY = '2026-09-14';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-16 10:00');
        $this->college = College::factory()->create();
        $program = Program::factory()->for($this->college)->create(['code' => 'BSCS']);
        $this->term = Term::factory()->create(['starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        $this->section = Section::factory()->for($this->term)->for($program)->create(['year_level' => 1, 'name' => '1-A']);
        $this->teacher = Teacher::factory()->forCollege($this->college)->create();
        $this->offering = SubjectOffering::factory()->for($this->section)->create([
            'subject_id' => Subject::factory()->for($this->college)->create(['code' => 'CS 101'])->id,
            'teacher_id' => $this->teacher->id,
        ]);
        $this->class = Schedule::factory()->for($this->offering)->slot(1, '08:00', '09:30')->create(['teacher_id' => $this->teacher->id]);

        foreach (['Abad', 'Bautista', 'Cruz'] as $lastName) {
            $this->enrolled[] = $this->enrollment($lastName, '2026-08-07');
        }

        $this->actingAs($this->teacher->user);
    }

    private function enrollment(string $lastName, string $since, ?SubjectOffering $offering = null): Enrollment
    {
        $learner = Learner::factory()->for($this->section->program)->create();
        $learner->user->update(['last_name' => $lastName]);
        $enrollment = Enrollment::factory()->for($offering ?? $this->offering)->create(['learner_id' => $learner->id]);
        $enrollment->forceFill(['created_at' => $since.' 09:00'])->save();

        return $enrollment;
    }

    /**
     * @param  array<int, string>  $marks  enrollment id => status
     */
    private function save(array $marks, string $date = self::MONDAY, ?Schedule $class = null): TestResponse
    {
        return $this->put(route('attendance.update', ['schedule' => $class ?? $this->class, 'date' => $date]), [
            'marks' => array_map(fn (int $id, string $status): array => ['enrollment_id' => $id, 'status' => $status], array_keys($marks), $marks),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function everyone(string $status = 'present'): array
    {
        return array_fill_keys(array_map(fn (Enrollment $enrollment): int => $enrollment->id, $this->enrolled), $status);
    }

    public function test_the_teacher_sees_their_days_classes_with_who_was_late_or_absent()
    {
        $other = Schedule::factory()->for($this->anotherOffering())->slot(1, '10:00', '11:00')->create();
        AttendanceRecord::factory()->for($this->enrolled[0])->for($this->class)->late()->create(['class_date' => self::MONDAY]);

        $this->get(route('teacher.classes.index', ['date' => self::MONDAY]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('teacher/classes/Index')
                ->has('classes', 1)
                ->where('classes.0.schedule_id', $this->class->id)
                ->where('classes.0.listed', 3)
                ->where('classes.0.late', 1)
                ->where('classes.0.absent', 0)
                ->has('week', 1));

        $this->assertNotSame($this->teacher->id, $other->teacher_id);
        $this->get(route('teacher.classes.index', ['date' => '2026-09-15']))
            ->assertInertia(fn (Assert $page) => $page->has('classes', 0));
    }

    public function test_only_exceptions_are_recorded_and_each_is_audited()
    {
        // Everyone counts as present without a record.
        $this->get(route('attendance.show', ['schedule' => $this->class, 'date' => self::MONDAY]))
            ->assertInertia(fn (Assert $page) => $page->has('learners', 3)->where('learners.0.status', null));

        $this->save([$this->enrolled[0]->id => 'present', $this->enrolled[1]->id => 'late'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '1 mark saved.');

        $late = AttendanceRecord::sole();
        $this->assertSame($this->enrolled[1]->id, $late->enrollment_id);
        $this->assertSame(AttendanceStatus::Late, $late->status);
        $this->assertSame($this->teacher->user_id, $late->recorded_by);
        $audit = AttendanceAudit::sole();
        $this->assertSame([null, AttendanceStatus::Late], [$audit->old_status, $audit->new_status]);

        $this->save([$this->enrolled[0]->id => 'present'])->assertInertiaFlash('toast.message', 'Nothing changed.');
        $this->assertSame(1, AttendanceRecord::count());
    }

    public function test_corrections_keep_the_record_and_its_history()
    {
        $this->save([$this->enrolled[2]->id => 'absent'])->assertSessionHasNoErrors();
        $record = AttendanceRecord::sole();

        // Back to present: the record stays (as present) so the change is on record.
        $this->save([$this->enrolled[2]->id => 'present'])->assertInertiaFlash('toast.message', '1 mark saved.');

        $this->assertSame(AttendanceStatus::Present, $record->refresh()->status);
        $audit = AttendanceAudit::where('attendance_record_id', $record->id)->latest('id')->firstOrFail();
        $this->assertSame([AttendanceStatus::Absent, AttendanceStatus::Present], [$audit->old_status, $audit->new_status]);
        $this->assertSame(2, AttendanceAudit::count());

        $this->get(route('teacher.classes.index', ['date' => self::MONDAY]))
            ->assertInertia(fn (Assert $page) => $page->where('classes.0.late', 0)->where('classes.0.absent', 0));
    }

    public function test_an_excused_absence_stays_as_the_excuse_decided()
    {
        $excuse = AbsenceExcuse::factory()->create(['learner_id' => $this->enrolled[0]->learner_id]);
        $record = AttendanceRecord::factory()->for($this->enrolled[0])->for($this->class)->absent()->create(['class_date' => self::MONDAY, 'absence_excuse_id' => $excuse->id]);

        $this->get(route('attendance.show', ['schedule' => $this->class, 'date' => self::MONDAY]))
            ->assertInertia(fn (Assert $page) => $page->where('learners.0.excused', true));

        $this->save($this->everyone())->assertSessionHasNoErrors();
        $this->assertSame(AttendanceStatus::Absent, $record->refresh()->status);
        $this->assertSame($excuse->id, $record->absence_excuse_id);
    }

    public function test_the_list_follows_enrollment_dates_and_batches()
    {
        $late = $this->enrollment('Dizon', '2026-09-15');
        $this->enrolled[2]->forceFill(['status' => EnrollmentStatus::Dropped, 'drop_confirmed_at' => '2026-09-10 10:00'])->save();

        $names = fn (string $date) => collect($this->get(route('attendance.show', ['schedule' => $this->class, 'date' => $date]))
            ->viewData('page')['props']['learners'])->pluck('name')->map(fn (string $name) => strtok($name, ','))->all();

        // Cruz dropped on the 10th: listed on the 7th, not the 14th; Dizon joined on the 15th.
        $this->assertSame(['Abad', 'Bautista', 'Cruz'], $names('2026-09-07'));
        $this->assertSame(['Abad', 'Bautista'], $names(self::MONDAY));
        $this->assertNotContains($late->learner->user->last_name, $names(self::MONDAY));

        $batch = Batch::factory()->for($this->offering)->create(['name' => '1-AA']);
        $this->enrolled[0]->update(['batch_id' => $batch->id]);
        $lab = Schedule::factory()->for($this->offering)->for($batch)->slot(3, '13:00', '16:00')->create(['teacher_id' => $this->teacher->id]);

        $this->get(route('attendance.show', ['schedule' => $lab, 'date' => '2026-09-16']))
            ->assertInertia(fn (Assert $page) => $page->has('learners', 1)->where('learners.0.enrollment_id', $this->enrolled[0]->id));
    }

    public function test_attendance_is_only_taken_on_real_class_days()
    {
        $marks = $this->everyone();

        $this->save($marks, '2026-09-15')->assertSessionHasErrors(['marks' => 'This class meets on Monday, not on Tuesday, Sep 15.']);
        $this->save($marks, '2026-09-21')->assertSessionHasErrors(['marks' => 'This class hasn\'t met yet; attendance opens on the day.']);
        $this->save($marks, '2026-08-03')->assertSessionHasErrors(['marks' => 'Aug 3, 2026 is outside '.$this->term->label.'.']);

        CalendarEvent::factory()->create(['college_id' => null, 'title' => 'Typhoon', 'starts_on' => '2026-09-07', 'ends_on' => '2026-09-07', 'suspends_classes' => true]);
        $this->save($marks, '2026-09-07')->assertSessionHasErrors(['marks' => 'Classes are suspended on Sep 7 (Typhoon).']);

        CalendarEvent::factory()->create(['college_id' => College::factory()->create()->id, 'starts_on' => '2026-08-31', 'ends_on' => '2026-08-31', 'suspends_classes' => true]);
        $this->save($marks, '2026-08-31')->assertSessionHasNoErrors();

        $this->get(route('attendance.show', ['schedule' => $this->class, 'date' => '2026-09-07']))
            ->assertInertia(fn (Assert $page) => $page->where('refusal', 'Classes are suspended on Sep 7 (Typhoon).')->has('learners', 0));
    }

    public function test_the_meeting_dates_skip_suspended_days()
    {
        CalendarEvent::factory()->create(['college_id' => $this->college->id, 'starts_on' => '2026-09-07', 'ends_on' => '2026-09-07', 'suspends_classes' => true]);

        $this->get(route('attendance.show', ['schedule' => $this->class, 'date' => self::MONDAY]))
            ->assertInertia(fn (Assert $page) => $page->where('previous', '2026-08-31')->where('next', null));
    }

    public function test_only_the_classs_teacher_and_its_colleges_dean_take_attendance()
    {
        $this->actingAs(Teacher::factory()->forCollege($this->college)->create()->user);
        $this->get(route('attendance.show', ['schedule' => $this->class, 'date' => self::MONDAY]))->assertForbidden();
        $this->save($this->everyone())->assertForbidden();

        $this->actingAs(User::factory()->dean(College::factory()->create())->create());
        $this->save($this->everyone())->assertForbidden();

        $this->actingAs(User::factory()->registrar()->create());
        $this->get(route('attendance.show', ['schedule' => $this->class, 'date' => self::MONDAY]))->assertForbidden();

        $dean = User::factory()->dean($this->college)->create();
        $this->actingAs($dean);
        $this->save([$this->enrolled[0]->id => 'absent'])->assertSessionHasNoErrors();
        $this->assertSame($dean->id, AttendanceRecord::firstOrFail()->recorded_by);
    }

    public function test_a_closed_terms_attendance_is_read_only()
    {
        $this->term->forceFill(['closed_at' => now()])->save();

        $this->get(route('attendance.show', ['schedule' => $this->class, 'date' => self::MONDAY]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canEdit', false)->where('closed', true));
        $this->save($this->everyone())->assertForbidden();
    }

    public function test_a_terms_limit_stops_teachers_changing_older_marks_but_not_the_dean()
    {
        AttendancePolicy::factory()->for($this->term)->create(['teacher_edit_days' => 2]);

        // Monday the 14th + 2 days is today: still open.
        $this->save([$this->enrolled[0]->id => 'late'])->assertSessionHasNoErrors();

        // Monday the 7th + 2 days was last week.
        $this->get(route('attendance.show', ['schedule' => $this->class, 'date' => '2026-09-07']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canEdit', false)
                ->where('teacherLock', 'Teachers can change marks up to 2 days after a class this term; ask your dean for a correction.')
                ->has('learners', 3));
        $this->save([$this->enrolled[0]->id => 'absent'], '2026-09-07')->assertSessionHasErrors('marks');
        $this->assertSame(1, AttendanceRecord::count());

        $this->actingAs(User::factory()->dean($this->college)->create());
        $this->get(route('attendance.show', ['schedule' => $this->class, 'date' => '2026-09-07']))
            ->assertInertia(fn (Assert $page) => $page->where('canEdit', true)->where('teacherLock', null));
        $this->save([$this->enrolled[0]->id => 'absent'], '2026-09-07')->assertSessionHasNoErrors();
    }

    public function test_no_limit_by_default()
    {
        AttendancePolicy::factory()->for($this->term)->create();

        $this->save([$this->enrolled[0]->id => 'absent'], '2026-08-10')->assertSessionHasNoErrors();
    }

    public function test_the_dean_sees_the_colleges_classes_on_a_day()
    {
        $this->anotherOffering();
        Schedule::factory()->for($this->anotherOffering('CS 102'))->slot(1, '10:00', '11:00')->create();
        $elsewhere = Section::factory()->for($this->term)->create();
        Schedule::factory()->for(SubjectOffering::factory()->for($elsewhere))->slot(1, '10:00', '11:00')->create();
        $this->actingAs(User::factory()->dean($this->college)->create());

        $this->get(route('dean.attendance.index', ['date' => self::MONDAY]))
            ->assertInertia(fn (Assert $page) => $page->component('dean/attendance/Index')->has('classes', 2));
        $this->get(route('dean.attendance.index', ['date' => self::MONDAY, 'teacher' => $this->teacher->id]))
            ->assertInertia(fn (Assert $page) => $page->has('classes', 1)->where('classes.0.listed', 3));
    }

    private function anotherOffering(string $code = 'CS 199'): SubjectOffering
    {
        return SubjectOffering::factory()->for($this->section)->create([
            'subject_id' => Subject::factory()->for($this->college)->create(['code' => $code])->id,
        ]);
    }
}
