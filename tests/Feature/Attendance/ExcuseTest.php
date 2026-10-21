<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\DecisionStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExcuseStatus;
use App\Models\AbsenceExcuse;
use App\Models\AbsenceExcuseDocument;
use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
use App\Models\Batch;
use App\Models\College;
use App\Models\Enrollment;
use App\Models\ExcuseDecision;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A registered learner (today is Wed Sep 30) with two Monday classes taught
 * by different teachers: CS 101 at 8:00 (teacher 0) and CS 102 at 10:00
 * (teacher 1). Excuses are decided class by class.
 */
class ExcuseTest extends TestCase
{
    use RefreshDatabase;

    private College $college;

    private Term $term;

    private Section $section;

    private Learner $learner;

    private User $dean;

    /** @var list<Teacher> */
    private array $teachers = [];

    /** @var list<Schedule> */
    private array $classes = [];

    /** @var list<Enrollment> */
    private array $enrollments = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        $this->travelTo('2026-09-30 10:00');
        $this->college = College::factory()->create();
        $program = Program::factory()->for($this->college)->create();
        $this->term = Term::factory()->create(['starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        $this->section = Section::factory()->for($this->term)->for($program)->create(['year_level' => 1]);
        $this->dean = User::factory()->dean($this->college)->create();
        $this->learner = Learner::factory()->for($program)->create();
        TermRegistration::factory()->for($this->learner)->for($this->term)->create(['section_id' => $this->section->id]);

        foreach (['CS 101' => ['08:00', '09:30'], 'CS 102' => ['10:00', '11:30']] as $code => [$start, $end]) {
            $teacher = Teacher::factory()->forCollege($this->college)->create();
            $this->teachers[] = $teacher;
            $offering = $this->offering($code, $teacher);
            $this->classes[] = Schedule::factory()->for($offering)->slot(1, $start, $end)->create(['teacher_id' => $teacher->id]);
            $this->enrollments[] = $this->enroll($offering);
        }
    }

    private function offering(string $code, ?Teacher $teacher): SubjectOffering
    {
        return SubjectOffering::factory()->for($this->section)->create(['subject_id' => Subject::factory()->for($this->college)->create(['code' => $code])->id, 'teacher_id' => $teacher?->id]);
    }

    private function enroll(SubjectOffering $offering, ?Batch $batch = null): Enrollment
    {
        $enrollment = Enrollment::factory()->for($offering)->create(['learner_id' => $this->learner->id, 'batch_id' => $batch?->id]);
        $enrollment->forceFill(['created_at' => '2026-08-07'])->save();

        return $enrollment;
    }

    private function teacher(int $i): User
    {
        return $this->teachers[$i]->user;
    }

    private function mark(int $class, string $date, string $status): void
    {
        $this->actingAs($this->teacher($class))
            ->put(route('attendance.update', ['schedule' => $this->classes[$class], 'date' => $date]), [
                'marks' => [['enrollment_id' => $this->enrollments[$class]->id, 'status' => $status]],
            ])->assertSessionHasNoErrors();
    }

    /**
     * Submit an excuse as the learner and return it.
     *
     * @param  array<string, mixed>  $data
     */
    private function submit(array $data = []): AbsenceExcuse
    {
        $this->actingAs($this->learner->user)->post(route('learner.excuses.store'), [
            'date_from' => '2026-09-21', 'date_to' => '2026-09-25', 'reason' => 'school_activity', ...$data,
        ])->assertSessionHasNoErrors();

        return AbsenceExcuse::latest('id')->firstOrFail();
    }

    private function decision(AbsenceExcuse $excuse, int $class): ExcuseDecision
    {
        return $excuse->decisions()->where('subject_offering_id', $this->classes[$class]->subject_offering_id)->sole();
    }

    private function decide(User $as, ExcuseDecision $decision, string $action, ?string $remarks = 'Noted.'): TestResponse
    {
        return $this->actingAs($as)->post(route("excuses.decisions.{$action}", $decision), ['remarks' => $remarks]);
    }

    public function test_submitting_creates_a_decision_for_each_class_meeting_in_the_range()
    {
        $tuesday = Schedule::factory()->for($this->offering('CS 103', $this->teachers[0]))->slot(2, '08:00', '09:00')->create(['teacher_id' => $this->teachers[0]->id]);
        $this->enroll($tuesday->subjectOffering);

        $excuse = $this->submit(['date_from' => '2026-09-21', 'date_to' => '2026-09-21']);

        // Only the Monday classes meet on the 21st.
        $this->assertEqualsCanonicalizing([$this->classes[0]->subject_offering_id, $this->classes[1]->subject_offering_id], $excuse->decisions()->pluck('subject_offering_id')->all());
        $this->assertSame(ExcuseStatus::Pending, $excuse->status);
        $this->assertSame(AttendanceNotice::EXCUSE_TO_DECIDE, $this->teacher(0)->notifications()->sole()->data['kind']);
        $this->assertSame(1, $this->teacher(1)->notifications()->count());
        $this->assertSame(0, $this->dean->notifications()->count());
    }

    public function test_an_excuse_on_days_without_classes_has_nothing_to_decide()
    {
        $this->actingAs($this->learner->user)->post(route('learner.excuses.store'), ['date_from' => '2026-09-23', 'date_to' => '2026-09-23', 'reason' => 'other'])
            ->assertInertiaFlash('toast.message', 'Excuse submitted, but none of your classes meet on those days, so there is nothing to excuse.');
        $this->assertSame(0, ExcuseDecision::count());
    }

    public function test_each_teacher_decides_for_their_own_class()
    {
        $this->mark(0, '2026-09-21', 'absent');
        $this->mark(1, '2026-09-21', 'absent');
        $excuse = $this->submit();

        $this->decide($this->teacher(0), $this->decision($excuse, 1), 'approve')->assertForbidden();
        $this->decide($this->teacher(0), $this->decision($excuse, 0), 'approve', null)->assertSessionHasNoErrors();

        $linked = AttendanceRecord::whereNotNull('absence_excuse_id')->pluck('enrollment_id')->all();
        $this->assertSame([$this->enrollments[0]->id], $linked, 'Only CS 101\'s absence is excused.');
        $this->assertSame(ExcuseStatus::Pending, $excuse->refresh()->status);

        $this->decide($this->teacher(1), $this->decision($excuse, 1), 'decline', null)->assertSessionHasErrors(['remarks' => 'Tell the learner why.']);
        $this->decide($this->teacher(1), $this->decision($excuse, 1), 'decline', 'Not an excused activity for this class.')->assertSessionHasNoErrors();
        $this->assertSame(ExcuseStatus::Partial, $excuse->refresh()->status);

        $kinds = $this->learner->user->notifications()->get()->map(fn ($notification) => $notification->data['kind'])->sort()->values()->all();
        $this->assertSame([AttendanceNotice::EXCUSE_APPROVED, AttendanceNotice::EXCUSE_DECLINED], $kinds);
        $this->assertSame($excuse->id, AttendanceAudit::whereNotNull('new_absence_excuse_id')->sole()->new_absence_excuse_id);
    }

    public function test_the_dean_oversees_and_decides_only_for_a_class_without_an_active_teacher()
    {
        $excuse = $this->submit();

        $this->actingAs($this->dean)->get(route('excuses.decisions.show', $this->decision($excuse, 0)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canDecide', false));
        $this->decide($this->dean, $this->decision($excuse, 0), 'approve')->assertForbidden();

        // CS 102's teacher left: the dean decides it.
        $this->teacher(1)->forceFill(['is_active' => false])->save();
        $this->decide($this->dean, $this->decision($excuse, 1), 'approve')->assertSessionHasNoErrors();
        $this->assertSame($this->dean->id, $this->decision($excuse, 1)->decided_by);

        $this->actingAs($this->dean)->get(route('dean.excuses.show', $excuse))
            ->assertInertia(fn (Assert $page) => $page->component('dean/excuses/Show')->has('excuse.decisions', 2)->where('deciders.'.$this->decision($excuse, 0)->id.'.dean', false));
    }

    public function test_whoever_teaches_the_class_now_decides_it()
    {
        $excuse = $this->submit();
        $substitute = Teacher::factory()->forCollege($this->college)->create();
        $this->classes[0]->update(['teacher_id' => $substitute->id]);

        $this->decide($this->teacher(0), $this->decision($excuse, 0), 'approve')->assertForbidden();
        $this->decide($substitute->user, $this->decision($excuse, 0), 'approve')->assertSessionHasNoErrors();
        $this->assertSame($substitute->user_id, $this->decision($excuse, 0)->decided_by);
    }

    public function test_a_batch_lab_is_decided_by_the_learners_own_batchs_teacher()
    {
        $lab = $this->offering('CS 110', null);
        [$aa, $ab] = [Batch::factory()->for($lab)->create(['name' => '1-AA']), Batch::factory()->for($lab)->create(['name' => '1-AB'])];
        $teacherAA = Teacher::factory()->forCollege($this->college)->create();
        $teacherAB = Teacher::factory()->forCollege($this->college)->create();
        Schedule::factory()->for($lab)->for($aa)->slot(1, '13:00', '16:00')->create(['teacher_id' => $teacherAA->id]);
        Schedule::factory()->for($lab)->for($ab)->slot(1, '13:00', '16:00')->create(['teacher_id' => $teacherAB->id]);
        $this->enroll($lab, $ab);

        $excuse = $this->submit();
        $decision = $excuse->decisions()->where('subject_offering_id', $lab->id)->sole();

        $this->decide($teacherAA->user, $decision, 'approve')->assertForbidden();
        $this->actingAs($teacherAA->user)->get(route('teacher.excuses.index'))
            ->assertInertia(fn (Assert $page) => $page->where('counts.pending', 0));
        $this->decide($teacherAB->user, $decision, 'approve')->assertSessionHasNoErrors();
    }

    public function test_only_a_medical_excuse_needs_a_document()
    {
        $medical = $this->submit(['reason' => 'medical']);
        $this->decide($this->teacher(0), $this->decision($medical, 0), 'approve')->assertSessionHasErrors(['excuse' => 'A medical excuse needs its certificate attached before it can be approved.']);

        AbsenceExcuseDocument::factory()->for($medical)->create();
        $this->decide($this->teacher(0), $this->decision($medical, 0), 'approve')->assertSessionHasNoErrors();

        $illness = $this->submit(['reason' => 'illness', 'date_from' => '2026-09-28', 'date_to' => '2026-09-28']);
        $this->decide($this->teacher(1), $this->decision($illness, 1), 'approve')->assertSessionHasNoErrors();
    }

    public function test_later_absences_are_excused_in_approved_classes_only_and_revoking_restores_them()
    {
        foreach (['2026-08-10', '2026-08-17', '2026-08-24', '2026-08-31'] as $date) {
            $this->mark(0, $date, 'absent');
        }

        $excuse = $this->submit(['date_from' => '2026-09-21', 'date_to' => '2026-09-21']);
        $this->decide($this->teacher(0), $this->decision($excuse, 0), 'approve')->assertSessionHasNoErrors();

        $this->mark(0, '2026-09-21', 'absent');
        $this->mark(1, '2026-09-21', 'absent');
        $this->assertSame($excuse->id, AttendanceRecord::where('enrollment_id', $this->enrollments[0]->id)->where('class_date', '2026-09-21')->sole()->absence_excuse_id);
        $this->assertNull(AttendanceRecord::where('enrollment_id', $this->enrollments[1]->id)->sole()->absence_excuse_id, 'CS 102 has not approved.');
        $this->assertSame(EnrollmentStatus::Enrolled, $this->enrollments[0]->refresh()->status);

        $this->decide($this->teacher(0), $this->decision($excuse, 0), 'revoke', 'The activity was cancelled.')->assertSessionHasNoErrors();
        $this->assertSame(DecisionStatus::Revoked, $this->decision($excuse, 0)->status);
        $this->assertNull(AttendanceRecord::where('enrollment_id', $this->enrollments[0]->id)->where('class_date', '2026-09-21')->sole()->absence_excuse_id);
        $this->assertSame(EnrollmentStatus::ForDropping, $this->enrollments[0]->refresh()->status);
    }

    public function test_revoking_moves_absences_to_another_excuse_approved_for_the_class()
    {
        $this->mark(0, '2026-09-21', 'absent');
        $first = $this->submit(['date_from' => '2026-09-21', 'date_to' => '2026-09-21']);
        $wider = $this->submit(['date_from' => '2026-09-20', 'date_to' => '2026-09-23']);
        $this->decide($this->teacher(0), $this->decision($first, 0), 'approve')->assertSessionHasNoErrors();
        $this->decide($this->teacher(0), $this->decision($wider, 0), 'approve')->assertSessionHasNoErrors();

        $this->decide($this->teacher(0), $this->decision($first, 0), 'revoke', 'Duplicate.')->assertSessionHasNoErrors();

        $this->assertSame($wider->id, AttendanceRecord::sole()->absence_excuse_id);
    }

    public function test_a_teacher_files_an_excuse_approved_in_their_own_classes()
    {
        $this->mark(0, '2026-09-21', 'absent');
        $this->actingAs($this->teacher(0))->post(route('teacher.excuses.store'), [
            'learner_id' => $this->learner->id, 'date_from' => '2026-09-21', 'date_to' => '2026-09-21', 'reason' => 'school_activity', 'remarks' => 'Represented the class.',
        ])->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Excuse filed and approved for your class. 1 other class waits for its teacher.');

        $excuse = AbsenceExcuse::sole();
        $this->assertSame($this->teacher(0)->id, $excuse->submitted_by);
        $this->assertSame(DecisionStatus::Approved, $this->decision($excuse, 0)->status);
        $this->assertSame(DecisionStatus::Pending, $this->decision($excuse, 1)->status);
        $this->assertNotNull(AttendanceRecord::sole()->absence_excuse_id);

        $stranger = Learner::factory()->create();
        $this->actingAs($this->teacher(0))->post(route('teacher.excuses.store'), [
            'learner_id' => $stranger->id, 'date_from' => '2026-09-21', 'date_to' => '2026-09-21', 'reason' => 'other',
        ])->assertSessionHasErrors();
        $this->assertSame(1, AbsenceExcuse::count());
    }

    public function test_excuses_more_than_seven_days_after_are_flagged_late_but_accepted()
    {
        $this->actingAs($this->learner->user);
        $this->post(route('learner.excuses.store'), ['date_from' => '2026-09-23', 'date_to' => '2026-09-23', 'reason' => 'other']);
        $this->post(route('learner.excuses.store'), ['date_from' => '2026-09-21', 'date_to' => '2026-09-21', 'reason' => 'other'])
            ->assertInertiaFlash('toast.message', 'Excuse submitted, 2 days past the 7-day deadline. Your teachers will see it as late.');

        $this->assertSame([0, 2], AbsenceExcuse::orderBy('id')->get()->map->daysLate()->all());
        $this->travel(30)->days();
        $this->assertSame(0, AbsenceExcuse::orderBy('id')->firstOrFail()->daysLate());
    }

    public function test_an_excuse_must_fit_a_registered_term_and_take_pdfs_or_photos()
    {
        $this->actingAs($this->learner->user);
        $submit = fn (array $data) => $this->post(route('learner.excuses.store'), ['reason' => 'other', ...$data]);

        $submit(['date_from' => '2026-09-01', 'date_to' => '2026-10-15'])->assertSessionHasErrors(['date_to' => 'One excuse can cover at most 31 days.']);
        $submit(['date_from' => '2026-07-01', 'date_to' => '2026-07-02'])->assertSessionHasErrors(['date_from' => 'You aren\'t registered for a term on those days.']);
        $submit(['date_from' => '2026-09-21', 'date_to' => '2026-09-20'])->assertSessionHasErrors('date_to');
        $submit(['date_from' => '2026-09-21', 'date_to' => '2026-09-21', 'files' => [UploadedFile::fake()->create('notes.docx', 10)]])->assertSessionHasErrors(['files.0' => 'Attach PDFs or photos (JPG, PNG, WebP).']);
        $this->assertSame(0, AbsenceExcuse::count());
    }

    public function test_a_closed_terms_excuses_stay_as_they_are()
    {
        $excuse = $this->submit();
        $this->term->forceFill(['closed_at' => now()])->save();

        $this->decide($this->teacher(0), $this->decision($excuse, 0), 'approve')->assertSessionHasErrors('excuse');
    }

    public function test_files_are_for_the_learner_the_deciding_teachers_and_the_dean()
    {
        $this->actingAs($this->learner->user)->post(route('learner.excuses.store'), [
            'date_from' => '2026-09-21', 'date_to' => '2026-09-21', 'reason' => 'medical',
            'files' => [UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf')],
        ])->assertSessionHasNoErrors();
        $document = AbsenceExcuseDocument::sole();

        foreach ([$this->learner->user, $this->teacher(0), $this->teacher(1), $this->dean] as $allowed) {
            $this->actingAs($allowed)->get(route('excuses.documents.show', $document))->assertOk();
        }

        $this->actingAs(Teacher::factory()->forCollege($this->college)->create()->user)->get(route('excuses.documents.show', $document))->assertForbidden();
        $this->actingAs(Learner::factory()->create()->user)->get(route('excuses.documents.show', $document))->assertForbidden();
        $this->actingAs(User::factory()->dean(College::factory()->create())->create())
            ->get(route('excuses.decisions.show', $document->absenceExcuse->decisions()->firstOrFail()))->assertForbidden();
    }

    public function test_each_side_sees_its_own_list()
    {
        $this->mark(0, '2026-09-21', 'late');
        $excuse = $this->submit();

        $this->actingAs($this->teacher(0))->get(route('teacher.excuses.index'))
            ->assertInertia(fn (Assert $page) => $page->component('teacher/excuses/Index')->has('decisions.data', 1)->where('decisions.data.0.subject.code', 'CS 101'));
        $this->actingAs($this->teacher(0))->get(route('excuses.decisions.show', $this->decision($excuse, 0)))
            ->assertInertia(fn (Assert $page) => $page->component('excuses/Decision')->where('canDecide', true)->where('lates', 1)->has('absences', 0));
        $this->actingAs($this->dean)->get(route('dean.excuses.index'))
            ->assertInertia(fn (Assert $page) => $page->has('excuses.data', 1)->where('excuses.data.0.dean_decides', 0));
        $this->actingAs($this->learner->user)->get(route('learner.excuses.index'))
            ->assertInertia(fn (Assert $page) => $page->has('excuses.0.decisions', 2));

        $this->assertSame(AttendanceStatus::Late, AttendanceRecord::sole()->status);
    }
}
