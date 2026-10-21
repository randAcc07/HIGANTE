<?php

namespace Database\Seeders\Demo;

use App\Enums\RegistrationStatus;
use App\Enums\UserRole;
use App\Models\Program;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use App\Scheduling\CurriculumFill;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Last school year, for the record: both of its terms get sections and a
 * registration for every learner registered this term in years 2 to 4
 * (withdrawals included), one year level lower. A learner keeps the same
 * section in both semesters. The sections are chained as copies: each 2nd
 * semester's from the 1st's, and this term's (from year 2 up) from last 2nd
 * semester's a year lower. Each section has its subjects from the
 * curriculum with its learners enrolled. There are no teachers, schedules
 * or attendance.
 */
class PastTermSeeder extends Seeder
{
    public function run(): void
    {
        $current = CampusData::currentTerm();
        $terms = Term::where('school_year', CampusData::term(CampusData::PAST_TERMS[0])['school_year'])->orderBy('starts_on')->get();
        $registrar = User::where('role', UserRole::Registrar)->sole();

        $learners = TermRegistration::query()
            ->join('learners', 'learners.id', '=', 'term_registrations.learner_id')
            ->where('term_registrations.term_id', $current->id)
            ->where('term_registrations.year_level', '>=', 2)
            ->orderBy('term_registrations.learner_id')
            ->get(['term_registrations.learner_id', 'term_registrations.year_level', 'learners.program_id'])
            ->groupBy(fn (TermRegistration $registration): string => $registration->getAttribute('program_id').'-'.($registration->year_level - 1));

        $rows = [];

        foreach (Program::orderBy('id')->get() as $program) {
            foreach ([1, 2, 3] as $yearLevel) {
                $year = CampusData::YEAR_LEVELS[$yearLevel];
                $group = $learners->get("{$program->id}-{$yearLevel}")?->pluck('learner_id')->all() ?? [];
                shuffle($group);

                $previous = [];

                foreach ($terms as $term) {
                    $sections = [];

                    for ($i = 0; $i < $year['sections']; $i++) {
                        // The 2nd semester's block was copied from the 1st's.
                        $sections[] = Section::create([
                            'term_id' => $term->id,
                            'previous_section_id' => $previous[$i] ?? null,
                            'program_id' => $program->id,
                            'year_level' => $yearLevel,
                            'name' => $yearLevel.'-'.'ABCDEFGH'[$i],
                            'capacity' => $year['capacity'],
                        ])->id;
                    }

                    $previous = $sections;

                    // Dealt out in turn, so the same learner lands in the same-named section each semester.
                    foreach ($group as $index => $learnerId) {
                        $registeredAt = $term->starts_on->subDays(mt_rand(3, 10))->setTime(mt_rand(8, 15), mt_rand(0, 59));
                        $rows[] = [
                            'learner_id' => $learnerId,
                            'term_id' => $term->id,
                            'year_level' => $yearLevel,
                            'section_id' => $sections[$index % count($sections)],
                            'is_irregular' => false,
                            'status' => RegistrationStatus::Registered->value,
                            'registered_by' => $registrar->id,
                            'created_at' => $registeredAt,
                            'updated_at' => $registeredAt,
                        ];
                    }
                }
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('term_registrations')->insert($chunk);
        }

        $this->linkCurrentSections($current, $terms->last());

        // The dean filled each section from the curriculum the day before the
        // term began, which enrolled its learners.
        foreach ($terms as $term) {
            foreach (Section::with('term')->where('term_id', $term->id)->orderBy('id')->get() as $section) {
                Clock::at($term->starts_on->subDay()->setTime(9, 0), fn () => app(CurriculumFill::class)->fill($section));
            }
        }
    }

    /**
     * This term's sections from year 2 up were copied from last 2nd
     * semester's, a year level lower and named to follow (2-A from 1-A).
     * First-year sections are new; a block with no namesake (1-B became
     * part of 2-A) has no successor.
     */
    private function linkCurrentSections(Term $current, ?Term $lastSemester): void
    {
        if ($lastSemester === null) {
            return;
        }

        foreach (Section::where('term_id', $current->id)->where('year_level', '>=', 2)->get() as $section) {
            $from = Section::where('term_id', $lastSemester->id)
                ->where('program_id', $section->program_id)
                ->where('year_level', $section->year_level - 1)
                ->where('name', ($section->year_level - 1).substr($section->name, strlen((string) $section->year_level)))
                ->value('id');

            $section->update(['previous_section_id' => $from]);
        }
    }
}
