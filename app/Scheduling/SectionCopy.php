<?php

namespace App\Scheduling;

use App\Enums\Semester;
use App\Models\CurriculumSubject;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Support\Facades\DB;

/**
 * Copying an earlier term's sections into a term: the same blocks for a
 * 2nd semester, shifted up a year level for a new school year. Each copy
 * remembers its source (registration keeps the block together through it)
 * and gets its subjects from the curriculum, as a new section does.
 */
final class SectionCopy
{
    public function __construct(private readonly CurriculumFill $curriculum) {}

    /**
     * The term to copy from: the latest earlier term in which the college
     * has sections.
     */
    public function source(Term $target, int $collegeId): ?Term
    {
        return Term::query()
            ->where('starts_on', '<', $target->starts_on->toDateString())
            ->whereHas('sections', fn ($sections) => $sections->whereRelation('program', 'college_id', $collegeId))
            ->orderByDesc('starts_on')
            ->first();
    }

    /**
     * Whether copying from that term moves to a new school year (and the
     * blocks go up a year level).
     */
    public static function shiftsUp(Term $from, Term $to): bool
    {
        return $to->semester === Semester::First && $from->school_year !== $to->school_year;
    }

    /**
     * Each of the source term's sections with what its copy would be: the
     * year level and name (shifted up for a new school year; a name that
     * starts with the year level follows it, 1-A → 2-A) and whether it is
     * ticked by default (not past year 6, and in a year the program's
     * curriculum covers, when it has one).
     *
     * @return list<array{id: int, program_id: int, program: string, from: string, year_level: int, name: string, capacity: int|null, ticked: bool}>
     */
    public function proposals(Term $from, Term $to, int $collegeId): array
    {
        $shift = self::shiftsUp($from, $to);
        $years = CurriculumSubject::query()
            ->whereRelation('program', 'college_id', $collegeId)
            ->distinct()
            ->get(['program_id', 'year_level'])
            ->groupBy('program_id')
            ->map(fn ($lines): array => $lines->pluck('year_level')->all());

        return array_values(Section::query()
            ->with('program:id,code')
            ->where('term_id', $from->id)
            ->whereRelation('program', 'college_id', $collegeId)
            ->orderBy('program_id')
            ->orderBy('year_level')
            ->orderBy('name')
            ->get()
            ->map(function (Section $section) use ($shift, $years): array {
                $year = $shift ? $section->year_level + 1 : $section->year_level;
                $name = $shift && str_starts_with($section->name, (string) $section->year_level)
                    ? $year.substr($section->name, strlen((string) $section->year_level))
                    : $section->name;
                $covered = ! $years->has($section->program_id) || in_array($year, $years[$section->program_id], true);

                return [
                    'id' => $section->id,
                    'program_id' => $section->program_id,
                    'program' => $section->program->code,
                    'from' => $section->name,
                    'year_level' => $year,
                    'name' => $name,
                    'capacity' => $section->capacity,
                    'ticked' => $year <= 6 && $covered,
                ];
            })
            ->all());
    }

    /**
     * Make the copies (already validated), each filled from the curriculum.
     * Returns how many sections and subjects were added.
     *
     * @param  list<array{from: Section, year_level: int, name: string, capacity: int|null}>  $copies
     * @return array{sections: int, subjects: int}
     */
    public function copy(Term $to, array $copies): array
    {
        return DB::transaction(function () use ($to, $copies): array {
            $subjects = 0;

            foreach ($copies as $copy) {
                $section = Section::create([
                    'term_id' => $to->id,
                    'previous_section_id' => $copy['from']->id,
                    'program_id' => $copy['from']->program_id,
                    'year_level' => $copy['year_level'],
                    'name' => $copy['name'],
                    'capacity' => $copy['capacity'],
                ]);
                $subjects += $this->curriculum->fill($section);
            }

            return ['sections' => count($copies), 'subjects' => $subjects];
        });
    }
}
