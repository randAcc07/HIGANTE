<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\College;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    private College $science;

    private College $engineering;

    private CalendarEvent $holiday;

    private CalendarEvent $scienceWeek;

    private CalendarEvent $engineeringDay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-19');
        $this->science = College::factory()->create(['code' => 'COS']);
        $this->engineering = College::factory()->create(['code' => 'COEng']);
        Term::factory()->create(['starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        Term::factory()->create(['starts_on' => '2026-01-12', 'ends_on' => '2026-05-22']);

        $this->holiday = CalendarEvent::factory()->create(['title' => 'Bonifacio Day', 'starts_on' => '2026-11-30', 'ends_on' => '2026-11-30']);
        $this->scienceWeek = CalendarEvent::factory()->for($this->science)->create(['title' => 'Science Week', 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-27', 'suspends_classes' => false]);
        $this->engineeringDay = CalendarEvent::factory()->for($this->engineering)->create(['title' => 'Engineering Day', 'starts_on' => '2026-09-18', 'ends_on' => '2026-09-18']);
        CalendarEvent::factory()->create(['title' => 'Last term holiday', 'starts_on' => '2026-02-25', 'ends_on' => '2026-02-25']);
    }

    public function test_people_see_campus_events_and_their_own_colleges_for_the_current_term()
    {
        foreach (['teacher', 'learner', 'dean'] as $role) {
            $this->actingAs(User::factory()->{$role}($this->science)->create())
                ->get(route('calendar.index'))
                ->assertInertia(fn (Assert $page) => $page
                    ->component('calendar/Index')
                    ->where('term.starts_on', '2026-08-10')
                    ->where('events.0.title', 'Science Week')
                    ->where('events.0.college', 'COS')
                    ->where('events.1.title', 'Bonifacio Day')
                    ->where('events.1.college', null)
                    ->has('events', 2));
        }
    }

    public function test_the_admin_and_registrar_see_every_college()
    {
        foreach (['admin', 'registrar'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())
                ->get(route('calendar.index'))
                ->assertInertia(fn (Assert $page) => $page->has('events', 3));
        }
    }

    public function test_during_a_break_the_calendar_opens_the_coming_term()
    {
        $next = Term::factory()->create(['starts_on' => '2027-01-11', 'ends_on' => '2027-05-21']);
        $this->travelTo('2026-12-28');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('calendar.index'))
            ->assertInertia(fn (Assert $page) => $page->where('term.id', $next->id));
    }

    public function test_another_term_can_be_shown()
    {
        $previous = Term::where('starts_on', '2026-01-12')->sole();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('calendar.index', ['term' => $previous->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('term.id', $previous->id)
                ->has('events', 1)
                ->where('events.0.title', 'Last term holiday'));
    }

    public function test_the_admin_adds_campus_wide_events_and_deans_add_their_colleges()
    {
        $event = ['title' => 'Typhoon suspension', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'suspends_classes' => true];

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('calendar.events.store'), $event)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('calendar_events', ['title' => 'Typhoon suspension', 'college_id' => null, 'created_by' => $admin->id]);

        $dean = User::factory()->dean($this->science)->create();
        $this->actingAs($dean)->post(route('calendar.events.store'), [...$event, 'title' => 'Science fair', 'college_id' => $this->engineering->id])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('calendar_events', ['title' => 'Science fair', 'college_id' => $this->science->id]);
    }

    public function test_each_event_can_only_be_changed_by_whoever_keeps_it()
    {
        $admin = User::factory()->admin()->create();
        $scienceDean = User::factory()->dean($this->science)->create();
        $change = ['title' => 'Changed', 'starts_on' => '2026-11-30', 'ends_on' => '2026-11-30', 'suspends_classes' => true];

        $this->actingAs($scienceDean);
        $this->put(route('calendar.events.update', $this->holiday), $change)->assertForbidden();
        $this->put(route('calendar.events.update', $this->engineeringDay), $change)->assertForbidden();
        $this->delete(route('calendar.events.destroy', $this->engineeringDay))->assertForbidden();
        $this->put(route('calendar.events.update', $this->scienceWeek), $change)->assertSessionHasNoErrors();

        $this->actingAs($admin);
        $this->put(route('calendar.events.update', $this->engineeringDay), $change)->assertForbidden();
        $this->delete(route('calendar.events.destroy', $this->holiday))->assertSessionHasNoErrors();

        $this->assertModelMissing($this->holiday);
        $this->assertSame('Engineering Day', $this->engineeringDay->refresh()->title);
        $this->assertSame('Changed', $this->scienceWeek->refresh()->title);
    }

    public function test_the_page_says_what_each_person_may_change()
    {
        $this->actingAs(User::factory()->dean($this->science)->create())
            ->get(route('calendar.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canCreate', true)
                ->where('newEventScope', 'COS')
                ->where('events.0.can_edit', true)
                ->where('events.1.can_edit', false));

        $this->actingAs(User::factory()->teacher($this->science)->create())
            ->get(route('calendar.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canCreate', false)->where('events.0.can_edit', false));
    }

    public function test_teachers_and_learners_cannot_add_events()
    {
        foreach (['teacher', 'learner', 'registrar'] as $role) {
            $this->actingAs(User::factory()->{$role}($role === 'registrar' ? null : $this->science)->create())
                ->post(route('calendar.events.store'), ['title' => 'X', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'suspends_classes' => true])
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('calendar_events', ['title' => 'X']);
    }

    public function test_an_event_cannot_end_before_it_starts()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('calendar.events.store'), ['title' => 'X', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-19', 'suspends_classes' => false])
            ->assertSessionHasErrors(['ends_on' => 'The event cannot end before it starts.']);
    }
}
