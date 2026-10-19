<?php

namespace Tests\Feature\Admin\Catalog;

use App\Models\College;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_subjects_can_be_filtered_by_college_and_searched()
    {
        $science = College::factory()->create();
        $education = College::factory()->create();
        Subject::factory()->for($science)->create(['code' => 'CS 101', 'title' => 'Introduction to Computing']);
        Subject::factory()->for($science)->create(['code' => 'BIO 101', 'title' => 'General Biology']);
        Subject::factory()->for($education)->create(['code' => 'EDUC 101', 'title' => 'The Teaching Profession']);

        $this->get(route('admin.subjects.index', ['college' => $science->id]))
            ->assertInertia(fn (Assert $page) => $page->component('admin/subjects/Index')->has('subjects.data', 2));

        $this->get(route('admin.subjects.index', ['search' => 'comput']))
            ->assertInertia(fn (Assert $page) => $page->has('subjects.data', 1)->where('subjects.data.0.code', 'CS 101'));
    }

    public function test_subjects_are_paginated()
    {
        Subject::factory()->count(30)->create();

        $this->get(route('admin.subjects.index'))->assertInertia(fn (Assert $page) => $page
            ->has('subjects.data', 25)
            ->where('subjects.total', 30)
            ->where('subjects.last_page', 2));
    }

    public function test_the_same_code_can_exist_in_two_colleges_but_not_twice_in_one()
    {
        $science = College::factory()->create();
        $education = College::factory()->create();
        Subject::factory()->for($science)->create(['code' => 'GE 1']);

        $this->post(route('admin.subjects.store'), ['college_id' => $education->id, 'code' => 'GE 1', 'title' => 'Understanding the Self', 'lecture_units' => 3, 'laboratory_units' => 0])
            ->assertSessionHasNoErrors();

        $this->post(route('admin.subjects.store'), ['college_id' => $science->id, 'code' => 'GE 1', 'title' => 'Again', 'lecture_units' => 3, 'laboratory_units' => 0])
            ->assertSessionHasErrors(['code' => 'This college already has a subject with that code.']);

        $this->assertDatabaseCount('subjects', 2);
    }

    public function test_units_must_add_up_to_something_and_have_one_decimal_at_most()
    {
        $college = College::factory()->create();
        $subject = ['college_id' => $college->id, 'code' => 'X 1', 'title' => 'X'];

        $this->post(route('admin.subjects.store'), [...$subject, 'lecture_units' => 0, 'laboratory_units' => 0])
            ->assertSessionHasErrors(['lecture_units' => 'A subject needs lecture or laboratory units.']);

        $this->post(route('admin.subjects.store'), [...$subject, 'lecture_units' => 2.25, 'laboratory_units' => 0])
            ->assertSessionHasErrors('lecture_units');

        $this->post(route('admin.subjects.store'), [...$subject, 'lecture_units' => 2.5, 'laboratory_units' => 1])
            ->assertSessionHasNoErrors();
    }

    public function test_editing_a_subject_never_moves_it_to_another_college()
    {
        $subject = Subject::factory()->create(['title' => 'Old title']);
        $other = College::factory()->create();

        $this->put(route('admin.subjects.update', $subject), ['college_id' => $other->id, 'code' => $subject->code, 'title' => 'New title', 'lecture_units' => 3, 'laboratory_units' => 1])
            ->assertSessionHasNoErrors();

        $subject->refresh();
        $this->assertSame('New title', $subject->title);
        $this->assertNotSame($other->id, $subject->college_id);
        $this->assertSame('1.0', $subject->laboratory_units);
    }

    public function test_an_offered_subject_is_not_deleted()
    {
        $offering = SubjectOffering::factory()->create();
        $subject = $offering->subject;

        $this->delete(route('admin.subjects.destroy', $subject))
            ->assertInertiaFlash('toast.message', "{$subject->code} still has 1 offering, so it can't be deleted.");
        $this->assertModelExists($subject);

        $unused = Subject::factory()->create();
        $this->delete(route('admin.subjects.destroy', $unused));
        $this->assertModelMissing($unused);
    }
}
