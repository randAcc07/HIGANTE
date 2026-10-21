<?php

use App\Models\Subject;
use Tests\Browser\Campus;

/*
 * The curriculum's add dialog, clicked through: subjects ticked in the
 * dialog land in the chosen year and semester.
 */

beforeEach(function () {
    $this->campus = Campus::build();
    $this->actingAs($this->campus->users['dean']);
});

it('adds ticked subjects to a year and semester', function () {
    Subject::factory()->for($this->campus->college)->create(['code' => 'CS 201', 'title' => 'Data Structures']);

    visit(path('dean.curriculum.index'))
        ->press('Add subjects')
        ->select('#curriculum-year', '2')
        ->select('#curriculum-semester', 'second')
        ->click('Data Structures')
        ->click('Introduction to Computing')
        ->press('Add 2')
        ->assertSee('2 subjects added.')
        ->assertSee('Year 2 · 2nd Semester')
        ->assertNoSmoke();

    expect($this->campus->program->curriculum()->with('subject')->get()->pluck('subject.code')->sort()->values()->all())->toBe(['CS 101', 'CS 201']);
});
