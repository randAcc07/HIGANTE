<?php

namespace Tests\Feature\Attendance;

use App\Attendance\ClassDays;
use App\Models\CalendarEvent;
use App\Models\College;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Suspensions narrowed to programs and/or year levels cancel only the
 * classes of matching sections (by the class's section, not the learner).
 */
class SuspensionScopeTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-09-21';

    private College $college;

    private Term $term;

    private Program $bscs;

    private Program $bsit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-30');
        $this->college = College::factory()->create();
        $this->bscs = Program::factory()->for($this->college)->create(['code' => 'BSCS']);
        $this->bsit = Program::factory()->for($this->college)->create(['code' => 'BSIT']);
        $this->term = Term::factory()->create(['starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
    }

    private function classOf(Program $program, int $year): Schedule
    {
        $section = Section::factory()->for($this->term)->for($program)->create(['year_level' => $year]);

        return Schedule::factory()->for(SubjectOffering::factory()->for($section))->slot(1, '08:00', '09:30')->create();
    }

    /**
     * @param  list<Program>  $programs
     * @param  list<int>|null  $years
     */
    private function suspend(array $programs = [], ?array $years = null, ?int $collegeId = null): void
    {
        $event = CalendarEvent::factory()->create([
            'college_id' => $collegeId, 'title' => 'Job fair', 'starts_on' => self::MONDAY, 'ends_on' => self::MONDAY,
            'suspends_classes' => true, 'year_levels' => $years,
        ]);
        $event->programs()->sync(array_map(fn (Program $program) => $program->id, $programs));
    }

    private function suspended(Schedule $class): bool
    {
        return (new ClassDays)->suspension($class, CarbonImmutable::parse(self::MONDAY)) !== null;
    }

    public function test_year_levels_narrow_a_suspension_by_the_classs_section()
    {
        $fourth = $this->classOf($this->bscs, 4);
        $third = $this->classOf($this->bscs, 3);
        $this->suspend(years: [4]);

        $this->assertTrue($this->suspended($fourth));
        $this->assertFalse($this->suspended($third));
    }

    public function test_programs_and_year_levels_apply_together()
    {
        $bscs4 = $this->classOf($this->bscs, 4);
        $bsit4 = $this->classOf($this->bsit, 4);
        $bscs3 = $this->classOf($this->bscs, 3);
        $this->suspend([$this->bscs], [4], $this->college->id);

        $this->assertSame([true, false, false], [$this->suspended($bscs4), $this->suspended($bsit4), $this->suspended($bscs3)]);
    }

    public function test_an_unnarrowed_suspension_covers_everyone_as_before()
    {
        $class = $this->classOf($this->bsit, 1);
        $this->suspend();

        $this->assertTrue($this->suspended($class));
    }

    public function test_a_dean_narrows_to_their_own_programs_and_the_admin_to_any()
    {
        $other = Program::factory()->create(['code' => 'BSED']);
        $event = fn (array $data) => ['title' => 'Job fair', 'starts_on' => self::MONDAY, 'ends_on' => self::MONDAY, 'suspends_classes' => true, ...$data];

        $this->actingAs(User::factory()->dean($this->college)->create());
        $this->post(route('calendar.events.store'), $event(['program_ids' => [$other->id]]))->assertSessionHasErrors(['program_ids.0' => 'Choose programs of your college.']);
        $this->post(route('calendar.events.store'), $event(['suspends_classes' => false, 'year_levels' => [4]]))->assertSessionHasErrors('year_levels');
        $this->post(route('calendar.events.store'), $event(['program_ids' => [$this->bscs->id], 'year_levels' => [4, 3]]))->assertSessionHasNoErrors();

        $saved = CalendarEvent::sole();
        $this->assertSame([3, 4], $saved->year_levels);
        $this->assertSame([$this->bscs->id], $saved->programs()->pluck('programs.id')->all());

        $this->actingAs(User::factory()->admin()->create());
        $this->post(route('calendar.events.store'), $event(['program_ids' => [$other->id, $this->bsit->id]]))->assertSessionHasNoErrors();

        $this->get(route('calendar.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)->pluck('programs')->map(fn ($codes) => collect($codes)->all())->contains(['BSED', 'BSIT']))
                ->has('programOptions', 2));
    }
}
