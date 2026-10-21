<?php

use App\Models\ClassCover;
use App\Models\Teacher;
use Carbon\CarbonImmutable;
use Tests\Browser\Campus;

/*
 * Arranging cover, clicked through: the teacher and dates bring up their
 * classes, one substitute is set for all, and the covers are saved.
 */

beforeEach(function () {
    $this->campus = Campus::build();
    $this->actingAs($this->campus->users['dean']);
});

it('arranges one substitute for a teachers week', function () {
    $campus = $this->campus;
    $substitute = Teacher::factory()->forCollege($campus->college)->create();
    $from = CarbonImmutable::today()->addDay();

    visit(path('dean.covers.index'))
        ->press('Arrange cover')
        ->select('#cover-teacher', (string) $campus->teacher->id)
        ->type('#cover-from', $from->toDateString())
        ->type('#cover-to', $from->addDays(6)->toDateString())
        ->assertSee('Same substitute for all')
        ->select('#cover-everyone', (string) $substitute->id)
        ->press('Arrange cover for 3 classes')
        ->assertSee('Cover arranged for 3 classes')
        ->assertNoSmoke();

    expect(ClassCover::where('substitute_id', $substitute->id)->count())->toBe(3);
});
