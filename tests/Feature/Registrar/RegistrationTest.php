<?php

namespace Tests\Feature\Registrar;

use App\Enums\EnrollmentStatus;
use App\Enums\LearnerStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Semester;
use App\Models\AttendanceRecord;
use App\Models\College;
use App\Models\Enrollment;
use App\Models\Learner;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $registrar;

    private Program $program;

    private Term $lastYear;

    private Term $first;

    private Term $second;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo('2026-07-01');
        $this->program = Program::factory()->for(College::factory())->create(['code' => 'BSCS']);
        $this->lastYear = Term::factory()->create(['school_year' => '2025-2026', 'semester' => Semester::Second, 'starts_on' => '2026-01-12', 'ends_on' => '2026-05-22']);
        $this->first = Term::factory()->create(['school_year' => '2026-2027', 'semester' => Semester::First, 'starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        $this->second = Term::factory()->create(['school_year' => '2026-2027', 'semester' => Semester::Second, 'starts_on' => '2027-01-11', 'ends_on' => '2027-05-21']);
        $this->registrar = User::factory()->registrar()->create();
        $this->actingAs($this->registrar);
    }

    private function learner(int $yearLevel = 1, array $override = []): Learner
    {
        return Learner::factory()->for($this->program)->create(['year_level' => $yearLevel, ...$override]);
    }

    private function section(Term $term, string $name, int $yearLevel = 2, ?int $capacity = null): Section
    {
        return Section::factory()->for($term)->for($this->program)->create(['name' => $name, 'year_level' => $yearLevel, 'capacity' => $capacity]);
    }

    private function offering(Section $section, string $code): SubjectOffering
    {
        return SubjectOffering::factory()->for($section)->create([
            'subject_id' => Subject::factory()->for($this->program->college)->create(['code' => $code])->id,
        ]);
    }

    private function registered(Learner $learner, Term $term, array $override = []): TermRegistration
    {
        return TermRegistration::factory()->for($learner)->for($term)->create($override);
    }

    /**
     * @param  list<Learner>  $learners
     */
    private function register(Term $term, array $learners, int $yearLevel = 2, bool $irregular = false): TestResponse
    {
        return $this->post(route('registrar.registrations.store', $term), [
            'entries' => array_map(fn (Learner $learner): array => ['learner_id' => $learner->id, 'year_level' => $yearLevel, 'is_irregular' => $irregular], $learners),
        ]);
    }

    public function test_the_first_semester_lists_last_years_learners_a_year_up_and_unticked()
    {
        $returning = $this->learner(1);
        $this->registered($returning, $this->lastYear, ['year_level' => 1]);
        $returnee = $this->learner(2);
        $graduate = $this->learner(4, ['status' => LearnerStatus::Graduated]);
        $this->registered($graduate, $this->lastYear, ['year_level' => 4]);
        $done = $this->learner(1);
        $this->registered($done, $this->lastYear, ['year_level' => 1]);
        $this->registered($done, $this->first, ['year_level' => 2]);

        $this->get(route('registrar.registrations.index', ['term' => $this->first->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('registrar/registrations/Index')
                ->has('candidates.data', 1)
                ->where('candidates.data.0.learner.id', $returning->id)
                ->where('candidates.data.0.year_level', 2)
                ->where('candidates.data.0.ticked', false));

        $this->get(route('registrar.registrations.index', ['term' => $this->first->id, 'source' => 'others']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 1)
                ->where('candidates.data.0.learner.id', $returnee->id)
                ->where('candidates.data.0.year_level', 2));
    }

    public function test_the_second_semester_carries_the_first_over_pre_ticked()
    {
        $stayed = $this->learner(2);
        $this->registered($stayed, $this->first, ['year_level' => 2, 'is_irregular' => true]);
        $left = $this->learner(2);
        $this->registered($left, $this->first, ['year_level' => 2, 'status' => RegistrationStatus::Withdrawn]);

        $this->get(route('registrar.registrations.index', ['term' => $this->second->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 2)
                ->where('candidates.data', fn ($rows) => collect($rows)
                    ->mapWithKeys(fn (array $row): array => [$row['learner']['id'] => [$row['ticked'], $row['year_level'], $row['is_irregular']]])
                    ->sortKeys()
                    ->all() === collect([$stayed->id => [true, 2, true], $left->id => [false, 2, false]])->sortKeys()->all()));
    }

    public function test_registering_places_each_learner_in_the_emptiest_section_with_room_and_enrolls_regulars()
    {
        $a = $this->section($this->first, '2-A', capacity: 2);
        $b = $this->section($this->first, '2-B', capacity: 1);
        $cs = $this->offering($a, 'CS 201');
        $this->offering($a, 'CS 202');
        $this->offering($b, 'CS 211');
        [$one, $two, $three, $four] = [$this->learner(1), $this->learner(1), $this->learner(1), $this->learner(1)];

        $this->register($this->first, [$one, $two, $three, $four])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'warning')
            ->assertInertiaFlash('toast.message', 'Registered 4 learners. 1 has no section with room and waits for the dean.');

        // Ties go to the first by name: A, B (now full), A, then nowhere.
        $sectionOf = fn (Learner $learner) => TermRegistration::where('learner_id', $learner->id)->value('section_id');
        $this->assertSame([$a->id, $b->id, $a->id, null], array_map($sectionOf, [$one, $two, $three, $four]));
        $this->assertSame(2, Enrollment::where('learner_id', $one->id)->count());
        $this->assertSame(1, Enrollment::where('learner_id', $two->id)->count());
        $this->assertSame(0, Enrollment::where('learner_id', $four->id)->count());
        $this->assertSame(2, $one->refresh()->year_level);
        $this->assertSame($this->registrar->id, TermRegistration::where('learner_id', $one->id)->value('registered_by'));
        $this->assertTrue($cs->enrollments()->where('learner_id', $three->id)->exists());
    }

    public function test_irregular_learners_get_a_section_but_no_subjects()
    {
        $section = $this->section($this->first, '2-A');
        $this->offering($section, 'CS 201');
        $learner = $this->learner(1);

        $this->register($this->first, [$learner], irregular: true)->assertSessionHasNoErrors();

        $registration = TermRegistration::where('learner_id', $learner->id)->sole();
        $this->assertSame($section->id, $registration->section_id);
        $this->assertTrue($registration->is_irregular);
        $this->assertSame(0, Enrollment::where('learner_id', $learner->id)->count());

        // Made regular, they take the section's subjects.
        $this->put(route('registrar.registrations.update', $registration), ['is_irregular' => false])->assertSessionHasNoErrors();
        $this->assertSame(1, Enrollment::where('learner_id', $learner->id)->count());
    }

    public function test_the_second_semester_keeps_the_block_even_past_capacity()
    {
        $firstA = $this->section($this->first, '2-A');
        $this->section($this->first, '2-B');
        $learner = $this->learner(2);
        $this->registered($learner, $this->first, ['year_level' => 2, 'section_id' => $firstA->id]);
        $this->section($this->second, '2-B');
        $secondA = $this->section($this->second, '2-A', capacity: 1);
        $this->registered($this->learner(2), $this->second, ['year_level' => 2, 'section_id' => $secondA->id]);

        $this->register($this->second, [$learner])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Registered 1 learner. BSCS 2-A is now over its capacity of 1.');

        $this->assertSame($secondA->id, TermRegistration::where('learner_id', $learner->id)->where('term_id', $this->second->id)->value('section_id'));
    }

    public function test_subjects_added_later_enroll_the_sections_regular_learners()
    {
        $section = $this->section($this->first, '2-A');
        $regular = $this->registered($this->learner(2), $this->first, ['section_id' => $section->id]);
        $this->registered($this->learner(2), $this->first, ['section_id' => $section->id, 'is_irregular' => true]);
        $this->registered($this->learner(2), $this->first, ['section_id' => $section->id, 'status' => RegistrationStatus::Withdrawn]);
        $this->actingAs(User::factory()->dean($this->program->college)->create());

        $this->post(route('dean.offerings.store', $section), [
            'subject_id' => Subject::factory()->for($this->program->college)->create()->id,
            'teacher_id' => null,
        ])->assertInertiaFlash('toast.message', 'Subject added; 1 regular learner enrolled.');

        $this->assertSame([$regular->learner_id], Enrollment::pluck('learner_id')->all());
    }

    public function test_withdrawal_keeps_the_registration_and_drops_the_classes()
    {
        $section = $this->section($this->first, '2-A');
        $enrollment = Enrollment::factory()->for($this->offering($section, 'CS 201'))->create();
        $registration = $this->registered($enrollment->learner, $this->first, ['section_id' => $section->id]);

        $this->post(route('registrar.registrations.withdraw', $registration))->assertSessionHasNoErrors();

        $this->assertSame(RegistrationStatus::Withdrawn, $registration->refresh()->status);
        $enrollment->refresh();
        $this->assertSame(EnrollmentStatus::Dropped, $enrollment->status);
        $this->assertSame($this->registrar->id, $enrollment->drop_confirmed_by);
    }

    public function test_a_mistaken_registration_is_undone_until_attendance_is_recorded()
    {
        $section = $this->section($this->first, '2-A');
        $this->offering($section, 'CS 201');
        $learner = $this->learner(1);
        $this->registered($learner, $this->lastYear, ['year_level' => 1]);
        $this->register($this->first, [$learner])->assertSessionHasNoErrors();
        $registration = TermRegistration::where('term_id', $this->first->id)->sole();
        $this->assertSame(2, $learner->refresh()->year_level);

        $this->delete(route('registrar.registrations.destroy', $registration))->assertSessionHasNoErrors();
        $this->assertModelMissing($registration);
        $this->assertSame(0, Enrollment::count());
        $this->assertSame(1, $learner->refresh()->year_level);

        $this->register($this->first, [$learner])->assertSessionHasNoErrors();
        $registration = TermRegistration::where('term_id', $this->first->id)->sole();
        AttendanceRecord::factory()->for(Enrollment::sole())->create();
        $this->delete(route('registrar.registrations.destroy', $registration))
            ->assertInertiaFlash('toast.type', 'error');
        $this->assertModelExists($registration);
    }

    public function test_only_active_unregistered_learners_can_be_registered()
    {
        $inactive = $this->learner(1, ['status' => LearnerStatus::Inactive]);
        $this->register($this->first, [$inactive])->assertSessionHasErrors('learner');

        $twice = $this->learner(1);
        $this->registered($twice, $this->first);
        $this->register($this->first, [$twice])->assertSessionHasErrors('learner');
    }

    public function test_an_ended_term_and_other_roles_are_refused()
    {
        $learner = $this->learner(1);
        $this->register($this->lastYear, [$learner])->assertForbidden();

        $registration = $this->registered($learner, $this->lastYear);
        $this->post(route('registrar.registrations.withdraw', $registration))->assertForbidden();
        $this->delete(route('registrar.registrations.destroy', $registration))->assertForbidden();

        $this->actingAs(User::factory()->dean($this->program->college)->create());
        $this->register($this->first, [$learner])->assertForbidden();
        $this->get(route('registrar.registrations.index'))->assertForbidden();
    }

    public function test_the_registered_list_filters_unsectioned_learners()
    {
        $section = $this->section($this->first, '2-A');
        $this->registered($this->learner(2), $this->first, ['section_id' => $section->id]);
        $waiting = $this->registered($this->learner(2), $this->first);

        $this->get(route('registrar.registrations.index', ['term' => $this->first->id, 'view' => 'registered', 'unsectioned' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('registrations.data', 1)
                ->where('registrations.data.0.id', $waiting->id)
                ->where('counts', ['registered' => 2, 'unsectioned' => 1, 'withdrawn' => 0]));
    }

    public function test_admission_can_register_the_learner_right_away()
    {
        $section = $this->section($this->first, '1-A', yearLevel: 1);
        $this->offering($section, 'CS 101');

        $this->post(route('registrar.learners.store'), [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'email' => 'juan@example.com',
            'student_number' => 'M2026-04821', 'program_id' => $this->program->id, 'year_level' => 1, 'status' => 'active',
            'register_term_id' => $this->first->id, 'is_irregular' => false,
        ])->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Registered for 1st Semester 2026-2027 in BSCS 1-A.');

        $learner = Learner::where('student_number', 'M2026-04821')->sole();
        $this->assertSame($section->id, $learner->termRegistrations()->sole()->section_id);
        $this->assertSame(1, $learner->enrollments()->count());

        $this->post(route('registrar.learners.store'), [
            'first_name' => 'Ana', 'last_name' => 'Reyes', 'email' => 'ana@example.com',
            'student_number' => 'M2026-04822', 'program_id' => $this->program->id, 'year_level' => 1, 'status' => 'graduated',
            'register_term_id' => $this->first->id,
        ])->assertSessionHasErrors(['register_term_id' => 'Only an active learner can be registered.']);
    }
}
