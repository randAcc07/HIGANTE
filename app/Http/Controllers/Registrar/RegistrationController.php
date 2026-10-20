<?php

namespace App\Http\Controllers\Registrar;

use App\Enums\LearnerStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Semester;
use App\Http\Controllers\Controller;
use App\Models\College;
use App\Models\Learner;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use App\Registration\TermRegistrar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Term registration at the registrar's office. The list to register from
 * is last school year's learners for a 1st semester (year level raised,
 * nobody ticked) or the 1st semester's for a 2nd (pre-ticked unless they
 * withdrew); anyone else active and unregistered (returnees) can be found
 * under "others".
 */
class RegistrationController extends Controller
{
    public function __construct(private TermRegistrar $registrar) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'term' => ['nullable', 'integer'],
            'view' => ['nullable', Rule::in(['candidates', 'registered'])],
            'source' => ['nullable', Rule::in(['list', 'others'])],
            'search' => ['nullable', 'string', 'max:100'],
            'college' => ['nullable', 'integer'],
            'program' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer', 'between:1,6'],
            'status' => ['nullable', Rule::enum(RegistrationStatus::class)],
            'unsectioned' => ['nullable', 'boolean'],
        ]) + ['term' => null, 'view' => null, 'source' => null, 'search' => null, 'college' => null, 'program' => null, 'year' => null, 'status' => null, 'unsectioned' => null];
        $filters['view'] ??= 'candidates';
        $filters['source'] ??= 'list';

        $terms = Term::orderByDesc('starts_on')->get();
        $term = $terms->firstWhere('id', $filters['term']) ?? Term::forPreparation() ?? Term::forViewing();

        // An ended or closed term has nobody left to register: only its record.
        if ($term !== null && ! TermRegistrar::isOpen($term)) {
            $filters['view'] = 'registered';
        }

        return Inertia::render('registrar/registrations/Index', [
            'terms' => $terms->map(fn (Term $term): array => self::termOption($term)),
            'term' => $term === null ? null : self::termOption($term),
            'canRegister' => $term !== null && TermRegistrar::isOpen($term),
            'counts' => $term === null ? null : $this->counts($term),
            'candidates' => $term !== null && $filters['view'] === 'candidates' ? $this->candidates($term, $filters) : null,
            'registrations' => $term !== null && $filters['view'] === 'registered' ? $this->registrations($term, $filters) : null,
            'colleges' => College::with(['programs' => fn ($programs) => $programs->orderBy('code')->select(['id', 'college_id', 'code', 'name'])])
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'filters' => $filters,
        ]);
    }

    /**
     * Register the ticked learners (one or many), each placed in a section.
     */
    public function store(Request $request, Term $term): RedirectResponse
    {
        Gate::authorize('create', [TermRegistration::class, $term]);
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:100'],
            'entries.*.learner_id' => ['required', 'integer', 'distinct', Rule::exists(Learner::class, 'id')],
            'entries.*.year_level' => ['required', 'integer', 'between:1,6'],
            'entries.*.is_irregular' => ['required', 'boolean'],
        ]);

        $learners = Learner::with(['user', 'program'])->findMany(array_column($validated['entries'], 'learner_id'))->keyBy('id');

        $results = DB::transaction(fn (): array => array_map(
            fn (array $entry): array => $this->registrar->register($learners[$entry['learner_id']], $term, (int) $entry['year_level'], (bool) $entry['is_irregular'], $user),
            $validated['entries'],
        ));

        $unsectioned = count(array_filter($results, fn (array $result): bool => $result['registration']->section_id === null));
        $warnings = array_values(array_unique(array_filter(array_column($results, 'warning'))));
        $message = trans_choice('{1} Registered :count learner.|[2,*] Registered :count learners.', count($results), ['count' => count($results)]);

        if ($unsectioned > 0) {
            $message .= ' '.trans_choice('{1} :count has no section with room and waits for the dean.|[2,*] :count have no section with room and wait for the dean.', $unsectioned, ['count' => $unsectioned]);
        }

        Inertia::flash('toast', [
            'type' => $unsectioned > 0 || $warnings !== [] ? 'warning' : 'success',
            'message' => trim($message.' '.implode(' ', $warnings)),
        ]);

        return back();
    }

    /**
     * Mark a registration irregular or regular. Turning regular enrolls the
     * learner in the section's subjects they don't have yet; turning
     * irregular keeps their subjects for the dean to adjust.
     */
    public function update(Request $request, TermRegistration $registration): RedirectResponse
    {
        Gate::authorize('update', $registration);

        $irregular = (bool) $request->validate(['is_irregular' => ['required', 'boolean']])['is_irregular'];

        if ($registration->status === RegistrationStatus::Withdrawn) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The learner has withdrawn.')]);

            return back();
        }

        DB::transaction(function () use ($registration, $irregular): void {
            $registration->update(['is_irregular' => $irregular]);

            if (! $irregular) {
                $this->registrar->enrollInSection($registration);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $irregular
            ? __(':name is irregular; the dean enrolls them subject by subject.', ['name' => $registration->learner->user->full_name])
            : __(':name is regular and takes all of the section\'s subjects.', ['name' => $registration->learner->user->full_name])]);

        return back();
    }

    public function withdraw(Request $request, TermRegistration $registration): RedirectResponse
    {
        Gate::authorize('update', $registration);
        /** @var User $user */
        $user = $request->user();

        if ($registration->status === RegistrationStatus::Withdrawn) {
            return back();
        }

        $this->registrar->withdraw($registration, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name has withdrawn; their classes are dropped.', ['name' => $registration->learner->user->full_name])]);

        return back();
    }

    /**
     * Undo a registration made by mistake, while nothing is recorded.
     */
    public function destroy(TermRegistration $registration): RedirectResponse
    {
        Gate::authorize('update', $registration);

        if ($this->registrar->hasAttendance($registration)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Attendance has been recorded for :name, so the registration can only be withdrawn.', ['name' => $registration->learner->user->full_name])]);

            return back();
        }

        $this->registrar->undo($registration);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Registration of :name undone.', ['name' => $registration->learner->user->full_name])]);

        return back();
    }

    /**
     * @return array{id: int, label: string, phase: string, semester: string}
     */
    private static function termOption(Term $term): array
    {
        return ['id' => $term->id, 'label' => $term->label, 'phase' => $term->phase()->value, 'semester' => $term->semester->value];
    }

    /**
     * @return array{registered: int, unsectioned: int, withdrawn: int}
     */
    private function counts(Term $term): array
    {
        $registrations = TermRegistration::where('term_id', $term->id);

        return [
            'registered' => (clone $registrations)->where('status', RegistrationStatus::Registered)->count(),
            'unsectioned' => (clone $registrations)->where('status', RegistrationStatus::Registered)->whereNull('section_id')->count(),
            'withdrawn' => (clone $registrations)->where('status', RegistrationStatus::Withdrawn)->count(),
        ];
    }

    /**
     * Active learners not registered for the term yet, with the year level
     * and irregular flag to propose and whether to pre-tick them.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function candidates(Term $term, array $filters): array
    {
        $sourceTermIds = TermRegistrar::sourceTerms($term)->pluck('id');
        $fromList = $filters['source'] === 'list';
        $inSource = fn (Builder $query) => $query->whereIn('term_id', $sourceTermIds);

        $page = Learner::query()
            ->with(['user', 'program:id,college_id,code', 'program.college:id,code'])
            ->where('learners.status', LearnerStatus::Active)
            ->whereHas('user', fn ($user) => $user->where('is_active', true))
            ->whereDoesntHave('termRegistrations', fn ($query) => $query->where('term_id', $term->id))
            ->when($fromList, fn ($query) => $query->whereHas('termRegistrations', $inSource), fn ($query) => $query->whereDoesntHave('termRegistrations', $inSource))
            ->tap(fn ($query) => self::filterLearners($query, $filters))
            ->when($filters['year'], fn ($query, int $year) => $fromList
                // The list proposes a year from the source registration.
                ? $query->whereHas('termRegistrations', fn ($registrations) => $inSource($registrations)->where('year_level', $term->semester === Semester::First ? $year - 1 : $year))
                : $query->where('learners.year_level', $year))
            ->join('users', 'users.id', '=', 'learners.user_id')
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->select('learners.*')
            ->paginate(50)
            ->withQueryString();

        // Each learner's latest registration in the source terms.
        $sources = TermRegistration::query()
            ->with(['section:id,name', 'term'])
            ->whereIn('learner_id', $page->getCollection()->pluck('id'))
            ->whereIn('term_id', $sourceTermIds)
            ->join('terms', 'terms.id', '=', 'term_registrations.term_id')
            ->orderByDesc('terms.starts_on')
            ->select('term_registrations.*')
            ->get()
            ->unique('learner_id')
            ->keyBy('learner_id');

        $rows = $page->getCollection()->map(function (Learner $learner) use ($term, $sources): array {
            /** @var TermRegistration|null $source */
            $source = $sources->get($learner->id);

            return [
                'learner' => $learner,
                'year_level' => TermRegistrar::proposedYearLevel($term, $learner, $source),
                'is_irregular' => $source !== null && $source->is_irregular,
                'ticked' => $term->semester === Semester::Second && $source?->status === RegistrationStatus::Registered,
                'previous' => $source === null ? null : [
                    'term' => $source->term->label,
                    'year_level' => $source->year_level,
                    'section' => $source->section?->name,
                    'status' => $source->status->value,
                ],
            ];
        });

        return [...$page->toArray(), 'data' => $rows->values()->all()];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function registrations(Term $term, array $filters): array
    {
        $page = TermRegistration::query()
            ->with(['learner.user', 'learner.program:id,college_id,code', 'learner.program.college:id,code', 'section:id,name,capacity', 'registrar:id,first_name,middle_name,last_name,suffix'])
            ->where('term_id', $term->id)
            ->whereHas('learner', fn ($query) => self::filterLearners($query, $filters))
            ->when($filters['year'], fn ($query, int $year) => $query->where('term_registrations.year_level', $year))
            ->when($filters['status'], fn ($query, string $status) => $query->where('term_registrations.status', $status))
            ->when($filters['unsectioned'], fn ($query) => $query->whereNull('section_id')->where('term_registrations.status', RegistrationStatus::Registered))
            ->join('learners', 'learners.id', '=', 'term_registrations.learner_id')
            ->join('users', 'users.id', '=', 'learners.user_id')
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->select('term_registrations.*')
            ->paginate(50)
            ->withQueryString();

        $rows = $page->getCollection()->map(fn (TermRegistration $registration): array => [
            'id' => $registration->id,
            'learner' => $registration->learner,
            'year_level' => $registration->year_level,
            'section' => $registration->section,
            'is_irregular' => $registration->is_irregular,
            'status' => $registration->status->value,
            'registered_on' => $registration->created_at?->toDateString(),
            'registered_by' => $registration->registrar?->full_name,
        ]);

        return [...$page->toArray(), 'data' => $rows->values()->all()];
    }

    /**
     * Search, college and program, on a learners query.
     *
     * @param  Builder<Learner>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Learner>
     */
    private static function filterLearners(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'], fn ($query, string $search) => $query->matching($search))
            ->when($filters['college'], fn ($query, int $college) => $query->whereHas('program', fn ($program) => $program->where('college_id', $college)))
            ->when($filters['program'], fn ($query, int $program) => $query->where('learners.program_id', $program));
    }
}
