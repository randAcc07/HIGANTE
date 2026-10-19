<?php

namespace Tests\Feature\Dean;

use App\Enums\DutyStatus;
use App\Models\AttendanceRecord;
use App\Models\Batch;
use App\Models\College;
use App\Models\Program;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Teacher;
use App\Models\TermRegistration;
use App\Models\User;

class ScheduleTest extends SchedulingTestCase
{
    public function test_a_class_is_added_on_the_reference_week()
    {
        $this->post(route('dean.schedules.store', $this->offering), $this->classData(['weekday' => 3, 'start' => '13:00', 'end' => '14:30']))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Class added.');

        $class = Schedule::sole();
        $this->assertSame(3, $class->weekday());
        $this->assertSame('2001-01-03 13:00', $class->starts_at->format('Y-m-d H:i'));
        $this->assertSame($this->term->id, $class->term_id);
    }

    public function test_a_room_clash_is_explained_with_the_other_class()
    {
        Schedule::factory()->for($this->anotherOffering('CS 102'))->slot(1, '08:30', '10:00')->create(['room_id' => $this->room->id, 'teacher_id' => $this->otherTeacher->id]);

        $this->post(route('dean.schedules.store', $this->offering), $this->classData())
            ->assertSessionHasErrors(['slot.0' => 'MB-101 is already booked Mon 8:30–10:00 AM by BSCS 1-A · CS 102.']);
    }

    public function test_another_colleges_booking_names_the_college()
    {
        $engineering = College::factory()->create(['code' => 'COEng']);
        $theirSection = Section::factory()->for($this->term)->for(Program::factory()->for($engineering)->create(['code' => 'BSCE']))->create(['name' => '2-A']);
        $theirOffering = SubjectOffering::factory()->for($theirSection)->create(['subject_id' => Subject::factory()->for($engineering)->create(['code' => 'CE 201'])->id]);
        Schedule::factory()->for($theirOffering)->slot(1, '09:00', '10:00')->create(['room_id' => $this->room->id]);

        $this->post(route('dean.schedules.store', $this->offering), $this->classData())
            ->assertSessionHasErrors(['slot.0' => 'MB-101 is already booked Mon 9:00–10:00 AM by COEng BSCE 2-A · CE 201.']);
    }

    public function test_a_teacher_clash_is_explained()
    {
        Schedule::factory()->for($this->anotherOffering('CS 102'))->slot(1, '07:00', '08:30')->create(['teacher_id' => $this->teacher->id, 'room_id' => $this->otherRoom->id]);

        $this->post(route('dean.schedules.store', $this->offering), $this->classData())
            ->assertSessionHasErrors(['slot.0' => "{$this->teacher->user->full_name} already teaches BSCS 1-A · CS 102 Mon 7:00–8:30 AM."]);
    }

    public function test_one_clash_can_have_several_reasons_and_all_are_listed()
    {
        Schedule::factory()->for($this->anotherOffering('CS 102'))->slot(1, '08:00', '09:00')->create(['teacher_id' => $this->teacher->id, 'room_id' => $this->room->id]);

        $this->post(route('dean.schedules.store', $this->offering), $this->classData())
            ->assertSessionHasErrors(['slot.0', 'slot.1', 'slot.2']);
        $this->assertSame(1, Schedule::count());
    }

    public function test_the_section_cannot_be_in_two_classes_but_one_subjects_batches_can_run_together()
    {
        Schedule::factory()->for($this->anotherOffering('CS 102'))->slot(1, '08:00', '09:30')->create(['room_id' => $this->otherRoom->id, 'teacher_id' => $this->otherTeacher->id]);
        $this->post(route('dean.schedules.store', $this->offering), $this->classData())
            ->assertSessionHasErrors(['slot.0' => 'The section already has BSCS 1-A · CS 102 Mon 8:00–9:30 AM.']);

        $a = Batch::factory()->for($this->offering)->create(['name' => '1-AA']);
        $b = Batch::factory()->for($this->offering)->create(['name' => '1-AB']);
        $this->post(route('dean.schedules.store', $this->offering), $this->classData(['weekday' => 2, 'batch_id' => $a->id]))->assertSessionHasNoErrors();
        $this->post(route('dean.schedules.store', $this->offering), $this->classData(['weekday' => 2, 'batch_id' => $b->id, 'room_id' => $this->otherRoom->id, 'teacher_id' => $this->otherTeacher->id]))
            ->assertSessionHasNoErrors();

        // In another subject, the same batch name is the same learners, so it
        // clashes; a different name is another group and may run alongside.
        $labs = $this->anotherOffering('CS 103');
        $sameGroup = Batch::factory()->for($labs)->create(['name' => '1-AA']);
        $otherGroup = Batch::factory()->for($labs)->create(['name' => '1-AC']);
        $third = Teacher::factory()->forCollege($this->college)->create();
        $elsewhere = ['weekday' => 2, 'room_id' => Room::factory()->create()->id, 'teacher_id' => $third->id];

        $this->post(route('dean.schedules.store', $labs), $this->classData([...$elsewhere, 'batch_id' => $sameGroup->id]))
            ->assertSessionHasErrors(['slot.0' => 'The section already has BSCS 1-A · CS 101 (1-AA) Tue 8:00–9:30 AM.']);
        $this->post(route('dean.schedules.store', $labs), $this->classData([...$elsewhere, 'batch_id' => $otherGroup->id]))
            ->assertSessionHasNoErrors();

        // A whole-section class still clashes with any batch's.
        $this->post(route('dean.schedules.store', $this->offering), $this->classData(['weekday' => 2, 'room_id' => Room::factory()->create()->id, 'teacher_id' => Teacher::factory()->forCollege($this->college)->create()->id]))
            ->assertSessionHasErrors('slot.0');
    }

    public function test_times_rooms_and_teachers_are_checked()
    {
        foreach ([
            ['start' => '06:30', 'end' => '08:00'],
            ['start' => '08:15', 'end' => '09:00'],
            ['start' => '19:00', 'end' => '20:30'],
            ['start' => '10:00', 'end' => '09:00'],
            ['weekday' => 7],
        ] as $override) {
            $this->post(route('dean.schedules.store', $this->offering), $this->classData($override))->assertSessionHasErrors();
        }

        $this->post(route('dean.schedules.store', $this->offering), $this->classData(['room_id' => null]))
            ->assertSessionHasErrors(['room_id' => 'Choose a room, or make the class online.']);
        $this->post(route('dean.schedules.store', $this->offering), $this->classData(['room_id' => Room::factory()->create(['is_active' => false])->id]))
            ->assertSessionHasErrors(['room_id' => 'Choose an active room.']);
        $this->post(route('dean.schedules.store', $this->offering), $this->classData(['teacher_id' => Teacher::factory()->create()->id]))
            ->assertSessionHasErrors(['teacher_id' => 'Choose an active teacher of your college.']);
        $this->post(route('dean.schedules.store', $this->offering), $this->classData(['batch_id' => Batch::factory()->create()->id]))
            ->assertSessionHasErrors('batch_id');

        $this->assertDatabaseCount('schedules', 0);

        $this->post(route('dean.schedules.store', $this->offering), $this->classData(['mode' => 'online', 'room_id' => $this->room->id]))->assertSessionHasNoErrors();
        $this->assertNull(Schedule::sole()->room_id);

        // The day's last class may end exactly at 8:00 PM.
        $this->post(route('dean.schedules.store', $this->offering), $this->classData(['weekday' => 6, 'start' => '18:30', 'end' => '20:00']))->assertSessionHasNoErrors();
    }

    public function test_a_small_room_or_a_teacher_on_leave_only_warns()
    {
        $small = Room::factory()->create(['code' => 'SR-1', 'capacity' => 2]);
        TermRegistration::factory()->count(3)->for($this->term)->create(['section_id' => $this->section->id]);
        $this->teacher->update(['duty_status' => DutyStatus::OnLeave]);

        $this->post(route('dean.schedules.store', $this->offering), $this->classData(['room_id' => $small->id]))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'warning')
            ->assertInertiaFlash('toast.message', "Class added. {$this->teacher->user->full_name} is on leave. SR-1 holds 2; this class has 3 learners.");
        $this->assertDatabaseCount('schedules', 1);
    }

    public function test_a_class_with_attendance_keeps_its_day_and_time()
    {
        $record = AttendanceRecord::factory()->create();
        $class = $record->schedule;
        $this->section = $class->subjectOffering->section;
        $this->actingAs(User::factory()->dean($this->section->program->college)->create());
        $data = [
            'batch_id' => null, 'weekday' => $class->weekday(), 'start' => $class->starts_at->format('H:i'), 'end' => $class->ends_at->format('H:i'),
            'mode' => 'onsite', 'room_id' => Room::factory()->create()->id, 'teacher_id' => $class->teacher_id,
        ];

        $this->put(route('dean.schedules.update', $class), [...$data, 'weekday' => $class->weekday() % 6 + 1])
            ->assertSessionHasErrors(['start' => 'This class already has attendance, so its day and time stay. Add a new class instead.']);

        $this->put(route('dean.schedules.update', $class), $data)->assertSessionHasNoErrors();
        $this->assertSame($data['room_id'], $class->refresh()->room_id);

        $this->delete(route('dean.schedules.destroy', $class))
            ->assertInertiaFlash('toast.message', 'This class already has attendance, so it stays.');
        $this->assertModelExists($class);
    }

    public function test_a_class_can_move_or_go_while_it_has_no_attendance()
    {
        $class = Schedule::factory()->for($this->offering)->slot(1, '08:00', '09:30')->create(['teacher_id' => $this->teacher->id, 'room_id' => $this->room->id]);

        // Its own old slot never counts as a clash.
        $this->put(route('dean.schedules.update', $class), $this->classData(['start' => '08:30', 'end' => '10:00']))->assertSessionHasNoErrors();
        $this->assertSame('08:30', $class->refresh()->starts_at->format('H:i'));

        $this->delete(route('dean.schedules.destroy', $class))->assertInertiaFlash('toast.message', 'Class removed.');
        $this->assertModelMissing($class);
    }
}
