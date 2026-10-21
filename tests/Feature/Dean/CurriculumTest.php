<?php

namespace Tests\Feature\Dean;

use App\Enums\Semester;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

class CurriculumTest extends SchedulingTestCase
{
    private function subject(string $code): Subject
    {
        return Subject::factory()->for($this->college)->create(['code' => $code]);
    }

    private function line(Subject $subject, int $year = 1, Semester $semester = Semester::First): CurriculumSubject
    {
        return CurriculumSubject::factory()->for($this->program)->for($subject)->create(['year_level' => $year, 'semester' => $semester]);
    }

    public function test_the_dean_sees_a_programs_curriculum_by_year_and_semester()
    {
        $this->line($this->subject('CS 201'), 2);
        $this->line($this->subject('CS 102'), 1, Semester::Second);
        $this->line($this->subject('CS 103'));
        $this->subject('CS 999'); // not in the curriculum yet
        Subject::factory()->create(['code' => 'XX 100']); // another college's

        $this->get(route('dean.curriculum.index'))->assertInertia(fn (Assert $page) => $page
            ->component('dean/curriculum/Index')
            ->where('program.id', $this->program->id)
            ->where('lines.0.subject.code', 'CS 103')
            ->where('lines.1.subject.code', 'CS 102')
            ->where('lines.1.semester', 'second')
            ->where('lines.2.subject.code', 'CS 201')
            // CS 101 is offered to a section but not in the curriculum.
            ->has('subjects', 2)
            ->where('subjects.0.code', 'CS 101')
            ->where('subjects.1.code', 'CS 999'));
    }

    public function test_subjects_are_added_to_a_year_and_semester_at_once()
    {
        $a = $this->subject('CS 102');
        $b = $this->subject('CS 103');

        $this->post(route('dean.curriculum.store', $this->program), ['year_level' => 1, 'semester' => 'second', 'subject_ids' => [$a->id, $b->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->program->curriculum()->where('year_level', 1)->where('semester', 'second')->count());

        $this->post(route('dean.curriculum.store', $this->program), ['year_level' => 2, 'semester' => 'first', 'subject_ids' => [$a->id]])
            ->assertSessionHasErrors(['subject_ids.0' => 'That subject is already in the curriculum.']);

        $foreign = Subject::factory()->create();
        $this->post(route('dean.curriculum.store', $this->program), ['year_level' => 1, 'semester' => 'first', 'subject_ids' => [$foreign->id]])
            ->assertSessionHasErrors('subject_ids.0');
        $this->post(route('dean.curriculum.store', $this->program), ['year_level' => 7, 'semester' => 'first', 'subject_ids' => []])
            ->assertSessionHasErrors(['year_level', 'subject_ids']);
    }

    public function test_a_subject_is_moved_or_removed()
    {
        $line = $this->line($this->subject('CS 102'));

        $this->put(route('dean.curriculum.update', $line), ['year_level' => 2, 'semester' => 'summer'])->assertSessionHasNoErrors();
        $this->assertSame([2, Semester::Summer], [$line->fresh()?->year_level, $line->fresh()?->semester]);

        $this->delete(route('dean.curriculum.destroy', $line))->assertSessionHasNoErrors();
        $this->assertModelMissing($line);
    }

    public function test_only_the_programs_dean_edits_its_curriculum()
    {
        $foreign = Program::factory()->create();
        $foreignLine = CurriculumSubject::factory()->for($foreign)->create();

        $this->post(route('dean.curriculum.store', $foreign), ['year_level' => 1, 'semester' => 'first', 'subject_ids' => [$foreignLine->subject_id]])->assertForbidden();
        $this->put(route('dean.curriculum.update', $foreignLine), ['year_level' => 2, 'semester' => 'first'])->assertForbidden();
        $this->delete(route('dean.curriculum.destroy', $foreignLine))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('dean.curriculum.index'))->assertForbidden();
    }

    public function test_a_new_section_gets_its_year_and_semesters_subjects()
    {
        $this->line($this->subject('CS 102'));
        $this->line($this->subject('CS 103'));
        $this->line($this->subject('CS 104'), 1, Semester::Second);
        $this->line($this->subject('CS 201'), 2);

        $this->post(route('dean.sections.store'), ['term_id' => $this->term->id, 'program_id' => $this->program->id, 'year_level' => 1, 'name' => '1-B'])
            ->assertSessionHasNoErrors();

        $section = Section::where('name', '1-B')->sole();
        $offerings = $section->subjectOfferings()->with('subject')->get();
        $this->assertEqualsCanonicalizing(['CS 102', 'CS 103'], $offerings->pluck('subject.code')->all());
        $this->assertSame([null], $offerings->pluck('teacher_id')->unique()->values()->all(), 'Teachers are to be announced.');
        $this->assertSame(['type' => 'success', 'message' => 'Section BSCS 1-B added with 2 subjects from the curriculum.'], session('inertia.flash_data')['toast'] ?? null);

        // A second-semester term takes the second semester's.
        $second = Term::factory()->create(['school_year' => $this->term->school_year, 'semester' => Semester::Second, 'starts_on' => '2027-01-11', 'ends_on' => '2027-05-21']);
        $this->post(route('dean.sections.store'), ['term_id' => $second->id, 'program_id' => $this->program->id, 'year_level' => 1, 'name' => '1-A'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['CS 104'], Section::where('term_id', $second->id)->sole()->subjectOfferings()->with('subject')->get()->pluck('subject.code')->all());
    }

    public function test_an_existing_section_adds_what_it_lacks_and_keeps_the_rest()
    {
        $this->line($this->offering->subject);
        $this->line($this->subject('CS 102'));
        $registration = TermRegistration::factory()->for($this->term)->create(['section_id' => $this->section->id]);
        $irregular = TermRegistration::factory()->for($this->term)->create(['section_id' => $this->section->id, 'is_irregular' => true]);

        $this->get(route('dean.sections.show', $this->section))->assertInertia(fn (Assert $page) => $page
            ->where('curriculumMissing', ['CS 102']));

        $this->post(route('dean.offerings.curriculum', $this->section))->assertSessionHasNoErrors();

        $added = $this->section->subjectOfferings()->whereRelation('subject', 'code', 'CS 102')->sole();
        $this->assertTrue(Enrollment::where('subject_offering_id', $added->id)->where('learner_id', $registration->learner_id)->exists());
        $this->assertFalse(Enrollment::where('subject_offering_id', $added->id)->where('learner_id', $irregular->learner_id)->exists());
        $this->assertSame($this->teacher->id, $this->offering->fresh()?->teacher_id, 'What the section had is untouched.');

        $this->post(route('dean.offerings.curriculum', $this->section));
        $this->assertSame(2, $this->section->subjectOfferings()->count());
    }

    public function test_a_subject_in_a_curriculum_cant_be_deleted()
    {
        $subject = $this->subject('CS 102');
        $this->line($subject);

        $this->actingAs(User::factory()->admin()->create());
        $this->delete(route('admin.subjects.destroy', $subject));

        $this->assertModelExists($subject);
        $this->assertSame("CS 102 still has 1 curriculum, so it can't be deleted.", session('inertia.flash_data')['toast']['message'] ?? null);
    }
}
