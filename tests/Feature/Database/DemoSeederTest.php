<?php

namespace Tests\Feature\Database;

use App\Attendance\ClassDays;
use App\Attendance\Excuses;
use App\Attendance\Standing;
use App\Enums\DecisionStatus;
use App\Enums\DropReason;
use App\Enums\EnrollmentStatus;
use App\Enums\ExcuseStatus;
use App\Enums\UserRole;
use App\Models\AbsenceExcuse;
use App\Models\AttendanceRecord;
use App\Models\CalendarEvent;
use App\Models\Enrollment;
use App\Models\ExcuseDecision;
use App\Models\Learner;
use App\Models\Schedule;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use App\Notifications\AttendanceNotice;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\ExcuseSeeder;
use Database\Seeders\Demo\TermSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * The demo data must be data the real app could produce. One seeded
 * database is shared by the assertions, since seeding takes a few seconds.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_data_is_consistent()
    {
        Storage::fake('local');
        $this->travelTo('2026-10-19 08:00');
        $this->seed(DatabaseSeeder::class);

        // Fixed logins, one per role and college.
        foreach (['admin', 'registrar', 'dean.cos', 'teacher.coe', 'learner.coeng', 'atrisk.cos', 'irregular.coe', 'returnee.coeng'] as $login) {
            $this->assertTrue(User::where('email', "{$login}@scheduler.test")->exists(), $login);
        }
        $this->assertSame(1, User::where('role', UserRole::Admin)->count());

        // No section or learner is in two classes at once.
        $this->assertSame(0, DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS n FROM schedules a
            JOIN schedules b ON a.id < b.id
            JOIN subject_offerings oa ON oa.id = a.subject_offering_id
            JOIN subject_offerings ob ON ob.id = b.subject_offering_id
            WHERE oa.section_id = ob.section_id AND a.starts_at < b.ends_at AND b.starts_at < a.ends_at
            SQL)->n);
        $this->assertSame(0, DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS n FROM enrollments e1
            JOIN enrollments e2 ON e1.learner_id = e2.learner_id AND e1.id < e2.id
            JOIN schedules a ON a.subject_offering_id = e1.subject_offering_id AND (a.batch_id IS NULL OR a.batch_id = e1.batch_id)
            JOIN schedules b ON b.subject_offering_id = e2.subject_offering_id AND (b.batch_id IS NULL OR b.batch_id = e2.batch_id)
            WHERE a.starts_at < b.ends_at AND b.starts_at < a.ends_at
            SQL)->n);

        // Everything a learner takes is in their own college.
        $this->assertSame(0, DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS n FROM enrollments e
            JOIN learners l ON l.id = e.learner_id
            JOIN users u ON u.id = l.user_id
            JOIN subject_offerings o ON o.id = e.subject_offering_id
            JOIN subjects s ON s.id = o.subject_id
            WHERE s.college_id <> u.college_id
            SQL)->n);

        // Marks only up to yesterday, on the schedule's weekday, and never on
        // a suspended meeting, except the partial-day suspension's: a couple
        // of classes were marked before it was entered (one absent, one late).
        $this->assertSame('2026-10-17', DB::table('attendance_records')->max('class_date'));
        $this->assertSame(0, DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS n FROM attendance_records r JOIN schedules s ON s.id = r.schedule_id
            WHERE WEEKDAY(r.class_date) <> WEEKDAY(s.starts_at)
            SQL)->n);
        $days = app(ClassDays::class);
        $suspendedMarks = AttendanceRecord::with(['schedule.term', 'schedule.subjectOffering.section.program'])->get()
            ->filter(fn (AttendanceRecord $record): bool => $days->suspension($record->schedule, $record->class_date) !== null);
        $partial = CalendarEvent::whereNotNull('suspends_from')->sole();
        $this->assertSame(['Science Fair awarding ceremony'], $suspendedMarks->map(fn (AttendanceRecord $record) => $days->suspension($record->schedule, $record->class_date))->unique()->values()->all());
        $this->assertTrue($suspendedMarks->every(fn (AttendanceRecord $record): bool => $record->created_at < $partial->created_at));
        $this->assertSame(2, $suspendedMarks->pluck('schedule_id')->unique()->count());
        $this->assertSame(['absent' => 2, 'late' => 2], $suspendedMarks->countBy(fn (AttendanceRecord $record): string => $record->status->value)->sortKeys()->all());
        Storage::disk('local')->assertExists((string) $partial->memo_path);
        $this->assertStringStartsWith(TermSeeder::MEMO_DIRECTORY.'/', (string) $partial->memo_path);
        $this->assertSame('COS Memo No. 2026-031', $partial->memo_number);

        // A suspension narrowed to BSCE 4th years: their classes that day are
        // off and unmarked, while the rest of the college met and was marked.
        $narrowed = CalendarEvent::with('programs')->where('title', 'BSCE 4th-year field trip')->sole();
        $this->assertSame(['BSCE'], $narrowed->programs->pluck('code')->all());
        $this->assertSame([4], $narrowed->year_levels);
        $marksThatDay = fn (bool $inScope) => AttendanceRecord::where('class_date', $narrowed->starts_on->toDateString())
            ->whereHas('schedule.subjectOffering.section', fn ($section) => $section
                ->whereHas('program', fn ($program) => $program->where('college_id', $narrowed->college_id))
                ->where(fn ($match) => $inScope
                    ? $match->where('year_level', 4)->whereHas('program', fn ($program) => $program->where('code', 'BSCE'))
                    : $match->where('year_level', '!=', 4)->orWhereHas('program', fn ($program) => $program->where('code', '!=', 'BSCE'))))
            ->count();
        $this->assertGreaterThan(0, Schedule::whereRaw('WEEKDAY(starts_at) = ?', [$narrowed->starts_on->dayOfWeekIso - 1])
            ->whereHas('subjectOffering.section', fn ($section) => $section->where('year_level', 4)->whereHas('program', fn ($program) => $program->where('code', 'BSCE')))
            ->count());
        $this->assertSame(0, $marksThatDay(true));
        $this->assertGreaterThan(0, $marksThatDay(false));

        // Excuses are decided per class by whoever the app lets decide, and
        // each excuse's status summarizes its classes. An absence points at
        // an excuse only where that class's decision is approved, and every
        // such absence does.
        $excuses = app(Excuses::class);

        foreach (ExcuseDecision::with('absenceExcuse')->whereNotNull('decided_by')->get() as $decision) {
            $this->assertTrue($excuses->canDecide(User::findOrFail($decision->decided_by), $decision), "decision {$decision->id}");
        }

        foreach (AbsenceExcuse::all() as $excuse) {
            $status = $excuse->status;
            $excuses->summarize($excuse);
            $this->assertSame($status, $excuse->refresh()->status, "excuse {$excuse->id}");
        }

        $this->assertTrue(AbsenceExcuse::where('status', ExcuseStatus::Partial)
            ->whereHas('decisions', fn ($decision) => $decision->where('status', DecisionStatus::Approved))
            ->whereHas('decisions', fn ($decision) => $decision->where('status', DecisionStatus::Declined))
            ->exists());
        $this->assertTrue(AbsenceExcuse::whereHas('submitter', fn ($user) => $user->where('role', UserRole::Teacher))->exists());
        $this->assertTrue(ExcuseDecision::where('status', DecisionStatus::Revoked)->exists());

        $approvedIn = fn (string $operator) => DB::table('attendance_records')
            ->join('enrollments', 'enrollments.id', '=', 'attendance_records.enrollment_id')
            ->join('absence_excuses', fn ($join) => $join->on('absence_excuses.learner_id', '=', 'enrollments.learner_id')
                ->whereColumn('attendance_records.class_date', '>=', 'absence_excuses.date_from')
                ->whereColumn('attendance_records.class_date', '<=', 'absence_excuses.date_to'))
            ->join('excuse_decisions', fn ($join) => $join->on('excuse_decisions.absence_excuse_id', '=', 'absence_excuses.id')
                ->whereColumn('excuse_decisions.subject_offering_id', 'enrollments.subject_offering_id'))
            ->where('attendance_records.status', 'absent')
            ->where('excuse_decisions.status', $operator, 'approved');
        $this->assertSame(0, (clone $approvedIn('='))->where(fn ($query) => $query->whereNull('attendance_records.absence_excuse_id')->orWhereColumn('attendance_records.absence_excuse_id', '!=', 'absence_excuses.id'))->count());
        $this->assertSame(0, (clone $approvedIn('!='))->whereColumn('attendance_records.absence_excuse_id', 'absence_excuses.id')->count());
        $this->assertSame(DB::table('attendance_records')->whereNotNull('absence_excuse_id')->count(), (clone $approvedIn('='))->count());

        // Every mark has its first audit.
        $this->assertSame(DB::table('attendance_records')->count(), DB::table('attendance_audits')->whereNull('old_status')->count());
        Storage::disk('local')->assertExists(DB::table('absence_excuse_documents')->value('path') ?? '');
        $this->assertStringStartsWith(ExcuseSeeder::DOCUMENT_DIRECTORY, (string) DB::table('absence_excuse_documents')->value('path'));

        // Only exceptions are written: a present record exists only where a
        // mark was corrected back to present, by the teacher or the dean.
        $correctedToPresent = DB::table('attendance_records')
            ->join('attendance_audits', 'attendance_audits.attendance_record_id', '=', 'attendance_records.id')
            ->join('users', 'users.id', '=', 'attendance_audits.changed_by')
            ->where('attendance_records.status', 'present')
            ->where('attendance_audits.old_status', 'absent')
            ->where('attendance_audits.new_status', 'present')
            ->pluck('users.role');
        $this->assertSame(DB::table('attendance_records')->where('status', 'present')->count(), DB::table('attendance_records')
            ->where('status', 'present')
            ->whereExists(fn ($audit) => $audit->from('attendance_audits')->whereColumn('attendance_record_id', 'attendance_records.id')->whereNotNull('old_status')->where('new_status', 'present'))
            ->count());
        $this->assertContains('teacher', $correctedToPresent);
        $this->assertContains('dean', $correctedToPresent);
        $this->assertSame(0, DB::table('attendance_records')
            ->leftJoin('attendance_audits', fn ($join) => $join->on('attendance_audits.attendance_record_id', '=', 'attendance_records.id')->whereNull('attendance_audits.old_status'))
            ->where(fn ($query) => $query->whereNull('attendance_audits.id')
                ->orWhereColumn('attendance_audits.changed_by', '!=', 'attendance_records.recorded_by')
                ->orWhereColumn('attendance_audits.changed_at', '!=', 'attendance_records.created_at'))
            ->count());

        // Every drop has its reason; the deans dismissed (one per college),
        // confirmed and reinstated through the app.
        $this->assertSame(0, Enrollment::where('status', EnrollmentStatus::Dropped)->whereNull('drop_reason')->count());
        $this->assertEqualsCanonicalizing(['absences', 'removed', 'withdrawn'], Enrollment::whereNotNull('drop_reason')->distinct()->pluck('drop_reason')->map(fn (DropReason $reason) => $reason->value)->all());
        $this->assertSame(3, Enrollment::where('status', EnrollmentStatus::Enrolled)->whereNotNull('drop_dismissed_at')->whereNull('reinstated_at')->distinct()->count('drop_dismissed_by'));
        $this->assertSame(1, Enrollment::whereNotNull('reinstated_at')->whereNull('drop_reason')->whereNull('drop_confirmed_at')->count());

        // Standing agrees with the app's own counts, under the term's policy,
        // for every warned or flagged class and a sample of the rest.
        $standing = app(Standing::class);
        $sample = Enrollment::where('warning_level', '>', 0)
            ->orWhere('status', EnrollmentStatus::ForDropping)
            ->orWhereIn('id', AttendanceRecord::where('status', '!=', 'present')->orderBy('id')->limit(200)->pluck('enrollment_id'))
            ->get();
        $this->assertGreaterThan(50, $sample->count());

        foreach ($sample as $enrollment) {
            $equivalent = $standing->counts($enrollment)['equivalent'];
            $held = $enrollment->drop_dismissed_absences !== null && $equivalent <= $enrollment->drop_dismissed_absences;

            if ($enrollment->status === EnrollmentStatus::Dropped) {
                continue;
            }

            $this->assertSame($equivalent >= 3 ? min($equivalent, 4) : 0, $enrollment->warning_level, "enrollment {$enrollment->id}");
            $this->assertSame($equivalent >= 5 && ! $held ? EnrollmentStatus::ForDropping : EnrollmentStatus::Enrolled, $enrollment->status, "enrollment {$enrollment->id}");
            $this->assertSame($enrollment->status === EnrollmentStatus::ForDropping, $enrollment->flagged_for_drop_at !== null);
        }

        // Notices in the header bell for the demo logins, dated in the past and mostly unread.
        foreach (['cos', 'coe', 'coeng'] as $college) {
            $kinds = fn (string $login) => User::where('email', "{$login}.{$college}@scheduler.test")->sole()->notifications->map(fn ($notice) => $notice->data['kind']);
            $this->assertContains(AttendanceNotice::WARNING, $kinds('learner'));
            $this->assertContains(AttendanceNotice::FLAGGED, $kinds('atrisk'));
            $this->assertContains(AttendanceNotice::LEARNER_FLAGGED, $kinds('dean'));
            $this->assertContains(AttendanceNotice::EXCUSE_APPROVED, $kinds('learner'));
        }
        $this->assertSame(0, DB::table('notifications')->where('created_at', '>', now())->count());
        foreach ([AttendanceNotice::EXCUSE_TO_DECIDE, AttendanceNotice::EXCUSE_DECLINED, AttendanceNotice::EXCUSE_REVOKED] as $kind) {
            $this->assertTrue(DB::table('notifications')->where('data->kind', $kind)->exists(), $kind);
        }
        $this->assertGreaterThan(DB::table('notifications')->count() / 2, DB::table('notifications')->whereNull('read_at')->count());
        $this->assertSame(Enrollment::where('drop_reason', 'absences')->count() + 1, DB::table('notifications')->whereIn('data->kind', ['dropped'])->count());

        $this->assertSame(EnrollmentStatus::ForDropping->value, DB::table('enrollments')
            ->join('learners', 'learners.id', '=', 'enrollments.learner_id')
            ->join('users', 'users.id', '=', 'learners.user_id')
            ->where('users.email', 'atrisk.cos@scheduler.test')
            ->orderBy('enrollments.id')
            ->value('enrollments.status'));

        // Withdrawals match the app: every class dropped, confirmed by the registrar.
        $this->assertSame(0, DB::table('term_registrations')
            ->join('enrollments', 'enrollments.learner_id', '=', 'term_registrations.learner_id')
            ->leftJoin('users', 'users.id', '=', 'enrollments.drop_confirmed_by')
            ->where('term_registrations.status', 'withdrawn')
            ->where(fn ($query) => $query->where('enrollments.status', '!=', 'dropped')->orWhere('users.role', '!=', 'registrar'))
            ->count());

        $this->assertDemoRegistration();
    }

    /**
     * The registrar opens on the upcoming 2nd semester: this term's learners
     * carried over (pre-ticked unless withdrawn), the returnees under
     * "others", and registering keeps a learner's block together.
     */
    private function assertDemoRegistration(): void
    {
        $current = Term::where(['school_year' => '2026-2027', 'semester' => 'first'])->sole();
        $next = Term::where(['school_year' => '2026-2027', 'semester' => 'second'])->sole();
        $this->actingAs(User::where('email', 'registrar@scheduler.test')->sole());

        // Last school year is on record one year level lower.
        $this->assertSame(0, TermRegistration::query()
            ->join('terms', 'terms.id', '=', 'term_registrations.term_id')
            ->where('terms.school_year', '2025-2026')
            ->whereNotIn('learner_id', TermRegistration::where('term_id', $current->id)->where('year_level', '>=', 2)->select('learner_id'))
            ->count());

        $this->get(route('registrar.registrations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('term.id', $next->id)
                ->where('candidates.total', TermRegistration::where('term_id', $current->id)->count())
                ->where('candidates.data', fn ($rows) => collect($rows)->every(fn (array $row): bool => $row['ticked'] === ($row['previous']['status'] === 'registered'))));

        $this->get(route('registrar.registrations.index', ['source' => 'others']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.total', 3)
                ->where('candidates.data', fn ($rows) => collect($rows)->every(fn (array $row): bool => str_starts_with($row['learner']['user']['email'], 'returnee.'))));

        $learner = Learner::whereHas('user', fn ($user) => $user->where('email', 'learner.cos@scheduler.test'))->sole();
        $this->post(route('registrar.registrations.store', $next), ['entries' => [['learner_id' => $learner->id, 'year_level' => 1, 'is_irregular' => false]]])
            ->assertSessionHasNoErrors();

        $sectionName = fn (Term $term): ?string => TermRegistration::with('section')->where(['learner_id' => $learner->id, 'term_id' => $term->id])->sole()->section?->name;
        $this->assertSame($sectionName($current), $sectionName($next));
    }

    public function test_it_refuses_a_database_that_already_has_users()
    {
        User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->seed(DatabaseSeeder::class);
    }
}
