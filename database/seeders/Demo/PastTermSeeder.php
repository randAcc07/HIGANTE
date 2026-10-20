<?php

namespace Database\Seeders\Demo;

use App\Enums\RegistrationStatus;
use App\Enums\UserRole;
use App\Models\Program;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Last school year, for the record: both of its terms get sections and a
 * registration for every learner registered this term in years 2 to 4
 * (withdrawals included), one year level lower. A learner keeps the same
 * section in both semesters. There are no classes or attendance.
 */
class PastTermSeeder extends Seeder
{
    public function run(): void
    {
        $current = Term::where('school_year', CampusData::TERM['school_year'])
            ->where('semester', CampusData::TERM['semester'])
            ->sole();
        $terms = Term::where('school_year', CampusData::PAST_TERMS[0]['school_year'])->orderBy('starts_on')->get();
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

                foreach ($terms as $term) {
                    $sections = [];

                    for ($i = 0; $i < $year['sections']; $i++) {
                        $sections[] = Section::create([
                            'term_id' => $term->id,
                            'program_id' => $program->id,
                            'year_level' => $yearLevel,
                            'name' => $yearLevel.'-'.'ABCDEFGH'[$i],
                            'capacity' => $year['capacity'],
                        ])->id;
                    }

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
    }
}
