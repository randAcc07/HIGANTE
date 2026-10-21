<?php

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use Tests\Browser\Campus;

/*
 * Taking attendance in the browser: everyone counts as present, the teacher
 * marks the exceptions and saves, and a later correction is saved as one.
 */

it('takes attendance and corrects a mark', function () {
    $campus = Campus::build();
    $this->actingAs($campus->users['teacher']);
    $date = $campus->lastLecture();
    $cruz = $campus->registrations[2]->learner->user;

    $page = visit(path('teacher.classes.index', ['date' => $date]))
        ->assertSee('All 3 present')
        ->click('CS 101')
        ->assertSee('Everyone counts as present unless marked otherwise.')
        ->assertSee('Present: 3')
        ->click('[aria-label="Cruz, '.$cruz->first_name.'\'s mark"] >> text=Late')
        ->assertSee('Late: 1')
        ->assertSee('1 unsaved change')
        ->press('Save changes')
        ->assertSee('1 mark saved.');

    $status = fn () => AttendanceRecord::whereHas('enrollment', fn ($query) => $query->where('learner_id', $campus->registrations[2]->learner_id))->sole()->status;
    expect(AttendanceRecord::count())->toBe(1)
        ->and($status())->toBe(AttendanceStatus::Late);

    $page->click('[aria-label="Cruz, '.$cruz->first_name.'\'s mark"] >> text=Absent')
        ->assertSee('1 unsaved change')
        ->press('Save changes')
        ->assertSee('1 mark saved.')
        ->assertNoSmoke();

    expect($status())->toBe(AttendanceStatus::Absent);
});
