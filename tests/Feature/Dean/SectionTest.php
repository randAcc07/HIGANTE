<?php

namespace Tests\Feature\Dean;

use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TermRegistration;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

class SectionTest extends SchedulingTestCase
{
    public function test_the_dean_sees_their_colleges_sections_for_the_coming_term()
    {
        Section::factory()->for($this->term)->create(); // another college's
        TermRegistration::factory()->count(2)->for($this->term)->create(['section_id' => $this->section->id]);

        $this->get(route('dean.sections.index'))->assertInertia(fn (Assert $page) => $page
            ->component('dean/sections/Index')
            ->where('term.id', $this->term->id)
            ->where('term.phase', 'upcoming')
            ->has('sections', 1)
            ->where('sections.0.name', '1-A')
            ->where('sections.0.term_registrations_count', 2)
            ->where('sections.0.subject_offerings_count', 1)
            ->where('canEdit', true));
    }

    public function test_a_section_is_added_for_one_of_the_colleges_programs()
    {
        $this->post(route('dean.sections.store'), ['term_id' => $this->term->id, 'program_id' => $this->program->id, 'year_level' => 1, 'name' => '1-B', 'capacity' => 35])
            ->assertRedirect();
        $this->assertDatabaseHas('sections', ['program_id' => $this->program->id, 'name' => '1-B', 'capacity' => 35]);

        $this->post(route('dean.sections.store'), ['term_id' => $this->term->id, 'program_id' => $this->program->id, 'year_level' => 1, 'name' => '1-B'])
            ->assertSessionHasErrors(['name' => 'This program already has a section with that name this term.']);

        $foreign = Program::factory()->create();
        $this->post(route('dean.sections.store'), ['term_id' => $this->term->id, 'program_id' => $foreign->id, 'year_level' => 1, 'name' => '1-A'])
            ->assertSessionHasErrors(['program_id' => "Choose one of your college's programs."]);
    }

    public function test_editing_changes_the_name_and_capacity_only()
    {
        $this->put(route('dean.sections.update', $this->section), ['name' => '1-Alpha', 'capacity' => 30, 'year_level' => 4, 'program_id' => Program::factory()->create()->id])
            ->assertSessionHasNoErrors();

        $this->section->refresh();
        $this->assertSame('1-Alpha', $this->section->name);
        $this->assertSame(30, $this->section->capacity);
        $this->assertSame(1, $this->section->year_level);
        $this->assertSame($this->program->id, $this->section->program_id);
    }

    public function test_a_section_with_learners_stays_and_an_empty_one_goes_with_its_classes()
    {
        TermRegistration::factory()->for($this->term)->create(['section_id' => $this->section->id]);

        $this->delete(route('dean.sections.destroy', $this->section))
            ->assertInertiaFlash('toast.message', "BSCS 1-A still has 1 registered learner, so it can't be deleted.");
        $this->assertModelExists($this->section);

        $empty = Section::factory()->for($this->term)->for($this->program)->create(['name' => '1-Z']);
        $offering = $this->anotherOffering('CS 109', $empty);
        $schedule = Schedule::factory()->for($offering)->create();

        $this->delete(route('dean.sections.destroy', $empty))->assertRedirect(route('dean.sections.index', ['term' => $this->term->id]));
        $this->assertModelMissing($empty);
        $this->assertModelMissing($offering);
        $this->assertModelMissing($schedule);
    }

    public function test_the_section_page_shows_its_subjects_classes_and_choices()
    {
        Schedule::factory()->for($this->offering)->slot(1, '08:00', '09:30')->create(['teacher_id' => $this->teacher->id, 'room_id' => $this->room->id]);
        Subject::factory()->for($this->college)->create(['code' => 'CS 200']);
        Subject::factory()->create(['code' => 'OTHER 1']);

        $this->get(route('dean.sections.show', $this->section))->assertInertia(fn (Assert $page) => $page
            ->component('dean/sections/Show')
            ->has('offerings', 1)
            ->has('classes', 1)
            ->where('classes.0.weekday', 1)
            ->where('classes.0.start', '08:00')
            ->where('classes.0.room', 'MB-101')
            ->has('subjects', 1)
            ->where('subjects.0.code', 'CS 200')
            ->has('teachers', 2)
            ->where('canEdit', true));
    }

    public function test_subjects_belong_to_the_college_and_appear_once()
    {
        $subject = Subject::factory()->for($this->college)->create();

        $this->post(route('dean.offerings.store', $this->section), ['subject_id' => $subject->id, 'teacher_id' => null])->assertSessionHasNoErrors();
        $this->post(route('dean.offerings.store', $this->section), ['subject_id' => $subject->id])
            ->assertSessionHasErrors(['subject_id' => 'The section already has this subject.']);
        $this->post(route('dean.offerings.store', $this->section), ['subject_id' => Subject::factory()->create()->id])
            ->assertSessionHasErrors('subject_id');
        $this->post(route('dean.offerings.store', $this->section), ['subject_id' => Subject::factory()->for($this->college)->create()->id, 'teacher_id' => Teacher::factory()->create()->id])
            ->assertSessionHasErrors('teacher_id');
    }

    public function test_changing_the_teacher_can_move_the_subjects_classes_unless_they_clash()
    {
        $class = Schedule::factory()->for($this->offering)->slot(1, '08:00', '09:30')->create(['teacher_id' => $this->teacher->id]);

        $this->put(route('dean.offerings.update', $this->offering), ['teacher_id' => $this->otherTeacher->id, 'apply_to_classes' => false])->assertSessionHasNoErrors();
        $this->assertSame($this->otherTeacher->id, $this->offering->refresh()->teacher_id);
        $this->assertSame($this->teacher->id, $class->refresh()->teacher_id);

        // The other teacher is busy then (with another section), so moving
        // the class is refused as a whole.
        $elsewhere = Section::factory()->for($this->term)->for($this->program)->create(['name' => '1-B']);
        Schedule::factory()->for($this->anotherOffering('CS 102', $elsewhere))->slot(1, '09:00', '10:00')->create(['teacher_id' => $this->otherTeacher->id]);
        $this->put(route('dean.offerings.update', $this->offering), ['teacher_id' => $this->otherTeacher->id, 'apply_to_classes' => true])
            ->assertSessionHasErrors('teacher_id.0');
        $this->assertSame($this->teacher->id, $class->refresh()->teacher_id);

        $free = Teacher::factory()->forCollege($this->college)->create();
        $this->put(route('dean.offerings.update', $this->offering), ['teacher_id' => $free->id, 'apply_to_classes' => true])->assertSessionHasNoErrors();
        $this->assertSame($free->id, $class->refresh()->teacher_id);
    }

    public function test_a_subject_with_enrolled_learners_stays()
    {
        Enrollment::factory()->for($this->offering)->create();

        $this->delete(route('dean.offerings.destroy', $this->offering))
            ->assertInertiaFlash('toast.message', "CS 101 still has 1 enrollment, so it can't be deleted.");
        $this->assertModelExists($this->offering);
    }

    public function test_batches_are_named_uniquely_and_kept_while_used()
    {
        $this->post(route('dean.batches.store', $this->offering), ['name' => '1-AA'])->assertSessionHasNoErrors();
        $this->post(route('dean.batches.store', $this->offering), ['name' => '1-AA'])
            ->assertSessionHasErrors(['name' => 'The subject already has a batch with that name.']);

        $batch = Batch::where('name', '1-AA')->sole();
        $this->put(route('dean.batches.update', $batch), ['name' => '1-AX'])->assertSessionHasNoErrors();
        $this->assertSame('1-AX', $batch->refresh()->name);

        Schedule::factory()->for($this->offering)->create(['batch_id' => $batch->id]);
        $this->delete(route('dean.batches.destroy', $batch))
            ->assertInertiaFlash('toast.message', "1-AX still has 1 class, so it can't be deleted.");
        $this->assertModelExists($batch);
    }

    public function test_another_colleges_dean_and_other_roles_cannot_touch_the_section()
    {
        $this->actingAs(User::factory()->dean()->create());
        $this->get(route('dean.sections.show', $this->section))->assertForbidden();
        $this->put(route('dean.sections.update', $this->section), ['name' => 'X'])->assertForbidden();
        $this->post(route('dean.schedules.store', $this->offering), $this->classData())->assertForbidden();
        $this->post(route('dean.batches.store', $this->offering), ['name' => 'X'])->assertForbidden();

        foreach (['admin', 'registrar', 'teacher', 'learner'] as $role) {
            $this->actingAs(User::factory()->{$role}($role === 'admin' || $role === 'registrar' ? null : $this->college)->create());
            $this->get(route('dean.sections.index'))->assertForbidden();
        }

        $this->assertSame('1-A', $this->section->refresh()->name);
        $this->assertDatabaseCount('schedules', 0);
    }

    public function test_a_closed_term_is_read_only()
    {
        $this->term->forceFill(['starts_on' => '2026-01-12', 'ends_on' => '2026-05-22', 'closed_at' => now()])->save();

        $this->get(route('dean.sections.show', $this->section))->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
        $this->put(route('dean.sections.update', $this->section), ['name' => 'X'])->assertForbidden();
        $this->post(route('dean.schedules.store', $this->offering), $this->classData())->assertForbidden();
        $this->post(route('dean.sections.store'), ['term_id' => $this->term->id, 'program_id' => $this->program->id, 'year_level' => 1, 'name' => '1-C'])->assertForbidden();
        $this->delete(route('dean.offerings.destroy', $this->offering))->assertForbidden();
    }
}
