<?php

namespace Tests\Feature\Dean;

use App\Models\Batch;
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

        $this->get(route('dean.timetables.index', ['view' => 'room', 'target' => $this->room->id]))->assertInertia(fn (Assert $page) => $page
            ->component('dean/timetables/Index')
            ->where('selected.view', 'room')
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

        $this->get(route('dean.timetables.index', ['view' => 'teacher', 'target' => $this->teacher->id]))
            ->assertInertia(fn (Assert $page) => $page->has('classes', 1)->where('classes.0.weekday', 4));

        $this->get(route('dean.timetables.index', ['view' => 'teacher', 'target' => Teacher::factory()->create()->id]))->assertSessionHasErrors('target');
    }

    public function test_a_sections_week_can_be_seen_as_one_batch_sees_it()
    {
        $labs = $this->anotherOffering('CS 102');
        $aa = Batch::factory()->for($labs)->create(['name' => '1-AA']);
        $ab = Batch::factory()->for($labs)->create(['name' => '1-AB']);
        Schedule::factory()->for($this->offering)->slot(1, '08:00', '09:30')->create();
        Schedule::factory()->for($labs)->slot(2, '08:00', '11:00')->create(['batch_id' => $aa->id]);
        Schedule::factory()->for($labs)->slot(3, '08:00', '11:00')->create(['batch_id' => $ab->id]);

        $this->get(route('dean.timetables.index', ['view' => 'section', 'target' => $this->section->id]))
            ->assertInertia(fn (Assert $page) => $page->has('sections', 1)->where('batches', ['1-AA', '1-AB'])->has('classes', 3));

        $this->get(route('dean.timetables.index', ['view' => 'section', 'target' => $this->section->id, 'batch' => '1-AA']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.batch', '1-AA')
                ->has('classes', 2)
                ->where('classes.0.subject', 'CS 101')
                ->where('classes.1.batch', '1-AA'));
    }

    public function test_only_the_colleges_sections_can_be_viewed()
    {
        $theirs = Section::factory()->for($this->term)->create();

        $this->get(route('dean.timetables.index', ['view' => 'section', 'target' => $theirs->id]))->assertSessionHasErrors('target');
    }

    public function test_the_timetable_offers_the_colleges_subjects_for_scheduling()
    {
        $this->anotherOffering('CS 102', Section::factory()->for($this->term)->for($this->program)->create(['name' => '1-B']));
        SubjectOffering::factory()->for(Section::factory()->for($this->term)->create())->create(); // another college's

        $this->get(route('dean.timetables.index', ['view' => 'room', 'target' => $this->room->id]))->assertInertia(fn (Assert $page) => $page
            ->where('canEdit', true)
            ->has('offerings', 2)
            ->has('sections', 2)
            ->has('rooms.0.capacity'));
    }

    public function test_a_closed_terms_timetable_is_read_only()
    {
        $this->term->forceFill(['starts_on' => '2026-01-12', 'ends_on' => '2026-05-22', 'closed_at' => now()])->save();

        $this->get(route('dean.timetables.index', ['term' => $this->term->id, 'view' => 'section', 'target' => $this->section->id]))
            ->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
    }
}
