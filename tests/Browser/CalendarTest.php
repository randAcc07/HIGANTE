<?php

use App\Models\CalendarEvent;
use Tests\Browser\Campus;

/*
 * A suspension narrowed to a year level, entered through the calendar's
 * dialog.
 */

it('adds a suspension for one year level', function () {
    $campus = Campus::build();
    $this->actingAs($campus->users['dean']);
    $day = $campus->term->starts_on->addWeek()->toDateString();

    visit(path('calendar.index', ['term' => $campus->term->id]))
        ->press('Add event')
        ->fill('#event-title', 'Job fair')
        ->fill('#event-start', $day)
        ->click('[role="dialog"] label:has-text("Year 4") button')
        ->click('[role="dialog"] label:has-text("BSCS") button')
        ->click('[role="dialog"] button:has-text("Save")')
        ->assertSee('Event added.')
        ->assertSee('No classes for BSCS year 4')
        ->assertNoSmoke();

    $event = CalendarEvent::sole();
    expect($event->year_levels)->toBe([4])
        ->and($event->programs()->pluck('code')->all())->toBe(['BSCS']);
});
