<?php

namespace App\Http\Controllers\Dean;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Scheduling\CurriculumFill;
use App\Scheduling\SectionCopy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Setting up a term's sections at once: copying an earlier term's (shifted
 * up a year for a new school year), and filling every section's missing
 * curriculum subjects.
 */
class SectionCopyController extends Controller
{
    public function store(Request $request, SectionCopy $copier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'term_id' => ['required', 'integer', Rule::exists(Term::class, 'id')],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.from_id' => ['required', 'integer', 'distinct'],
            'sections.*.year_level' => ['required', 'integer', 'between:1,6'],
            'sections.*.name' => ['required', 'string', 'max:30'],
            'sections.*.capacity' => ['nullable', 'integer', 'between:1,1000'],
        ], ['sections.required' => __('Tick at least one section to copy.')]);

        $term = Term::findOrFail($request->integer('term_id'));
        $sources = Section::with(['program', 'term'])->findMany(array_column($validated['sections'], 'from_id'))->keyBy('id');
        // Program and lowercase name of every section the term has, and of each copy so far.
        $taken = array_fill_keys(Section::where('term_id', $term->id)->get(['program_id', 'name'])
            ->map(fn (Section $section): string => $section->program_id.'|'.mb_strtolower($section->name))
            ->all(), true);
        $copies = [];
        $errors = [];

        foreach ($validated['sections'] as $i => $row) {
            $from = $sources->get((int) $row['from_id']);

            if ($from === null || $from->program->college_id !== $user->college_id || ! $from->term->starts_on->lt($term->starts_on)) {
                $errors["sections.{$i}.from_id"] = __('Copy only your college\'s sections from an earlier term.');

                continue;
            }

            Gate::authorize('create', [Section::class, $from->program, $term]);
            $key = $from->program_id.'|'.mb_strtolower(trim((string) $row['name']));

            if (isset($taken[$key])) {
                $errors["sections.{$i}.name"] = __(':program already has a section named :name this term.', ['program' => $from->program->code, 'name' => trim((string) $row['name'])]);

                continue;
            }

            $taken[$key] = true;
            $copies[] = [
                'from' => $from,
                'year_level' => (int) $row['year_level'],
                'name' => trim((string) $row['name']),
                'capacity' => isset($row['capacity']) ? (int) $row['capacity'] : null,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $made = $copier->copy($term, $copies);

        $sections = trans_choice('{1} :count section|[2,*] :count sections', $made['sections'], ['count' => $made['sections']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $made['subjects'] === 0
            ? __('Copied :sections.', ['sections' => $sections])
            : __('Copied :sections with :subjects from the curriculum.', ['sections' => $sections, 'subjects' => trans_choice('{1} :count subject|[2,*] :count subjects', $made['subjects'], ['count' => $made['subjects']])])]);

        return to_route('dean.sections.index', ['term' => $term->id]);
    }

    /**
     * Add the curriculum's missing subjects to every section of the term.
     */
    public function fill(Request $request, Term $term, CurriculumFill $curriculum): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $sections = Section::with(['program', 'term'])
            ->where('term_id', $term->id)
            ->whereRelation('program', 'college_id', $user->college_id)
            ->get();
        $added = 0;
        $filled = 0;

        foreach ($sections as $section) {
            Gate::authorize('update', $section);
            $count = $curriculum->fill($section);
            $added += $count;
            $filled += $count > 0 ? 1 : 0;
        }

        Inertia::flash('toast', $added === 0
            ? ['type' => 'info', 'message' => __('Every section already has its curriculum subjects.')]
            : ['type' => 'success', 'message' => __('Added :subjects to :sections.', [
                'subjects' => trans_choice('{1} :count subject|[2,*] :count subjects', $added, ['count' => $added]),
                'sections' => trans_choice('{1} :count section|[2,*] :count sections', $filled, ['count' => $filled]),
            ])]);

        return back();
    }
}
