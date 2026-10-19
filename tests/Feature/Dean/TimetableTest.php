<?php

namespace Tests\Feature\Dean;

use App\Models\College;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\Teacher;
use Inertia\Testing\AssertableInertia as Assert;

class TimetableTest extends SchedulingTestCase
{
    public function test_a_rooms_week_shows_other_colleges_only_as_booked()
    {
        Schedule::factory()->for($this->offering)->slot(1, '08:00', '09:30')->create(['room_id' => $this->room->id, 'teacher_id' => $this->teacher->id]);
        $engineering = College::factory()->create(['code' => 'COEng']);
        $theirs = SubjectOffering::factory()->for(Section::factory()->for($this->term)->for(Program::factory()->for($engineering)->create())->create())->create();
        Schedule::factory()->for($theirs)->slot(2, '10:00', '11:00')->create(['room_id' => $this->room->id]);

        $this->get(route('dean.timetables.index', ['room' => $this->room->id]))->assertInertia(fn (Assert $page) => $page
            ->component('dean/timetables/Index')
            ->has('classes', 2)
            ->where('classes.0.subject', 'CS 101')
            ->where('classes.0.section', 'BSCS 1-A')
            ->where('classes.1.busy', 'COEng')
            ->missing('classes.1.subject')
            ->missing('classes.1.teacher'));
    }

    public function test_a_teachers_week_is_limited_to_the_colleges_teachers()
    {
        Schedule::factory()->for($this->offering)->slot(4, '13:00', '14:30')->create(['teacher_id' => $this->teacher->id]);

        $this->get(route('dean.timetables.index', ['teacher' => $this->teacher->id]))
            ->assertInertia(fn (Assert $page) => $page->has('classes', 1)->where('classes.0.weekday', 4));

        $this->get(route('dean.timetables.index', ['teacher' => Teacher::factory()->create()->id]))->assertSessionHasErrors('teacher');
    }
}
