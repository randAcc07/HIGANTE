<?php

namespace Tests\Feature\Dean;

use App\Enums\EnrollmentStatus;
use App\Enums\RegistrationStatus;
use App\Models\AttendanceRecord;
use App\Models\Batch;
use App\Models\College;
use App\Models\Enrollment;
use App\Models\Learner;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\TermRegistration;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

class PlacementTest extends SchedulingTestCase
{
    private function registration(array $override = []): TermRegistration
    {
        $learner = Learner::factory()->for($this->program)->create(['year_level' => 1]);

        return TermRegistration::factory()->for($learner)->for($this->term)->create(['year_level' => 1, ...$override]);
    }

    private function enroll(TermRegistration $registration, SubjectOffering $offering, ?Batch $batch = null): Enrollment
    {
        return Enrollment::factory()->for($offering)->create(['learner_id' => $registration->learner_id, 'batch_id' => $batch?->id]);
    }

    private function otherSection(string $name = '1-B'): Section
    {
        return Section::factory()->for($this->term)->for($this->program)->create(['year_level' => 1, 'name' => $name]);
    }

    public function test_the_queue_lists_unsectioned_learners_and_placing_enrolls_regulars()
    {
        $waiting = $this->registration();
        $this->registration(['section_id' => $this->section->id]);
        $this->section->update(['capacity' => 1]);

        $this->get(route('dean.learners.index', ['term' => $this->term->id, 'section' => 'none']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('dean/learners/Index')
                ->where('unsectioned', 1)
                ->has('registrations.data', 1)
                ->where('registrations.data.0.id', $waiting->id)
                ->where('registrations.data.0.subjects_count', 0));

        $this->put(route('dean.learners.section', $waiting), ['section_id' => $this->section->id])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'warning');

        $this->assertSame($this->section->id, $waiting->refresh()->section_id);
        $this->assertTrue($this->offering->enrollments()->where('learner_id', $waiting->learner_id)->exists());
        $this->get(route('dean.learners.index', ['term' => $this->term->id, 'section' => (string) $this->section->id]))
            ->assertInertia(fn (Assert $page) => $page->where('registrations.data', fn ($rows) => collect($rows)->firstWhere('id', $waiting->id)['subjects_count'] === 1));
    }

    public function test_only_sections_of_the_learners_program_and_year_level_take_them()
    {
        $waiting = $this->registration();
        $second = Section::factory()->for($this->term)->for($this->program)->create(['year_level' => 2, 'name' => '2-A']);

        $this->put(route('dean.learners.section', $waiting), ['section_id' => $second->id])->assertSessionHasErrors('section_id');
    }

    public function test_moving_a_regular_learner_swaps_subjects_and_keeps_recorded_ones_as_dropped()
    {
        $registration = $this->registration(['section_id' => $this->section->id]);
        $recorded = $this->enroll($registration, $this->offering);
        AttendanceRecord::factory()->for($recorded)->create();
        $unrecorded = $this->enroll($registration, $this->anotherOffering());
        $target = $this->otherSection();
        $newOffering = $this->anotherOffering('CS 103', $target);

        $this->put(route('dean.learners.section', $registration), ['section_id' => $target->id])->assertSessionHasNoErrors();

        $this->assertSame(EnrollmentStatus::Dropped, $recorded->refresh()->status);
        $this->assertSame($this->dean->id, $recorded->drop_confirmed_by);
        $this->assertModelMissing($unrecorded);
        $this->assertTrue($newOffering->enrollments()->where('learner_id', $registration->learner_id)->exists());
    }

    public function test_moving_an_irregular_learner_keeps_their_subjects()
    {
        $registration = $this->registration(['section_id' => $this->section->id, 'is_irregular' => true]);
        $kept = $this->enroll($registration, $this->offering);
        $target = $this->otherSection();
        $this->anotherOffering('CS 103', $target);

        $this->put(route('dean.learners.section', $registration), ['section_id' => $target->id])->assertSessionHasNoErrors();

        $this->assertSame(EnrollmentStatus::Enrolled, $kept->refresh()->status);
        $this->assertSame(1, Enrollment::where('learner_id', $registration->learner_id)->count());
    }

    public function test_adding_a_subject_checks_the_learners_week_and_makes_them_irregular()
    {
        $registration = $this->registration(['section_id' => $this->section->id]);
        $this->enroll($registration, $this->offering);
        Schedule::factory()->for($this->offering)->slot(1, '08:00', '09:30')->create();

        $elsewhere = $this->otherSection();
        $clashing = $this->anotherOffering('CS 201', $elsewhere);
        Schedule::factory()->for($clashing)->slot(1, '09:00', '10:00')->create();
        $free = $this->anotherOffering('CS 202', $elsewhere);
        Schedule::factory()->for($free)->slot(2, '09:00', '10:00')->create();

        $this->post(route('dean.learners.enroll', $registration), ['subject_offering_id' => $clashing->id])
            ->assertSessionHasErrors('subject_offering_id.0');
        $this->assertFalse($registration->refresh()->is_irregular);

        $this->post(route('dean.learners.enroll', $registration), ['subject_offering_id' => $free->id])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'CS 202 of BSCS 1-B added. The learner is now irregular.');
        $this->assertTrue($registration->refresh()->is_irregular);

        $this->post(route('dean.learners.enroll', $registration), ['subject_offering_id' => $free->id])
            ->assertSessionHasErrors('subject_offering_id');
    }

    public function test_removing_a_subject_deletes_it_or_drops_it_once_recorded()
    {
        $registration = $this->registration(['section_id' => $this->section->id]);
        $unrecorded = $this->enroll($registration, $this->offering);
        $recorded = $this->enroll($registration, $this->anotherOffering());
        AttendanceRecord::factory()->for($recorded)->create();

        $this->delete(route('dean.enrollments.destroy', $unrecorded))->assertSessionHasNoErrors();
        $this->assertModelMissing($unrecorded);
        $this->assertTrue($registration->refresh()->is_irregular);

        $this->delete(route('dean.enrollments.destroy', $recorded))->assertSessionHasNoErrors();
        $this->assertSame(EnrollmentStatus::Dropped, $recorded->refresh()->status);

        // A dropped subject can be taken again, keeping its history.
        $this->post(route('dean.learners.enroll', $registration), ['subject_offering_id' => $recorded->subject_offering_id])->assertSessionHasNoErrors();
        $this->assertSame(EnrollmentStatus::Enrolled, $recorded->refresh()->status);
        $this->assertNull($recorded->drop_confirmed_by);
    }

    public function test_the_roster_sets_batches_and_refuses_overlapping_labs()
    {
        // Physics 1-AA and Chemistry 1-AB run at the same time: different groups.
        $physics = $this->anotherOffering('PHYS 101');
        $chemistry = SubjectOffering::factory()->for($this->section)->create(['subject_id' => Subject::factory()->for($this->college)->create(['code' => 'CHEM 101'])->id]);
        [$physicsA, $physicsB] = [Batch::factory()->for($physics)->create(['name' => '1-AA']), Batch::factory()->for($physics)->create(['name' => '1-AB'])];
        [$chemistryA, $chemistryB] = [Batch::factory()->for($chemistry)->create(['name' => '1-AA']), Batch::factory()->for($chemistry)->create(['name' => '1-AB'])];
        Schedule::factory()->for($physics)->for($physicsA)->slot(3, '08:00', '11:00')->create();
        Schedule::factory()->for($chemistry)->for($chemistryB)->slot(3, '08:00', '11:00')->create();
        Schedule::factory()->for($physics)->for($physicsB)->slot(4, '08:00', '11:00')->create();
        Schedule::factory()->for($chemistry)->for($chemistryA)->slot(4, '08:00', '11:00')->create();

        $registration = $this->registration(['section_id' => $this->section->id]);
        $inPhysics = $this->enroll($registration, $physics);
        $inChemistry = $this->enroll($registration, $chemistry);

        $this->get(route('dean.sections.show', $this->section))
            ->assertInertia(fn (Assert $page) => $page
                ->has('roster', 1)
                ->where('roster.0.enrollments.'.$physics->id.'.id', $inPhysics->id)
                // Batches carry their lab's seats (smallest room they meet in) for the automatic split.
                ->where('offerings', fn ($offerings) => collect($offerings)->firstWhere('id', $physics->id)['batches'][0]['room_capacity'] === Schedule::where('batch_id', $physicsA->id)->sole()->room->capacity));

        $this->put(route('dean.batches.roster', $this->section), ['batches' => [
            ['enrollment_id' => $inPhysics->id, 'batch_id' => $physicsA->id],
            ['enrollment_id' => $inChemistry->id, 'batch_id' => $chemistryB->id],
        ]])->assertSessionHasErrors('batches.0');
        $this->assertNull($inPhysics->refresh()->batch_id);

        $this->put(route('dean.batches.roster', $this->section), ['batches' => [
            ['enrollment_id' => $inPhysics->id, 'batch_id' => $physicsA->id],
            ['enrollment_id' => $inChemistry->id, 'batch_id' => $chemistryA->id],
        ]])->assertSessionHasNoErrors();
        $this->assertSame($physicsA->id, $inPhysics->refresh()->batch_id);
        $this->assertSame($chemistryA->id, $inChemistry->refresh()->batch_id);

        // A batch of another subject is refused.
        $this->put(route('dean.batches.roster', $this->section), ['batches' => [
            ['enrollment_id' => $inPhysics->id, 'batch_id' => $chemistryB->id],
        ]])->assertSessionHasErrors('batches.0');
    }

    public function test_scheduling_warns_about_learners_with_another_class_then()
    {
        $elsewhere = $this->otherSection();
        $theirs = $this->anotherOffering('CS 201', $elsewhere);
        Schedule::factory()->for($theirs)->slot(1, '08:00', '09:30')->create();
        $irregular = $this->registration(['section_id' => $elsewhere->id, 'is_irregular' => true]);
        $this->enroll($irregular, $theirs);
        $this->enroll($irregular, $this->offering);

        $this->post(route('dean.schedules.store', $this->offering), $this->classData())
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'warning')
            ->assertInertiaFlash('toast.message', 'Class added. '.$irregular->learner->user->full_name.' has another class at that time.');
    }

    public function test_placement_is_for_the_learners_own_dean_while_they_are_registered()
    {
        $registration = $this->registration();

        $this->actingAs(User::factory()->dean(College::factory()->create())->create());
        $this->get(route('dean.learners.show', $registration))->assertForbidden();
        $this->put(route('dean.learners.section', $registration), ['section_id' => $this->section->id])->assertForbidden();

        $this->actingAs($this->dean);
        $this->get(route('dean.learners.show', $registration))->assertOk();
        $registration->update(['status' => RegistrationStatus::Withdrawn]);
        $this->put(route('dean.learners.section', $registration), ['section_id' => $this->section->id])->assertForbidden();

        $registration->update(['status' => RegistrationStatus::Registered]);
        $this->term->forceFill(['closed_at' => now()])->save();
        $this->put(route('dean.learners.section', $registration), ['section_id' => $this->section->id])->assertForbidden();

        $this->actingAs(User::factory()->registrar()->create());
        $this->get(route('dean.learners.index'))->assertForbidden();
    }

    public function test_the_learner_page_shows_their_week()
    {
        $registration = $this->registration(['section_id' => $this->section->id]);
        $this->enroll($registration, $this->offering);
        Schedule::factory()->for($this->offering)->slot(1, '08:00', '09:30')->create();

        $this->get(route('dean.learners.show', $registration))
            ->assertInertia(fn (Assert $page) => $page
                ->component('dean/learners/Show')
                ->has('enrollments', 1)
                ->has('classes', 1)
                ->where('classes.0.subject', 'CS 101')
                ->where('canEdit', true));
    }

    /**
     * CS 101 split into 1-AA (Ann) and 1-AB (Ben), with Cy unbatched; all
     * three take the subject passed in too.
     *
     * @return array{Batch, Batch, array<string, TermRegistration>}
     */
    private function splitSource(SubjectOffering ...$targets): array
    {
        [$aa, $ab] = [Batch::factory()->for($this->offering)->create(['name' => '1-AA']), Batch::factory()->for($this->offering)->create(['name' => '1-AB'])];
        $learners = [];

        foreach (['Ann' => $aa, 'Ben' => $ab, 'Cy' => null] as $name => $batch) {
            $learners[$name] = $this->registration(['section_id' => $this->section->id]);
            $this->enroll($learners[$name], $this->offering, $batch);

            foreach ($targets as $target) {
                $this->enroll($learners[$name], $target);
            }
        }

        return [$aa, $ab, $learners];
    }

    private function batchIn(SubjectOffering $offering, TermRegistration $registration): ?string
    {
        return Enrollment::with('batch')->where('subject_offering_id', $offering->id)->where('learner_id', $registration->learner_id)->sole()->batch?->name;
    }

    public function test_copying_batches_gives_other_subjects_the_same_split()
    {
        $physics = $this->anotherOffering('PHYS 101');
        $chemistry = SubjectOffering::factory()->for($this->section)->create(['subject_id' => Subject::factory()->for($this->college)->create(['code' => 'CHEM 101'])->id]);
        $extra = Batch::factory()->for($chemistry)->create(['name' => '1-AC']);
        $chemAA = Batch::factory()->for($chemistry)->create(['name' => '1-AA']);
        [, , $learners] = $this->splitSource($physics, $chemistry);
        Enrollment::where('subject_offering_id', $chemistry->id)->where('learner_id', $learners['Ben']->learner_id)->update(['batch_id' => $extra->id]);
        Enrollment::where('subject_offering_id', $chemistry->id)->where('learner_id', $learners['Cy']->learner_id)->update(['batch_id' => $chemAA->id]);

        $this->post(route('dean.batches.copy', $this->offering), ['targets' => [$physics->id, $chemistry->id]])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '4 learners placed. 3 batches added; schedule their classes on the Timetables page.');

        $this->assertSame(['1-AA', '1-AB'], $physics->batches()->orderBy('name')->pluck('name')->all());
        $this->assertSame(['1-AA', '1-AB', '1-AC'], $chemistry->batches()->orderBy('name')->pluck('name')->all(), 'The extra 1-AC stays.');
        $this->assertSame(['1-AA', '1-AB', null], [$this->batchIn($physics, $learners['Ann']), $this->batchIn($physics, $learners['Ben']), $this->batchIn($physics, $learners['Cy'])]);
        $this->assertSame('1-AB', $this->batchIn($chemistry, $learners['Ben']), 'Moved out of 1-AC to match.');
        $this->assertSame('1-AA', $this->batchIn($chemistry, $learners['Cy']), 'Unbatched in CS 101: left as is.');
    }

    public function test_a_copy_that_would_overlap_labs_is_refused_whole()
    {
        $physics = $this->anotherOffering('PHYS 101');
        $chemistry = SubjectOffering::factory()->for($this->section)->create(['subject_id' => Subject::factory()->for($this->college)->create(['code' => 'CHEM 101'])->id]);
        [$aa, , $learners] = $this->splitSource($physics, $chemistry);
        $chemAA = Batch::factory()->for($chemistry)->create(['name' => '1-AA']);
        // Both 1-AA labs on Wednesday morning (put in directly, as scheduling would warn).
        Schedule::factory()->for($this->offering)->for($aa)->slot(3, '08:00', '11:00')->create();
        Schedule::factory()->for($chemistry)->for($chemAA)->slot(3, '08:00', '11:00')->create();

        $this->post(route('dean.batches.copy', $this->offering), ['targets' => [$physics->id, $chemistry->id]])
            ->assertSessionHasErrors('batches.0');

        $this->assertSame(0, $physics->batches()->count(), 'The batches added for PHYS 101 are undone too.');
        $this->assertNull($this->batchIn($chemistry, $learners['Ann']));
    }

    public function test_copying_batches_stays_within_the_section_and_its_dean()
    {
        $elsewhere = $this->anotherOffering('CS 201', $this->otherSection('1-B'));
        $this->post(route('dean.batches.copy', $this->offering), ['targets' => [$this->anotherOffering('PHYS 101')->id]])
            ->assertSessionHasErrors(['targets' => 'This subject has no batches to copy.']);

        Batch::factory()->for($this->offering)->create(['name' => '1-AA']);
        $this->post(route('dean.batches.copy', $this->offering), ['targets' => [$elsewhere->id]])->assertSessionHasErrors('targets.0');
        $this->post(route('dean.batches.copy', $this->offering), ['targets' => [$this->offering->id]])->assertSessionHasErrors('targets.0');

        $this->actingAs(User::factory()->dean(College::factory()->create())->create());
        $this->post(route('dean.batches.copy', $this->offering), ['targets' => [$elsewhere->id]])->assertForbidden();
    }
}
