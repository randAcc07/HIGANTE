<?php

namespace Database\Seeders\Demo;

use App\Enums\DutyStatus;
use App\Models\College;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Teacher;
use App\Models\Term;
use Illuminate\Database\Seeder;

/**
 * The upcoming 2nd semester: the current term's sections copied over (same
 * programs, year levels, names and capacities, each pointing back at its
 * 1st-semester section), each with its few general education subjects from
 * the curriculum and no teacher or schedule yet; one line is left out
 * (CampusData::CURRICULUM_GAP). CampusData::UNCOPIED_COLLEGE has no
 * sections yet, so its dean is offered "Copy sections". Nobody is
 * registered, so the registrar's list shows the current term's learners
 * carried over and pre-ticked, and registering them keeps each block in the
 * section copied from theirs. For the admin's readiness panel, READY_COLLEGE
 * has teachers and classes for everything, PARTLY_READY_COLLEGE teachers for
 * most and no classes.
 */
class NextTermSeeder extends Seeder
{
    public function run(): void
    {
        $current = CampusData::currentTerm();
        $next = CampusData::nextTerm();

        $sections = Section::with('program.college')
            ->where('term_id', $current->id)
            ->whereRelation('program.college', 'code', '!=', CampusData::UNCOPIED_COLLEGE)
            ->orderBy('id')
            ->get();

        foreach ($sections as $section) {
            $copy = Section::create([
                'term_id' => $next->id,
                'previous_section_id' => $section->id,
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

        $this->prepare($next);
    }

    /**
     * How far each college has got: READY_COLLEGE's subjects all have a
     * teacher and a timetable; PARTLY_READY_COLLEGE's dean has given most
     * subjects a teacher (every fourth still open) and scheduled none.
     */
    private function prepare(Term $next): void
    {
        foreach (College::whereIn('code', [CampusData::READY_COLLEGE, CampusData::PARTLY_READY_COLLEGE])->orderBy('id')->get() as $college) {
            if ($college->code === CampusData::READY_COLLEGE) {
                app(SectionSeeder::class)->scheduleOfferings($next, $college);

                continue;
            }

            $teachers = Teacher::query()
                ->where('duty_status', DutyStatus::Active)
                ->whereHas('user', fn ($user) => $user->where('college_id', $college->id)->where('is_active', true))
                ->orderBy('id')
                ->pluck('id')
                ->all();
            $offerings = SubjectOffering::query()
                ->whereHas('section', fn ($section) => $section->where('term_id', $next->id)->whereRelation('program', 'college_id', $college->id))
                ->orderBy('id')
                ->get()
                ->values();

            foreach ($offerings as $index => $offering) {
                if ($teachers !== [] && $index % 4 !== 3) {
                    $offering->update(['teacher_id' => $teachers[$index % count($teachers)]]);
                }
            }
        }
    }
}
