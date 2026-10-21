<?php

use App\Enums\Semester;
use App\Models\Section;
use App\Models\Term;
use Tests\Browser\Campus;

/*
 * Copying last term's sections, clicked through: a new school year shifts
 * the block up a year, and the copy remembers where it came from.
 */

beforeEach(function () {
    $this->campus = Campus::build();
    $this->actingAs($this->campus->users['dean']);
});

it('copies last school years block one year up', function () {
    $campus = $this->campus;
    [$from] = explode('-', $campus->term->school_year);
    $previous = Term::factory()->create([
        'school_year' => ($from - 1).'-'.$from,
        'semester' => Semester::Second,
        'starts_on' => $campus->term->starts_on->subMonths(7),
        'ends_on' => $campus->term->starts_on->subMonths(2),
    ]);
    $source = Section::factory()->for($previous)->for($campus->program)->create(['year_level' => 1, 'name' => '1-C', 'capacity' => 30]);

    visit(path('dean.sections.index', ['term' => $campus->term->id]))
        ->press('Copy sections')
        ->assertSee('each block moves up a year level')
        ->press('Copy 1 section')
        ->assertSee('Copied 1 section.')
        ->assertNoSmoke();

    $copy = Section::where('term_id', $campus->term->id)->where('previous_section_id', $source->id)->sole();
    expect([$copy->name, $copy->year_level, $copy->capacity])->toBe(['2-C', 2, 30]);
});
