<?php

namespace Tests\Feature\Models;

use App\Enums\UserRole;
use App\Models\AbsenceExcuseDocument;
use App\Models\AttendanceAudit;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\Batch;
use App\Models\CalendarEvent;
use App\Models\Enrollment;
use App\Models\Learner;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\TermRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Factories must build data the real app could produce: everything in one
 * college, schedules in their section's term, marks on a real class day.
 */
class FactoryConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_factory_creates_a_valid_row()
    {
        foreach ([
            AbsenceExcuseDocument::class, AttendanceAudit::class, AttendancePolicy::class,
            Batch::class, CalendarEvent::class, TermRegistration::class,
        ] as $model) {
            $this->assertTrue($model::factory()->create()->exists, $model);
        }
    }

    public function test_teachers_and_learners_get_matching_users()
    {
        $teacher = Teacher::factory()->create();
        $this->assertSame(UserRole::Teacher, $teacher->user->role);
        $this->assertNotNull($teacher->user->college_id);

        $learner = Learner::factory()->create();
        $this->assertSame(UserRole::Learner, $learner->user->role);
        $this->assertSame($learner->program->college_id, $learner->user->college_id);
    }

    public function test_an_enrollment_stays_inside_one_college()
    {
        $enrollment = Enrollment::factory()->create();
        $offering = $enrollment->subjectOffering;
        $collegeId = $offering->section->program->college_id;

        $this->assertSame($collegeId, $offering->subject->college_id);
        $this->assertSame($collegeId, $offering->teacher?->user->college_id);
        $this->assertSame($offering->section->program_id, $enrollment->learner->program_id);
    }

    public function test_a_schedule_uses_its_sections_term_and_college()
    {
        $schedule = Schedule::factory()->create();
        $section = $schedule->subjectOffering->section;

        $this->assertSame($section->term_id, $schedule->term_id);
        $this->assertSame($section->program->college_id, $schedule->teacher->user->college_id);
        $this->assertTrue($schedule->starts_at->isSameDay($schedule->ends_at));
    }

    public function test_an_attendance_record_falls_on_its_schedules_weekday_inside_the_term()
    {
        $record = AttendanceRecord::factory()->create();
        $schedule = $record->schedule;

        $this->assertSame($schedule->weekday(), $record->class_date->dayOfWeekIso);
        $this->assertTrue($record->class_date->between($schedule->term->starts_on, $schedule->term->ends_on));
        $this->assertSame($record->enrollment->subject_offering_id, $schedule->subject_offering_id);
    }
}
