<?php

namespace Tests\Feature\Database;

use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\ExcuseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        foreach (['admin', 'registrar', 'dean.cos', 'teacher.coe', 'learner.coeng', 'atrisk.cos', 'irregular.coe'] as $login) {
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

        // Marks only up to yesterday, on the schedule's weekday, never on a campus holiday.
        $this->assertSame('2026-10-17', DB::table('attendance_records')->max('class_date'));
        $this->assertSame(0, DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS n FROM attendance_records r JOIN schedules s ON s.id = r.schedule_id
            WHERE WEEKDAY(r.class_date) <> WEEKDAY(s.starts_at)
               OR r.class_date IN (SELECT starts_on FROM calendar_events WHERE college_id IS NULL AND suspends_classes)
            SQL)->n);

        // Only approved excuses excuse absences; every mark has its first audit.
        $this->assertSame(0, DB::table('attendance_records')
            ->join('absence_excuses', 'absence_excuses.id', '=', 'attendance_records.absence_excuse_id')
            ->where('absence_excuses.status', '!=', 'approved')
            ->count());
        $this->assertSame(DB::table('attendance_records')->count(), DB::table('attendance_audits')->whereNull('old_status')->count());
        Storage::disk('local')->assertExists(DB::table('absence_excuse_documents')->value('path') ?? '');
        $this->assertStringStartsWith(ExcuseSeeder::DOCUMENT_DIRECTORY, (string) DB::table('absence_excuse_documents')->value('path'));

        // Standing follows the handbook: 3 lates = 1 absence, warn at 3 and 4, flag at 5.
        $standings = DB::select(<<<'SQL'
            SELECT e.status, e.warning_level,
                   SUM(r.status = 'absent' AND r.absence_excuse_id IS NULL) + FLOOR(SUM(r.status = 'late') / 3) AS equivalent
            FROM enrollments e JOIN attendance_records r ON r.enrollment_id = e.id
            WHERE e.status <> 'dropped'
            GROUP BY e.id, e.status, e.warning_level
            SQL);

        foreach ($standings as $standing) {
            $equivalent = (int) $standing->equivalent;
            $this->assertSame($equivalent >= 5 ? 'for_dropping' : 'enrolled', $standing->status);
            $this->assertSame($equivalent >= 5 ? 4 : ($equivalent >= 3 ? min($equivalent, 4) : 0), (int) $standing->warning_level);
        }

        $this->assertSame(EnrollmentStatus::ForDropping->value, DB::table('enrollments')
            ->join('learners', 'learners.id', '=', 'enrollments.learner_id')
            ->join('users', 'users.id', '=', 'learners.user_id')
            ->where('users.email', 'atrisk.cos@scheduler.test')
            ->orderBy('enrollments.id')
            ->value('enrollments.status'));
    }

    public function test_it_refuses_a_database_that_already_has_users()
    {
        User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->seed(DatabaseSeeder::class);
    }
}
