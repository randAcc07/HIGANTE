<?php

namespace Tests\Feature\Admin\Catalog;

use App\Models\College;
use App\Models\Learner;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CollegeAndProgramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_colleges_are_listed_with_their_counts()
    {
        $college = College::factory()->create(['code' => 'COS']);
        Program::factory()->count(2)->for($college)->create();
        Subject::factory()->for($college)->create();
        User::factory()->dean($college)->create();
        User::factory()->learner($college)->create();

        $this->get(route('admin.colleges.index'))->assertInertia(fn (Assert $page) => $page
            ->has('colleges', 1)
            ->where('colleges.0.code', 'COS')
            ->where('colleges.0.programs_count', 2)
            ->where('colleges.0.subjects_count', 1)
            ->where('colleges.0.staff_count', 1));
    }

    public function test_a_college_can_be_added_and_renamed()
    {
        $this->post(route('admin.colleges.store'), ['code' => 'COA', 'name' => 'College of Agriculture'])
            ->assertRedirect(route('admin.colleges.show', College::where('code', 'COA')->sole()));

        $college = College::where('code', 'COA')->sole();

        $this->put(route('admin.colleges.update', $college), ['code' => 'COA', 'name' => 'College of Agriculture and Forestry'])
            ->assertSessionHasNoErrors();

        $this->assertSame('College of Agriculture and Forestry', $college->refresh()->name);
    }

    public function test_college_codes_and_names_are_unique_and_codes_are_plain()
    {
        College::factory()->create(['code' => 'COS', 'name' => 'College of Science']);

        $this->post(route('admin.colleges.store'), ['code' => 'COS', 'name' => 'College of Science'])
            ->assertSessionHasErrors(['code', 'name']);

        $this->post(route('admin.colleges.store'), ['code' => 'C O S', 'name' => 'Other'])
            ->assertSessionHasErrors(['code' => 'Use letters, digits and dashes only.']);

        $this->assertDatabaseCount('colleges', 1);
    }

    public function test_a_college_in_use_is_not_deleted()
    {
        $college = College::factory()->create(['name' => 'College of Science']);
        Program::factory()->count(2)->for($college)->create();

        $this->delete(route('admin.colleges.destroy', $college))
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', "College of Science still has 2 programs, so it can't be deleted.");

        $this->assertModelExists($college);
    }

    public function test_an_unused_college_is_deleted()
    {
        $college = College::factory()->create();

        $this->delete(route('admin.colleges.destroy', $college))->assertRedirect(route('admin.colleges.index'));

        $this->assertModelMissing($college);
    }

    public function test_the_college_page_lists_its_programs()
    {
        $college = College::factory()->create();
        $program = Program::factory()->for($college)->create(['code' => 'BSCS']);
        Learner::factory()->count(3)->for($program)->create();
        Program::factory()->create();

        $this->get(route('admin.colleges.show', $college))->assertInertia(fn (Assert $page) => $page
            ->component('admin/colleges/Show')
            ->where('college.id', $college->id)
            ->where('college.learner_count', 3)
            ->has('programs', 1)
            ->where('programs.0.code', 'BSCS')
            ->where('programs.0.learners_count', 3));
    }

    public function test_programs_are_added_to_their_college_and_stay_there()
    {
        $college = College::factory()->create();
        $other = College::factory()->create();

        $this->post(route('admin.colleges.programs.store', $college), ['code' => 'BSBio', 'name' => 'BS Biology'])
            ->assertSessionHasNoErrors();

        $program = Program::where('code', 'BSBio')->sole();
        $this->assertSame($college->id, $program->college_id);

        $this->put(route('admin.programs.update', $program), ['code' => 'BSBio', 'name' => 'Bachelor of Science in Biology', 'college_id' => $other->id])
            ->assertSessionHasNoErrors();

        $program->refresh();
        $this->assertSame('Bachelor of Science in Biology', $program->name);
        $this->assertSame($college->id, $program->college_id);

        $this->post(route('admin.colleges.programs.store', $other), ['code' => 'BSBio', 'name' => 'Duplicate'])
            ->assertSessionHasErrors('code');
    }

    public function test_a_program_in_use_is_not_deleted()
    {
        $program = Program::factory()->create(['code' => 'BSCS']);
        Learner::factory()->for($program)->create();
        Section::factory()->for($program)->create();

        $this->delete(route('admin.programs.destroy', $program))
            ->assertInertiaFlash('toast.message', "BSCS still has 1 learner and 1 section, so it can't be deleted.");
        $this->assertModelExists($program);

        $unused = Program::factory()->create();
        $this->delete(route('admin.programs.destroy', $unused))->assertInertiaFlash('toast.type', 'success');
        $this->assertModelMissing($unused);
    }
}
