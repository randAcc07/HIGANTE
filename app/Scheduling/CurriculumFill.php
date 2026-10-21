<?php

namespace App\Scheduling;

use App\Models\CurriculumSubject;
use App\Models\Section;
use App\Registration\TermRegistrar;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A section's subjects from its program's curriculum: the lines for its
 * year level and its term's semester. Filling only adds what is missing
 * (teacher to be announced); nothing the section has is changed or removed.
 */
final class CurriculumFill
{
    public function __construct(private readonly TermRegistrar $registrar) {}

    /**
     * The curriculum's subjects the section doesn't have yet.
     *
     * @return Collection<int, CurriculumSubject>
     */
    public function missing(Section $section): Collection
    {
        return CurriculumSubject::query()
            ->with('subject:id,code,title')
            ->where('program_id', $section->program_id)
            ->where('year_level', $section->year_level)
            ->where('semester', $section->term->semester)
            ->whereNotIn('subject_id', $section->subjectOfferings()->select('subject_id'))
            ->get()
            ->sortBy(fn (CurriculumSubject $line): string => $line->subject->code)
            ->values();
    }

    /**
     * Add the missing subjects, enrolling the section's regular learners in
     * each. Returns how many were added.
     */
    public function fill(Section $section): int
    {
        $missing = $this->missing($section);

        DB::transaction(function () use ($section, $missing): void {
            foreach ($missing as $line) {
                $offering = $section->subjectOfferings()->create(['subject_id' => $line->subject_id]);
                $this->registrar->enrollSectionIn($offering);
            }
        });

        return $missing->count();
    }
}
