<?php

use App\Enums\ExcuseReason;
use App\Enums\ExcuseStatus;
use App\Models\AbsenceExcuse;
use Tests\Browser\Campus;

/*
 * Excuses clicked through: the learner submits one, the dean approves one.
 * (One visit per test: a second visit() shows the first page again.)
 */

it('lets a learner submit an excuse', function () {
    $campus = Campus::build();
    $day = $campus->lastLecture();
    $this->actingAs($campus->users['learner']);

    visit(path('learner.excuses.index'))
        ->press('Submit an excuse')
        ->fill('#excuse-from', $day)
        ->select('#excuse-reason', 'school_activity')
        ->fill('#excuse-remarks', 'Campus quiz bee.')
        ->click('[role="dialog"] button:has-text("Submit")')
        ->assertSee('Excuse submitted. Your dean will review it.')
        ->assertSee('Pending')
        ->assertNoSmoke();

    $excuse = AbsenceExcuse::sole();
    expect($excuse->date_from->toDateString())->toBe($day)
        ->and($excuse->date_to->toDateString())->toBe($day)
        ->and($excuse->reason)->toBe(ExcuseReason::SchoolActivity);
});

it('lets the dean approve an excuse', function () {
    $campus = Campus::build();
    $day = $campus->lastLecture();
    $excuse = AbsenceExcuse::factory()->create([
        'learner_id' => $campus->registrations[0]->learner_id,
        'date_from' => $day, 'date_to' => $day,
        'reason' => ExcuseReason::SchoolActivity, 'status' => ExcuseStatus::Pending,
        'remarks' => 'Campus quiz bee.',
    ]);
    $this->actingAs($campus->users['dean']);

    visit(path('dean.excuses.show', $excuse))
        ->assertSee('Campus quiz bee.')
        ->press('Approve')
        ->click('[role="dialog"] button:has-text("Approve")')
        ->assertSee('Excuse approved.')
        ->assertNoSmoke();

    expect($excuse->refresh()->status)->toBe(ExcuseStatus::Approved);
});
