<?php

namespace Tests\Feature\Dean;

use App\Models\College;
use App\Models\Program;
use App\Models\Room;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A college with a dean, a program, an upcoming term, a section, two
 * teachers, two rooms and one subject offered to the section.
 */
abstract class SchedulingTestCase extends TestCase
{
    use RefreshDatabase;

    protected College $college;

    protected User $dean;

    protected Program $program;

    protected Term $term;

    protected Section $section;

    protected Teacher $teacher;

    protected Teacher $otherTeacher;

    protected Room $room;

    protected Room $otherRoom;

    protected SubjectOffering $offering;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-01');
        $this->college = College::factory()->create(['code' => 'COS']);
        $this->dean = User::factory()->dean($this->college)->create();
        $this->program = Program::factory()->for($this->college)->create(['code' => 'BSCS']);
        $this->term = Term::factory()->create(['starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        $this->section = Section::factory()->for($this->term)->for($this->program)->create(['year_level' => 1, 'name' => '1-A']);
        $this->teacher = Teacher::factory()->forCollege($this->college)->create();
        $this->otherTeacher = Teacher::factory()->forCollege($this->college)->create();
        $this->room = Room::factory()->create(['code' => 'MB-101', 'capacity' => 40]);
        $this->otherRoom = Room::factory()->create(['code' => 'MB-102', 'capacity' => 40]);
        $this->offering = SubjectOffering::factory()->for($this->section)->create([
            'subject_id' => Subject::factory()->for($this->college)->create(['code' => 'CS 101'])->id,
            'teacher_id' => $this->teacher->id,
        ]);

        $this->actingAs($this->dean);
    }

    /**
     * Form data for a class of the offering.
     *
     * @return array<string, mixed>
     */
    protected function classData(array $override = []): array
    {
        return [
            'batch_id' => null,
            'weekday' => 1,
            'start' => '08:00',
            'end' => '09:30',
            'mode' => 'onsite',
            'room_id' => $this->room->id,
            'teacher_id' => $this->teacher->id,
            ...$override,
        ];
    }

    protected function anotherOffering(string $code = 'CS 102', ?Section $section = null): SubjectOffering
    {
        return SubjectOffering::factory()->for($section ?? $this->section)->create([
            'subject_id' => Subject::factory()->for($this->college)->create(['code' => $code])->id,
            'teacher_id' => $this->otherTeacher->id,
        ]);
    }
}
