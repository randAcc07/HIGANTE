<?php

namespace Tests\Feature\Dean;

use App\Enums\Semester;
use App\Models\CurriculumSubject;
use App\Models\Learner;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use App\Registration\TermRegistrar;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Last school year's 2nd semester (2025-2026) had BSCS 1-A and 4-A; the
 * coming term is the 1st semester of 2026-2027, which already has 1-A.
 */
class SectionCopyTest extends SchedulingTestCase
{
    private Term $previous;

    private Section $oneA;

    private Section $fourA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->term->update(['school_year' => '2026-2027']);
        $this->previous = Term::factory()->create(['school_year' => '2025-2026', 'semester' => Semester::Second, 'starts_on' => '2026-01-12', 'ends_on' => '2026-05-22']);
        $this->oneA = Section::factory()->for($this->previous)->for($this->program)->create(['year_level' => 1, 'name' => '1-A', 'capacity' => 40]);
        $this->fourA = Section::factory()->for($this->previous)->for($this->program)->create(['year_level' => 4, 'name' => '4-A']);

        foreach ([1, 2, 3, 4] as $year) {
            CurriculumSubject::factory()->for($this->program)->create([
                'year_level' => $year,
                'subject_id' => Subject::factory()->for($this->college)->create(['code' => "CY {$year}01"])->id,
            ]);
        }
    }

    public function test_a_new_school_year_offers_last_terms_blocks_one_year_up()
    {
        Section::factory()->for($this->previous)->create(); // another college's

        $this->get(route('dean.sections.index', ['term' => $this->term->id]))->assertInertia(fn (Assert $page) => $page
            ->where('copy.from.id', $this->previous->id)
            ->where('copy.shiftsUp', true)
            ->has('copy.sections', 2)
            ->where('copy.sections.0', ['id' => $this->oneA->id, 'program_id' => $this->program->id, 'program' => 'BSCS', 'from' => '1-A', 'year_level' => 2, 'name' => '2-A', 'capacity' => 40, 'ticked' => true])
            // Year 5 is past the curriculum: a graduating block.
            ->where('copy.sections.1.year_level', 5)
            ->where('copy.sections.1.ticked', false));
    }

    public function test_a_second_semester_offers_the_same_blocks()
    {
        $second = Term::factory()->create(['school_year' => '2026-2027', 'semester' => Semester::Second, 'starts_on' => '2027-01-11', 'ends_on' => '2027-05-21']);

        $this->get(route('dean.sections.index', ['term' => $second->id]))->assertInertia(fn (Assert $page) => $page
            ->where('copy.from.id', $this->term->id)
            ->where('copy.shiftsUp', false)
            ->where('copy.sections.0.name', '1-A')
            ->where('copy.sections.0.year_level', 1));
    }

    public function test_copies_are_linked_and_get_their_curriculum_subjects()
    {
        $this->post(route('dean.sections.copy'), ['term_id' => $this->term->id, 'sections' => [
            ['from_id' => $this->oneA->id, 'year_level' => 2, 'name' => '2-A', 'capacity' => 35],
        ]])->assertSessionHasNoErrors()->assertRedirect(route('dean.sections.index', ['term' => $this->term->id]));

        $copy = Section::where('term_id', $this->term->id)->where('name', '2-A')->sole();
        $this->assertSame([$this->oneA->id, 2, 35], [$copy->previous_section_id, $copy->year_level, $copy->capacity]);
        $this->assertSame(['CY 201'], $copy->subjectOfferings()->with('subject')->get()->pluck('subject.code')->all());
        $this->assertSame('Copied 1 section with 1 subject from the curriculum.', session('inertia.flash_data')['toast']['message'] ?? null);
    }

    public function test_a_taken_or_foreign_section_is_refused_whole()
    {
        $foreign = Section::factory()->for($this->previous)->for(Program::factory())->create();

        $this->post(route('dean.sections.copy'), ['term_id' => $this->term->id, 'sections' => [
            ['from_id' => $this->fourA->id, 'year_level' => 4, 'name' => '4-A'],
            ['from_id' => $this->oneA->id, 'year_level' => 1, 'name' => '1-a'],
            ['from_id' => $foreign->id, 'year_level' => 2, 'name' => '2-Z'],
        ]])->assertSessionHasErrors([
            'sections.1.name' => 'BSCS already has a section named 1-a this term.',
            'sections.2.from_id',
        ]);

        $this->assertSame(1, Section::where('term_id', $this->term->id)->count(), 'Nothing is copied.');
    }

    public function test_registration_keeps_a_copied_block_together()
    {
        $this->post(route('dean.sections.copy'), ['term_id' => $this->term->id, 'sections' => [
            ['from_id' => $this->oneA->id, 'year_level' => 2, 'name' => '2-A', 'capacity' => 1],
        ]])->assertSessionHasNoErrors();
        $twoA = Section::where('term_id', $this->term->id)->where('name', '2-A')->sole();
        // An emptier year-2 section made by hand.
        Section::factory()->for($this->term)->for($this->program)->create(['year_level' => 2, 'name' => '2-B']);

        $registrar = app(TermRegistrar::class);
        $staff = User::factory()->registrar()->create();
        $placed = [];

        foreach (['Abad', 'Bautista'] as $name) {
            $learner = Learner::factory()->for($this->program)->create(['year_level' => 1]);
            TermRegistration::factory()->for($this->previous)->create(['learner_id' => $learner->id, 'section_id' => $this->oneA->id, 'year_level' => 1]);
            $placed[$name] = $registrar->register($learner->fresh() ?? $learner, $this->term, 2, false, $staff);
        }

        $this->assertSame($twoA->id, $placed['Abad']['registration']->section_id);
        $this->assertSame($twoA->id, $placed['Bautista']['registration']->section_id, 'Kept with the block past capacity.');

        // A learner from another block goes to the emptiest.
        $other = Learner::factory()->for($this->program)->create(['year_level' => 1]);
        $this->assertSame('2-B', $registrar->register($other, $this->term, 2, false, $staff)['registration']->section?->name);
    }

    public function test_every_section_of_the_term_gets_its_missing_curriculum_subjects()
    {
        Section::factory()->for($this->term)->for($this->program)->create(['year_level' => 2, 'name' => '2-A']);

        $this->get(route('dean.sections.index', ['term' => $this->term->id]))
            ->assertInertia(fn (Assert $page) => $page->where('curriculumMissing', 2));

        $this->post(route('dean.sections.curriculum', $this->term))->assertSessionHasNoErrors();

        $this->assertSame('Added 2 subjects to 2 sections.', session('inertia.flash_data')['toast']['message'] ?? null);
        $this->get(route('dean.sections.index', ['term' => $this->term->id]))
            ->assertInertia(fn (Assert $page) => $page->where('curriculumMissing', 0));
    }
}
