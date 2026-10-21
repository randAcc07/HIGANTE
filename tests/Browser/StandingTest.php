<?php

use App\Enums\DropReason;
use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use Tests\Browser\Campus;

/*
 * The dean's at-risk list, clicked through: a learner flagged for dropping
 * is dropped after confirming, and told.
 */

it('drops a flagged learner after confirming', function () {
    $campus = Campus::build();
    $flagged = Enrollment::where('subject_offering_id', $campus->lecture->id)
        ->where('learner_id', $campus->registrations[1]->learner_id)
        ->sole();
    $flagged->forceFill(['status' => EnrollmentStatus::ForDropping, 'warning_level' => 4, 'flagged_for_drop_at' => now()])->save();
    $this->actingAs($campus->users['dean']);

    visit(path('dean.standing.index', ['term' => $campus->term->id]))
        ->assertSee('For dropping (1)')
        ->assertSee('Bautista')
        ->click('tbody button:has-text("Drop")')
        ->assertSee('Drop '.$campus->registrations[1]->learner->user->full_name.' from CS 101?')
        ->click('[role="dialog"] button:has-text("Drop")')
        ->assertSee('is dropped from CS 101.')
        ->assertNoSmoke();

    $flagged->refresh();
    expect($flagged->status)->toBe(EnrollmentStatus::Dropped)
        ->and($flagged->drop_reason)->toBe(DropReason::Absences)
        ->and($campus->registrations[1]->learner->user->notifications()->count())->toBe(1);
});
