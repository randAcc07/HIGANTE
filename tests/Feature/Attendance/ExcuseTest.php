<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExcuseReason;
use App\Enums\ExcuseStatus;
use App\Models\AbsenceExcuse;
use App\Models\AbsenceExcuseDocument;
use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A registered learner in a term in session (today is Wed Sep 30) with two
 * classes: CS 101 on Mondays at 8:00 and CS 102 on Mondays at 10:00.
 */
class ExcuseTest extends TestCase
{
    use RefreshDatabase;

    private College $college;

    private Term $term;

    private Learner $learner;

    private User $dean;

    private Teacher $teacher;

    /** @var array{Schedule, Schedule} */
    private array $classes;

    /** @var array{Enrollment, Enrollment} */
    private array $enrollments;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        $this->travelTo('2026-09-30 10:00');
        $this->college = College::factory()->create();
        $program = Program::factory()->for($this->college)->create();
        $this->term = Term::factory()->create(['starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        $section = Section::factory()->for($this->term)->for($program)->create(['year_level' => 1]);
        $this->teacher = Teacher::factory()->forCollege($this->college)->create();
        $this->dean = User::factory()->dean($this->college)->create();
        $this->learner = Learner::factory()->for($program)->create();
        TermRegistration::factory()->for($this->learner)->for($this->term)->create(['section_id' => $section->id]);

        foreach (['CS 101' => '08:00', 'CS 102' => '10:00'] as $code => $start) {
            $offering = SubjectOffering::factory()->for($section)->create(['subject_id' => Subject::factory()->for($this->college)->create(['code' => $code])->id, 'teacher_id' => $this->teacher->id]);
            $this->classes[] = Schedule::factory()->for($offering)->slot(1, $start, date('H:i', strtotime($start) + 5400))->create(['teacher_id' => $this->teacher->id]);
            $enrollment = Enrollment::factory()->for($offering)->create(['learner_id' => $this->learner->id]);
            $enrollment->forceFill(['created_at' => '2026-08-07'])->save();
            $this->enrollments[] = $enrollment;
        }
    }

    private function mark(int $class, string $date, string $status): void
    {
        $this->actingAs($this->teacher->user)
            ->put(route('attendance.update', ['schedule' => $this->classes[$class], 'date' => $date]), [
                'marks' => [['enrollment_id' => $this->enrollments[$class]->id, 'status' => $status]],
            ])->assertSessionHasNoErrors();
    }

    private function excuse(array $override = []): AbsenceExcuse
    {
        return AbsenceExcuse::factory()->create([
            'learner_id' => $this->learner->id,
            'date_from' => '2026-09-21', 'date_to' => '2026-09-25',
            'reason' => ExcuseReason::SchoolActivity, 'status' => ExcuseStatus::Pending,
            ...$override,
        ]);
    }

    private function decide(string $decision, AbsenceExcuse $excuse, ?string $remarks = 'Noted.'): TestResponse
    {
        return $this->actingAs($this->dean)->post(route("dean.excuses.{$decision}", $excuse), ['remarks' => $remarks]);
    }

    public function test_a_learner_submits_an_excuse_with_files_and_the_dean_hears_of_it()
    {
        $this->actingAs($this->learner->user)->post(route('learner.excuses.store'), [
            'date_from' => '2026-09-21', 'date_to' => '2026-09-22', 'reason' => 'medical', 'remarks' => 'Fever.',
            'files' => [UploadedFile::fake()->create('certificate.pdf', 200, 'application/pdf'), UploadedFile::fake()->image('page2.jpg')],
        ])->assertSessionHasNoErrors();

        $excuse = AbsenceExcuse::sole();
        $this->assertSame(ExcuseStatus::Pending, $excuse->status);
        $this->assertSame($this->learner->user_id, $excuse->submitted_by);
        $this->assertSame(['certificate.pdf', 'page2.jpg'], $excuse->documents()->pluck('original_name')->all());
        Storage::assertExists((string) AbsenceExcuseDocument::first()?->path);
        $this->assertSame(AttendanceNotice::EXCUSE_SUBMITTED, $this->dean->notifications()->sole()->data['kind']);

        $this->get(route('learner.excuses.index'))
            ->assertInertia(fn (Assert $page) => $page->component('learner/excuses/Index')->has('excuses', 1)->has('excuses.0.documents', 2));
    }

    public function test_excuses_more_than_seven_days_after_are_flagged_late_but_accepted()
    {
        // Today is Sep 30: an excuse ending Sep 23 is due by Sep 30 (on time),
        // one ending Sep 21 was due Sep 28 (2 days late).
        $this->actingAs($this->learner->user);
        $this->post(route('learner.excuses.store'), ['date_from' => '2026-09-23', 'date_to' => '2026-09-23', 'reason' => 'other'])
            ->assertInertiaFlash('toast.type', 'success');
        $this->post(route('learner.excuses.store'), ['date_from' => '2026-09-21', 'date_to' => '2026-09-21', 'reason' => 'other'])
            ->assertInertiaFlash('toast.message', 'Excuse submitted, 2 days past the 7-day deadline. Your dean will see it as late.');

        $this->assertSame([0, 2], AbsenceExcuse::orderBy('id')->get()->map->daysLate()->all());

        $this->actingAs($this->dean)->get(route('dean.excuses.index'))
            ->assertInertia(fn (Assert $page) => $page->where('excuses.data.0.days_late', 0)->where('excuses.data.1.days_late', 2));

        // The deadline is measured from when it was submitted, not from today.
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

    public function test_approval_excuses_absences_in_every_class_but_not_lates()
    {
        $this->mark(0, '2026-09-21', 'absent');
        $this->mark(1, '2026-09-21', 'late');
        $this->mark(1, '2026-09-28', 'absent');
        $excuse = $this->excuse();

        $this->decide('approve', $excuse, null)->assertSessionHasNoErrors();

        $excuse->refresh();
        $this->assertSame(ExcuseStatus::Approved, $excuse->status);
        $this->assertSame($this->dean->id, $excuse->reviewed_by);
        $linked = AttendanceRecord::where('absence_excuse_id', $excuse->id)->get();
        $this->assertSame([$this->enrollments[0]->id], $linked->pluck('enrollment_id')->all(), 'Only the absence on the 21st; the late stays, the 28th is outside.');
        $audit = AttendanceAudit::where('attendance_record_id', $linked->first()?->id)->latest('id')->firstOrFail();
        $this->assertSame([null, $excuse->id], [$audit->old_absence_excuse_id, $audit->new_absence_excuse_id]);
        $this->assertSame(AttendanceNotice::EXCUSE_APPROVED, $this->learner->user->notifications()->latest()->first()?->data['kind']);
    }

    public function test_only_a_medical_excuse_needs_a_document()
    {
        $medical = $this->excuse(['reason' => ExcuseReason::Medical]);
        $this->decide('approve', $medical)->assertSessionHasErrors(['excuse' => 'A medical excuse needs its certificate attached before it can be approved.']);
        $this->assertSame(ExcuseStatus::Pending, $medical->refresh()->status);

        AbsenceExcuseDocument::factory()->for($medical)->create();
        $this->decide('approve', $medical)->assertSessionHasNoErrors();

        $illness = $this->excuse(['reason' => ExcuseReason::Illness, 'date_from' => '2026-09-28', 'date_to' => '2026-09-28']);
        $this->decide('approve', $illness)->assertSessionHasNoErrors();
        $this->assertSame(ExcuseStatus::Approved, $illness->refresh()->status);
    }

    public function test_absences_marked_after_approval_are_excused_and_standing_follows()
    {
        foreach (['2026-08-10', '2026-08-17', '2026-08-24', '2026-08-31'] as $date) {
            $this->mark(0, $date, 'absent');
        }

        $excuse = $this->excuse(['date_from' => '2026-09-21', 'date_to' => '2026-09-21']);
        $this->decide('approve', $excuse)->assertSessionHasNoErrors();

        // The fifth absence lands on an excused day: no flag.
        $this->mark(0, '2026-09-21', 'absent');
        $this->assertSame($excuse->id, AttendanceRecord::where('class_date', '2026-09-21')->sole()->absence_excuse_id);
        $this->assertSame(EnrollmentStatus::Enrolled, $this->enrollments[0]->refresh()->status);

        // Revoked: it counts, and the learner is flagged.
        $this->decide('revoke', $excuse, 'The activity was cancelled.')->assertSessionHasNoErrors();
        $excuse->refresh();
        $this->assertSame(ExcuseStatus::Revoked, $excuse->status);
        $this->assertSame('The activity was cancelled.', $excuse->revoke_remarks);
        $this->assertNull(AttendanceRecord::where('class_date', '2026-09-21')->sole()->absence_excuse_id);
        $this->assertSame(EnrollmentStatus::ForDropping, $this->enrollments[0]->refresh()->status);
    }

    public function test_revoking_moves_absences_to_another_approved_excuse_covering_them()
    {
        $this->mark(0, '2026-09-21', 'absent');
        $first = $this->excuse(['date_from' => '2026-09-21', 'date_to' => '2026-09-21']);
        $wider = $this->excuse(['date_from' => '2026-09-20', 'date_to' => '2026-09-23']);
        $this->decide('approve', $first)->assertSessionHasNoErrors();
        $this->decide('approve', $wider)->assertSessionHasNoErrors();

        $this->decide('revoke', $first, 'Duplicate.')->assertSessionHasNoErrors();

        $this->assertSame($wider->id, AttendanceRecord::sole()->absence_excuse_id);
    }

    public function test_rejecting_needs_remarks_for_the_learner()
    {
        $excuse = $this->excuse();

        $this->decide('reject', $excuse, null)->assertSessionHasErrors(['remarks' => 'Tell the learner why.']);
        $this->decide('reject', $excuse, 'Not an excusable activity.')->assertSessionHasNoErrors();

        $this->assertSame(ExcuseStatus::Rejected, $excuse->refresh()->status);
        $this->decide('approve', $excuse)->assertSessionHasErrors(['excuse' => 'This excuse was already decided. Reload the page.']);
    }

    public function test_a_closed_terms_excuses_stay_as_they_are()
    {
        $excuse = $this->excuse();
        $this->term->forceFill(['closed_at' => now()])->save();

        $this->decide('approve', $excuse)->assertSessionHasErrors('excuse');
        $this->actingAs($this->dean)->get(route('dean.excuses.show', $excuse))
            ->assertInertia(fn (Assert $page) => $page->where('lock', $this->term->label.' is closed, so excuses covering its days can no longer be changed.'));
    }

    public function test_excuses_and_their_files_are_the_learners_and_their_deans()
    {
        $excuse = $this->excuse();
        $document = AbsenceExcuseDocument::factory()->for($excuse)->create(['path' => 'absence-excuses/x/file.pdf', 'original_name' => 'file.pdf']);
        Storage::put('absence-excuses/x/file.pdf', '%PDF-1.4');

        $this->actingAs($this->learner->user)->get(route('excuses.documents.show', $document))->assertOk();
        $this->actingAs($this->dean)->get(route('excuses.documents.show', $document))->assertOk();
        $this->actingAs($this->dean)->get(route('dean.excuses.show', $excuse))->assertOk();

        $this->actingAs(Learner::factory()->create()->user)->get(route('excuses.documents.show', $document))->assertForbidden();
        $this->actingAs(User::factory()->dean(College::factory()->create())->create());
        $this->get(route('dean.excuses.show', $excuse))->assertForbidden();
        $this->post(route('dean.excuses.approve', $excuse))->assertForbidden();
        $this->actingAs($this->teacher->user)->get(route('excuses.documents.show', $document))->assertForbidden();

        $this->actingAs($this->dean)->get(route('dean.excuses.index'))
            ->assertInertia(fn (Assert $page) => $page->component('dean/excuses/Index')->has('excuses.data', 1)->where('counts.pending', 1));
    }

    public function test_the_dean_sees_the_absences_an_excuse_covers()
    {
        $this->mark(0, '2026-09-21', 'absent');
        $this->mark(1, '2026-09-21', 'late');

        $this->actingAs($this->dean)->get(route('dean.excuses.show', $this->excuse()))
            ->assertInertia(fn (Assert $page) => $page
                ->component('dean/excuses/Show')
                ->has('absences', 1)
                ->where('absences.0.excuse', 'none')
                ->where('lates', 1)
                ->where('lock', null));

        $this->assertSame(AttendanceStatus::Late, AttendanceRecord::where('enrollment_id', $this->enrollments[1]->id)->sole()->status);
    }
}
