<?php

namespace Database\Seeders\Demo;

use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Term;
use Illuminate\Database\Seeder;

/**
 * The upcoming 2nd semester: the current term's sections again (same
 * programs, year levels, names and capacities), each with its few general
 * education subjects from the curriculum, with no teacher or schedule yet
 * (one is left out: CampusData::CURRICULUM_GAP). Nobody is
 * registered, so the registrar's list shows the current term's learners
 * carried over and pre-ticked, and registering them keeps each block in
 * its same-named section.
 */
class NextTermSeeder extends Seeder
{
    public function run(): void
    {
        $current = Term::where('school_year', CampusData::TERM['school_year'])
            ->where('semester', CampusData::TERM['semester'])
            ->sole();
        $next = Term::where('school_year', CampusData::NEXT_TERM['school_year'])
            ->where('semester', CampusData::NEXT_TERM['semester'])
            ->sole();

        foreach (Section::with('program')->where('term_id', $current->id)->orderBy('id')->get() as $section) {
            $copy = Section::create([
                'term_id' => $next->id,
                'program_id' => $section->program_id,
                'year_level' => $section->year_level,
                'name' => $section->name,
                'capacity' => $section->capacity,
            ]);

            $gap = $section->program->code === CampusData::CURRICULUM_GAP['program'] && $section->name === CampusData::CURRICULUM_GAP['section'];
            $subjectIds = Subject::where('college_id', $section->program->college_id)
                ->whereIn('code', CampusData::SECOND_SEMESTER_BY_YEAR[$section->year_level])
                ->when($gap, fn ($query) => $query->where('code', '!=', CampusData::CURRICULUM_GAP['subject']))
                ->orderBy('code')
                ->pluck('id');

            foreach ($subjectIds as $subjectId) {
                SubjectOffering::create(['section_id' => $copy->id, 'subject_id' => $subjectId]);
            }
        }
    }
}
