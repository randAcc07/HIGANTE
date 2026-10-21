<?php

namespace Tests\Feature;

use App\Enums\Semester;
use App\Models\College;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Every term-scoped page opens on the term in session, even while the next
 * one exists; pages that prepare a term offer a shortcut to it.
 */
class TermDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_open_on_the_term_in_session_and_offer_the_next()
    {
        $this->travelTo('2026-10-21');
        $current = Term::factory()->create(['school_year' => '2026-2027', 'semester' => Semester::First, 'starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        $next = Term::factory()->create(['school_year' => '2026-2027', 'semester' => Semester::Second, 'starts_on' => '2027-01-11', 'ends_on' => '2027-05-21']);
        $dean = User::factory()->dean(College::factory()->create())->create();
        $registrar = User::factory()->registrar()->create();

        $pages = [
            [$dean, 'dean.sections.index', true],
            [$dean, 'dean.timetables.index', true],
            [$dean, 'dean.learners.index', true],
            [$registrar, 'registrar.registrations.index', true],
            [$dean, 'dean.attendance.index', false],
            [$dean, 'dean.standing.index', false],
            [$dean, 'dean.covers.index', false],
        ];

        foreach ($pages as [$user, $route, $prepares]) {
            $this->actingAs($user)->get(route($route))->assertInertia(fn (Assert $page) => $page
                ->where('term.id', $current->id)
                ->when($prepares, fn (Assert $page) => $page->where('nextTerm', ['id' => $next->id, 'label' => $next->label])));
        }

        // On the next term itself, there is nothing to jump to.
        $this->actingAs($dean)->get(route('dean.sections.index', ['term' => $next->id]))
            ->assertInertia(fn (Assert $page) => $page->where('term.id', $next->id)->where('nextTerm', null));
    }
}
