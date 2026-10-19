<?php

namespace Tests\Feature\Admin\Catalog;

use App\Enums\Semester;
use App\Enums\TermPhase;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TermTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $term = [
        'school_year' => '2026-2027',
        'semester' => 'first',
        'starts_on' => '2026-08-10',
        'ends_on' => '2026-12-18',
        'lates_per_absence' => 3,
        'drop_at_absences' => 5,
        'warn_at_absences' => [4, 3],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_a_term_is_saved_with_its_attendance_policy()
    {
        $this->post(route('admin.terms.store'), $this->term)->assertSessionHasNoErrors();

        $term = Term::sole();
        $this->assertSame('1st Semester 2026-2027', $term->label);
        $this->assertSame(Semester::First, $term->semester);
        $this->assertSame(3, $term->attendancePolicy?->lates_per_absence);
        $this->assertSame([3, 4], $term->attendancePolicy->warn_at_absences);
    }

    public function test_each_term_shows_its_phase_and_the_handbook_policy_is_offered()
    {
        $this->travelTo('2026-10-19');
        Term::factory()->create(['starts_on' => '2027-01-11', 'ends_on' => '2027-05-21']);
        Term::factory()->create(['starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        Term::factory()->create(['starts_on' => '2026-01-12', 'ends_on' => '2026-05-22']);
        Term::factory()->create(['starts_on' => '2025-08-11', 'ends_on' => '2025-12-19', 'closed_at' => now()]);

        $this->get(route('admin.terms.index'))->assertInertia(fn (Assert $page) => $page
            ->component('admin/terms/Index')
            ->where('terms.0.phase', 'upcoming')
            ->where('terms.1.phase', 'in_session')
            ->where('terms.1.starts_on', '2026-08-10')
            ->where('terms.2.phase', 'ended')
            ->where('terms.3.phase', 'closed')
            ->where('handbookPolicy', AttendancePolicy::HANDBOOK));
    }

    public function test_which_term_screens_open_by_default()
    {
        $first = Term::factory()->create(['starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        $second = Term::factory()->create(['starts_on' => '2027-01-11', 'ends_on' => '2027-05-21']);

        // During the first semester: viewing opens it, preparing opens the next.
        $this->travelTo('2026-10-19');
        $this->assertTrue(Term::inSession()?->is($first));
        $this->assertTrue(Term::forViewing()?->is($first));
        $this->assertTrue(Term::forPreparation()?->is($second));

        // During the break, both open the coming semester.
        $this->travelTo('2026-12-28');
        $this->assertNull(Term::inSession());
        $this->assertTrue(Term::forViewing()?->is($second));
        $this->assertTrue(Term::forPreparation()?->is($second));

        // After the last term, viewing falls back to it; nothing to prepare.
        $this->travelTo('2027-06-15');
        $this->assertTrue(Term::forViewing()?->is($second));
        $this->assertNull(Term::forPreparation());
    }

    public function test_only_an_ended_term_can_be_closed_and_a_closed_term_is_read_only()
    {
        $this->travelTo('2026-10-19');
        $running = Term::factory()->create(['starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        $ended = Term::factory()->create(['school_year' => '2025-2026', 'semester' => 'second', 'starts_on' => '2026-01-12', 'ends_on' => '2026-05-22']);
        AttendancePolicy::factory()->for($ended)->create();

        $this->post(route('admin.terms.close', $running))
            ->assertInertiaFlash('toast.message', 'Only a term that has ended can be closed.');
        $this->assertFalse($running->refresh()->isClosed());

        $this->post(route('admin.terms.close', $ended))->assertInertiaFlash('toast.type', 'success');
        $this->assertSame(TermPhase::Closed, $ended->refresh()->phase());

        $this->put(route('admin.terms.update', $ended), [...$this->term, 'school_year' => '2025-2026', 'semester' => 'second', 'starts_on' => '2026-01-12', 'ends_on' => '2026-05-29'])
            ->assertInertiaFlash('toast.message', "{$ended->label} is closed. Reopen it to make changes.");
        $this->assertSame('2026-05-22', $ended->refresh()->ends_on->toDateString());

        $this->delete(route('admin.terms.reopen', $ended))->assertInertiaFlash('toast.type', 'success');
        $this->assertSame(TermPhase::Ended, $ended->refresh()->phase());
        $this->assertNull($ended->closed_by);
    }

    public function test_dates_cannot_move_away_from_recorded_attendance()
    {
        $record = AttendanceRecord::factory()->create();
        $term = $record->schedule->term;
        AttendancePolicy::factory()->for($term)->create();
        $day = $record->class_date->toDateString();

        $this->put(route('admin.terms.update', $term), [
            ...$this->term,
            'school_year' => $term->school_year,
            'starts_on' => $record->class_date->addDay()->toDateString(),
            'ends_on' => $term->ends_on->toDateString(),
        ])->assertSessionHasErrors(['starts_on' => "Attendance is already recorded on days these dates leave out ({$day} to {$day})."]);

        $this->put(route('admin.terms.update', $term), [
            ...$this->term,
            'school_year' => $term->school_year,
            'starts_on' => $term->starts_on->toDateString(),
            'ends_on' => $term->ends_on->addWeek()->toDateString(),
        ])->assertSessionHasNoErrors();
    }

    public function test_the_school_year_and_dates_are_checked()
    {
        foreach ([
            ['school_year' => '2026/2027'],
            ['school_year' => '2026-2028'],
            ['ends_on' => '2026-08-01'],
        ] as $override) {
            $this->post(route('admin.terms.store'), [...$this->term, ...$override])
                ->assertSessionHasErrors(array_key_first($override));
        }

        $this->assertDatabaseCount('terms', 0);
    }

    public function test_a_semester_appears_once_per_school_year_and_terms_never_overlap()
    {
        Term::factory()->create(['school_year' => '2026-2027', 'semester' => 'first', 'starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);

        $this->post(route('admin.terms.store'), [...$this->term, 'starts_on' => '2027-01-11', 'ends_on' => '2027-05-21'])
            ->assertSessionHasErrors(['semester' => 'That school year already has this semester.']);

        $this->post(route('admin.terms.store'), [...$this->term, 'semester' => 'second', 'starts_on' => '2026-12-01', 'ends_on' => '2027-05-21'])
            ->assertSessionHasErrors(['starts_on' => 'These dates overlap 1st Semester 2026-2027.']);

        $this->post(route('admin.terms.store'), [...$this->term, 'semester' => 'second', 'starts_on' => '2027-01-11', 'ends_on' => '2027-05-21'])
            ->assertSessionHasNoErrors();
    }

    public function test_warnings_must_come_before_the_drop_count()
    {
        $this->post(route('admin.terms.store'), [...$this->term, 'warn_at_absences' => [3, 5]])
            ->assertSessionHasErrors(['warn_at_absences' => 'Every warning must come before the drop count.']);

        $this->post(route('admin.terms.store'), [...$this->term, 'warn_at_absences' => []])
            ->assertSessionHasNoErrors();
        $this->assertSame([], Term::sole()->attendancePolicy?->warn_at_absences);
    }

    public function test_a_term_and_its_policy_can_be_edited()
    {
        $term = Term::factory()->create(['school_year' => '2026-2027', 'starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        AttendancePolicy::factory()->for($term)->create();

        $this->put(route('admin.terms.update', $term), [...$this->term, 'ends_on' => '2026-12-19', 'drop_at_absences' => 6, 'warn_at_absences' => [3, 4, 5]])
            ->assertSessionHasNoErrors();

        $term->refresh();
        $this->assertSame('2026-12-19', $term->ends_on->toDateString());
        $this->assertSame(6, $term->attendancePolicy?->drop_at_absences);
        $this->assertSame([3, 4, 5], $term->attendancePolicy->warn_at_absences);
        $this->assertDatabaseCount('attendance_policies', 1);
    }

    public function test_a_term_in_use_is_not_deleted()
    {
        $section = Section::factory()->create();
        $term = $section->term;

        $this->delete(route('admin.terms.destroy', $term))
            ->assertInertiaFlash('toast.message', "{$term->label} still has 1 section, so it can't be deleted.");
        $this->assertModelExists($term);

        $unused = Term::factory()->create();
        AttendancePolicy::factory()->for($unused)->create();
        $this->delete(route('admin.terms.destroy', $unused));
        $this->assertModelMissing($unused);
        $this->assertDatabaseMissing('attendance_policies', ['term_id' => $unused->id]);
    }

    public function test_only_the_admin_manages_terms()
    {
        foreach (['registrar', 'dean', 'teacher', 'learner'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create());
            $this->get(route('admin.terms.index'))->assertForbidden();
            $this->post(route('admin.terms.store'), $this->term)->assertForbidden();
        }

        $this->assertDatabaseCount('terms', 0);
    }
}
