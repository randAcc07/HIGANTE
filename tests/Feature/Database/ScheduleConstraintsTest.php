<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the database-level rules the scheduler relies on, so a migration
 * change that drops one fails here instead of in production.
 */
class ScheduleConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private int $termId;

    private int $offeringId;

    private int $enrollmentId;

    /** @var array<string, int> */
    private array $teacherIds = [];

    /** @var array<string, int> */
    private array $roomIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $collegeId = DB::table('colleges')->insertGetId(['code' => 'COE', 'name' => 'College of Engineering']);
        $programId = DB::table('programs')->insertGetId(['college_id' => $collegeId, 'code' => 'BSCE', 'name' => 'BS Civil Engineering']);
        $this->termId = DB::table('terms')->insertGetId(['school_year' => '2026-2027', 'semester' => 'first', 'starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        $sectionId = DB::table('sections')->insertGetId(['term_id' => $this->termId, 'program_id' => $programId, 'year_level' => 2, 'name' => '2A']);
        $courseId = DB::table('courses')->insertGetId(['college_id' => $collegeId, 'code' => 'CE201', 'title' => 'Statics']);

        foreach (['a', 'b'] as $key) {
            $userId = DB::table('users')->insertGetId([
                'role' => 'teacher', 'college_id' => $collegeId, 'name' => "Teacher {$key}",
                'email' => "teacher-{$key}@example.com", 'password' => 'x',
            ]);
            $this->teacherIds[$key] = DB::table('teachers')->insertGetId(['user_id' => $userId, 'employee_number' => "EMP-{$key}"]);
            $this->roomIds[$key] = DB::table('rooms')->insertGetId(['code' => "R-{$key}", 'capacity' => 40]);
        }

        $this->offeringId = DB::table('course_offerings')->insertGetId(['section_id' => $sectionId, 'course_id' => $courseId, 'teacher_id' => $this->teacherIds['a']]);

        $learnerUserId = DB::table('users')->insertGetId([
            'role' => 'learner', 'college_id' => $collegeId, 'name' => 'Learner', 'email' => 'learner@example.com', 'password' => 'x',
        ]);
        $learnerId = DB::table('learners')->insertGetId(['user_id' => $learnerUserId, 'program_id' => $programId, 'student_number' => '2026-0001', 'year_level' => 2]);
        $this->enrollmentId = DB::table('enrollments')->insertGetId(['learner_id' => $learnerId, 'course_offering_id' => $this->offeringId]);

        // Monday 08:00-09:30 in room a with teacher a.
        $this->meeting('a', 'a', '2001-01-01 08:00', '2001-01-01 09:30');
    }

    public function test_room_cannot_be_double_booked()
    {
        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uq_offering_meetings_room_slot');

        $this->meeting('b', 'a', '2001-01-01 08:30', '2001-01-01 10:00');
    }

    public function test_teacher_cannot_be_double_booked()
    {
        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uq_offering_meetings_teacher_slot');

        $this->meeting('a', 'b', '2001-01-01 08:30', '2001-01-01 10:00');
    }

    public function test_moving_a_meeting_into_an_overlap_is_rejected()
    {
        $id = $this->meeting('a', 'a', '2001-01-01 10:00', '2001-01-01 11:00');

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('offering_meetings')->where('id', $id)->update(['starts_at' => '2001-01-01 09:00']);
    }

    public function test_back_to_back_and_other_day_meetings_are_allowed()
    {
        $this->meeting('a', 'a', '2001-01-01 09:30', '2001-01-01 11:00');
        $this->meeting('a', 'a', '2001-01-02 08:00', '2001-01-02 09:30');

        $this->assertSame(3, DB::table('offering_meetings')->count());
    }

    public function test_online_meetings_need_no_room_but_onsite_ones_do()
    {
        $this->meeting('b', null, '2001-01-01 08:00', '2001-01-01 09:30', 'online');
        $this->assertSame(2, DB::table('offering_meetings')->count());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('chk_offering_meetings_room');

        $this->meeting('b', null, '2001-01-03 08:00', '2001-01-03 09:30');
    }

    public function test_meeting_must_stay_inside_the_reference_week_and_one_day()
    {
        foreach ([
            ['2026-10-12 08:00', '2026-10-12 09:00', 'chk_offering_meetings_reference_week'],
            ['2001-01-01 23:00', '2001-01-02 01:00', 'chk_offering_meetings_same_day'],
            ['2001-01-04 10:00', '2001-01-04 09:00', 'slot'],
        ] as [$startsAt, $endsAt, $constraint]) {
            try {
                $this->meeting('b', 'b', $startsAt, $endsAt);
                $this->fail("Meeting {$startsAt} to {$endsAt} was accepted.");
            } catch (QueryException $e) {
                $this->assertStringContainsString($constraint, $e->getMessage());
            }
        }
    }

    public function test_only_absences_can_carry_an_excuse()
    {
        $meetingId = DB::table('offering_meetings')->value('id');
        $excuseId = DB::table('absence_excuses')->insertGetId([
            'learner_id' => DB::table('learners')->value('id'),
            'date_from' => '2026-10-12', 'date_to' => '2026-10-12', 'reason' => 'medical', 'status' => 'approved',
        ]);

        DB::table('attendance_records')->insert([
            'enrollment_id' => $this->enrollmentId, 'offering_meeting_id' => $meetingId,
            'class_date' => '2026-10-12', 'status' => 'absent', 'absence_excuse_id' => $excuseId,
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('chk_attendance_records_excuse');

        DB::table('attendance_records')->insert([
            'enrollment_id' => $this->enrollmentId, 'offering_meeting_id' => $meetingId,
            'class_date' => '2026-10-19', 'status' => 'late', 'absence_excuse_id' => $excuseId,
        ]);
    }

    private function meeting(string $teacher, ?string $room, string $startsAt, string $endsAt, string $mode = 'onsite'): int
    {
        return DB::table('offering_meetings')->insertGetId([
            'course_offering_id' => $this->offeringId,
            'term_id' => $this->termId,
            'teacher_id' => $this->teacherIds[$teacher],
            'room_id' => $room === null ? null : $this->roomIds[$room],
            'mode' => $mode,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }
}
