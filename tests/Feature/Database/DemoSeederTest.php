<?php

namespace Tests\Feature\Database;

use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Models\Learner;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\ExcuseSeeder;
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

        // Marks only up to yesterday, on the schedule's weekday, never on a day
        // the learner's classes were suspended (campus-wide or by their
        // college, on any day of the event).
        $this->assertSame('2026-10-17', DB::table('attendance_records')->max('class_date'));
        $this->assertSame(0, DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS n FROM attendance_records r JOIN schedules s ON s.id = r.schedule_id
            WHERE WEEKDAY(r.class_date) <> WEEKDAY(s.starts_at)
            SQL)->n);
        $this->assertSame(0, DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS n FROM attendance_records r
            JOIN enrollments e ON e.id = r.enrollment_id
            JOIN learners l ON l.id = e.learner_id
            JOIN users u ON u.id = l.user_id
            JOIN calendar_events ev ON ev.suspends_classes
                AND (ev.college_id IS NULL OR ev.college_id = u.college_id)
                AND r.class_date BETWEEN ev.starts_on AND ev.ends_on
            SQL)->n);

        // Only approved excuses excuse absences; every mark has its first audit.
        $this->assertSame(0, DB::table('attendance_records')
            ->join('absence_excuses', 'absence_excuses.id', '=', 'attendance_records.absence_excuse_id')
            ->where('absence_excuses.status', '!=', 'approved')
            ->count());
        $this->assertSame(DB::table('attendance_records')->count(), DB::table('attendance_audits')->whereNull('old_status')->count());
        Storage::disk('local')->assertExists(DB::table('absence_excuse_documents')->value('path') ?? '');
        $this->assertStringStartsWith(ExcuseSeeder::DOCUMENT_DIRECTORY, (string) DB::table('absence_excuse_documents')->value('path'));

        // Standing follows the handbook: 3 lates = 1 absence, warn at 3 and 4,
        // flag at 5. Dropped classes (withdrawals and confirmed drops) keep
        // the warnings they reached.
        $standings = DB::select(<<<'SQL'
            SELECT e.status, e.warning_level,
                   SUM(r.status = 'absent' AND r.absence_excuse_id IS NULL) + FLOOR(SUM(r.status = 'late') / 3) AS equivalent
            FROM enrollments e JOIN attendance_records r ON r.enrollment_id = e.id
            GROUP BY e.id, e.status, e.warning_level
            SQL);

        foreach ($standings as $standing) {
            $equivalent = (int) $standing->equivalent;
            $this->assertSame($equivalent >= 3 ? min($equivalent, 4) : 0, (int) $standing->warning_level);

            if ($standing->status !== 'dropped') {
                $this->assertSame($equivalent >= 5 ? 'for_dropping' : 'enrolled', $standing->status);
            }
        }

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
