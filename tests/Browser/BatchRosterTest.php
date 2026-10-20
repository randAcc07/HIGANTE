<?php

use App\Models\Enrollment;
use Tests\Browser\Campus;

/*
 * The section roster's batch tools, clicked through: the client-side
 * split and moves, then the save the server checks.
 */

beforeEach(function () {
    $this->campus = Campus::build();
    $this->actingAs($this->campus->users['dean']);
});

/**
 * Each sectioned learner's batch in the lab subject, by last name.
 *
 * @return array<string, string|null>
 */
function labBatches(Campus $campus): array
{
    return Enrollment::with(['learner.user', 'batch'])
        ->where('subject_offering_id', $campus->lab->id)
        ->get()
        ->mapWithKeys(fn (Enrollment $enrollment): array => [$enrollment->learner->user->last_name => $enrollment->batch?->name])
        ->sortKeys()
        ->all();
}

it('splits learners without a batch by last name and saves', function () {
    $campus = $this->campus;

    visit(path('dean.sections.show', $campus->section))
        ->assertSee('0 unsaved changes')
        ->press('Split automatically')
        ->assertSee('Placed 3 by last name: 1-AA +2 (A–B), 1-AB +1 (C).')
        ->assertSee('3 unsaved changes')
        ->press('Save batches')
        ->assertSee('Batches saved.')
        ->assertNoSmoke();

    expect(labBatches($campus))->toBe(['Abad' => '1-AA', 'Bautista' => '1-AA', 'Cruz' => '1-AB']);
});

it('moves ticked learners to a batch', function () {
    $campus = $this->campus;

    visit(path('dean.sections.show', $campus->section))
        // The checkboxes are buttons (reka-ui), so they are clicked, not checked.
        ->click('[aria-label="Tick Abad, '.$campus->registrations[0]->learner->user->first_name.'"]')
        ->click('[aria-label="Tick Cruz, '.$campus->registrations[2]->learner->user->first_name.'"]')
        ->assertSee('2 ticked: put in')
        ->select('[aria-label="Batch to put the ticked learners in"]', '1-AB')
        ->press('Apply')
        ->press('Save batches')
        ->assertSee('Batches saved.')
        ->assertNoSmoke();

    expect(labBatches($campus))->toBe(['Abad' => '1-AB', 'Bautista' => null, 'Cruz' => '1-AB']);
});
