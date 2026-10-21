<?php

namespace Database\Seeders\Demo;

use App\Enums\Semester;
use App\Models\CurriculumSubject;
use App\Models\Program;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Each program's curriculum (prospectus), matching what its sections are
 * given: in the 1st semester the general education subjects of the year
 * level plus the program's majors, in the 2nd the year level's general
 * education subjects. A subject is in a program's curriculum once.
 */
class CurriculumSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Program::with('college')->orderBy('id')->get() as $program) {
            $subjects = Subject::where('college_id', $program->college_id)->pluck('id', 'code');

            foreach (CampusData::PROGRAMS[$program->college->code][$program->code]['years'] as $yearLevel => $majors) {
                $lines = [
                    Semester::First->value => [...CampusData::GENERAL_EDUCATION_BY_YEAR[$yearLevel], ...array_column($majors, 0)],
                    Semester::Second->value => CampusData::SECOND_SEMESTER_BY_YEAR[$yearLevel],
                ];

                foreach ($lines as $semester => $codes) {
                    foreach ($codes as $code) {
                        CurriculumSubject::create([
                            'program_id' => $program->id,
                            'subject_id' => $subjects[$code],
                            'year_level' => $yearLevel,
                            'semester' => $semester,
                        ]);
                    }
                }
            }
        }
    }
}
