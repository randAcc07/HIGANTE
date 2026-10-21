<?php

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Tests\Browser\Campus;

/*
 * The admin's month grid, clicked through: an event opens the Calendar at
 * its entry, which blinks.
 */

it('opens an event from the month on the calendar', function () {
    $campus = Campus::build();
    $this->actingAs($campus->users['admin']);
    $event = CalendarEvent::factory()->create([
        'title' => 'Intramurals opening',
        'starts_on' => CarbonImmutable::today()->addDay(),
        'ends_on' => CarbonImmutable::today()->addDay(),
        'suspends_classes' => false,
    ]);

    visit(path('dashboard', ['month' => CarbonImmutable::today()->addDay()->format('Y-m')]))
        ->assertSee('Never signed in')
        ->click('Intramurals opening')
        ->assertAttribute("#event-{$event->id}", 'data-highlighted', 'true')
        ->assertNoSmoke();
});
