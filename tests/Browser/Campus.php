<?php

namespace Tests\Browser;

use App\Enums\Semester;
use App\Models\Batch;
use App\Models\College;
use App\Models\Enrollment;
use App\Models\Learner;
use App\Models\Program;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A small campus in session for the browser tests: one college with a
 * dean, a program, a term in session, section 1-A with a lecture subject and
 * a lab split into 1-AA / 1-AB, and four registered learners (one waiting
 * for a section). Every page then has something to draw.
 */
final class Campus
{
    public College $college;

    public Program $program;

    public Term $term;

    public Section $section;

    public SubjectOffering $lecture;

    public SubjectOffering $lab;

    public Room $room;

    public Room $lab_room;

    public Teacher $teacher;

    /** @var array<string, User> */
    public array $users = [];

    /** @var list<TermRegistration> */
    public array $registrations = [];

    public static function build(): self
    {
        $campus = new self;
        $today = CarbonImmutable::today();

        $campus->college = College::factory()->create(['code' => 'COS', 'name' => 'College of Science']);
        $campus->program = Program::factory()->for($campus->college)->create(['code' => 'BSCS', 'name' => 'BS Computer Science']);
        $campus->term = Term::factory()->create([
            'school_year' => $today->year.'-'.($today->year + 1),
            'semester' => Semester::First,
            'starts_on' => $today->subMonth(),
            'ends_on' => $today->addMonths(3),
        ]);
        $campus->section = Section::factory()->for($campus->term)->for($campus->program)->create(['year_level' => 1, 'name' => '1-A', 'capacity' => 40]);
        $campus->teacher = Teacher::factory()->forCollege($campus->college)->create();
        $campus->room = Room::factory()->create(['code' => 'MB-101', 'capacity' => 40]);
        $campus->lab_room = Room::factory()->create(['code' => 'LAB-1', 'capacity' => 20]);

        $campus->lecture = SubjectOffering::factory()->for($campus->section)->create([
            'subject_id' => Subject::factory()->for($campus->college)->create(['code' => 'CS 101', 'title' => 'Introduction to Computing'])->id,
            'teacher_id' => $campus->teacher->id,
        ]);
        $campus->lab = SubjectOffering::factory()->for($campus->section)->create([
            'subject_id' => Subject::factory()->for($campus->college)->create(['code' => 'CS 102', 'title' => 'Programming 1'])->id,
            'teacher_id' => $campus->teacher->id,
        ]);
        $batchA = Batch::factory()->for($campus->lab)->create(['name' => '1-AA']);
        $batchB = Batch::factory()->for($campus->lab)->create(['name' => '1-AB']);

        Schedule::factory()->for($campus->lecture)->slot(1, '08:00', '09:30')->create(['teacher_id' => $campus->teacher->id, 'room_id' => $campus->room->id]);
        Schedule::factory()->for($campus->lab)->for($batchA)->slot(2, '08:00', '11:00')->create(['teacher_id' => $campus->teacher->id, 'room_id' => $campus->lab_room->id]);
        Schedule::factory()->for($campus->lab)->for($batchB)->slot(3, '08:00', '11:00')->create(['teacher_id' => $campus->teacher->id, 'room_id' => $campus->lab_room->id]);

        $campus->users = [
            'admin' => User::factory()->admin()->create(),
            'registrar' => User::factory()->registrar()->create(),
            'dean' => User::factory()->dean($campus->college)->create(),
            'teacher' => $campus->teacher->user,
        ];

        foreach (['Abad', 'Bautista', 'Cruz', 'Dizon'] as $i => $lastName) {
            $learner = Learner::factory()->for($campus->program)->create(['year_level' => 1]);
            $learner->user->update(['last_name' => $lastName]);
            $sectioned = $i < 3;

            $campus->registrations[] = TermRegistration::factory()->for($learner)->for($campus->term)->create([
                'year_level' => 1,
                'section_id' => $sectioned ? $campus->section->id : null,
            ]);

            if ($sectioned) {
                Enrollment::factory()->for($campus->lecture)->create(['learner_id' => $learner->id]);
                Enrollment::factory()->for($campus->lab)->create(['learner_id' => $learner->id]);
            }
        }

        $campus->users['learner'] = $campus->registrations[0]->learner->user;

        return $campus;
    }
}
