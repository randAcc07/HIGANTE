<?php

use App\Enums\DecisionStatus;
use App\Enums\ExcuseReason;
use App\Models\AbsenceExcuse;
use Tests\Browser\Campus;

/*
 * Excuses clicked through: the learner submits one, and the class's
 * teacher approves it for their class.
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
        ->assertSee('Excuse submitted. Each of your teachers decides for their class.')
        ->assertSee('Waiting for the teacher')
        ->assertNoSmoke();

    $excuse = AbsenceExcuse::sole();
    expect($excuse->date_from->toDateString())->toBe($day)
        ->and($excuse->reason)->toBe(ExcuseReason::SchoolActivity)
        ->and($excuse->decisions()->count())->toBeGreaterThan(0);
});

it('lets the class teacher approve an excuse for their class', function () {
    $campus = Campus::build();
    $day = $campus->lastLecture();
    $excuse = AbsenceExcuse::factory()->create([
        'learner_id' => $campus->registrations[0]->learner_id,
        'date_from' => $day, 'date_to' => $day,
        'reason' => ExcuseReason::SchoolActivity,
        'remarks' => 'Campus quiz bee.',
    ]);
    $decision = $excuse->decisions()->create(['subject_offering_id' => $campus->lecture->id, 'status' => DecisionStatus::Pending]);
    $this->actingAs($campus->users['teacher']);

    visit(path('excuses.decisions.show', $decision))
        ->assertSee('Campus quiz bee.')
        ->press('Approve')
        ->click('[role="dialog"] button:has-text("Approve")')
        ->assertSee('Approved for this class.')
        ->assertNoSmoke();

    expect($decision->refresh()->status)->toBe(DecisionStatus::Approved);
});
