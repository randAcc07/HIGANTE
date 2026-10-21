<?php

use Tests\Browser\Campus;

/*
 * The dashboard's week, clicked through: stepping to the next week and
 * back to this one.
 */

it('steps the teachers week forward and back', function () {
    $campus = Campus::build();
    $this->actingAs($campus->users['teacher']);

    visit(path('dashboard'))
        ->assertSee('current week')
        ->click('[aria-label="Next week"]')
        ->assertDontSee('current week')
        ->press('This week')
        ->assertSee('current week')
        ->assertNoSmoke();
});
