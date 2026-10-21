<?php

namespace App\Dashboard;

use App\Enums\LearnerStatus;
use App\Enums\RegistrationStatus;
use App\Enums\UserRole;
use App\Models\Learner;
use App\Models\Program;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use App\Registration\TermRegistrar;
use Carbon\CarbonImmutable;

/**
 * The registrar's dashboard: how registration for the term being prepared
 * is going (per program and year level), learners waiting for the dean,
 * recent admissions, and learners who never signed in.
 */
final class RegistrarPanel
{
    /** How many items each side list shows. */
    private const LISTED = 6;

    /** "Recent" admissions: the last this many days. */
    public const RECENT_DAYS = 7;

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $term = Term::forPreparation() ?? Term::forViewing();
        $registered = $term === null ? null : TermRegistration::where('term_id', $term->id)->where('status', RegistrationStatus::Registered);
        $unsectioned = $registered === null ? null : (clone $registered)->whereNull('section_id');
        $recent = Learner::where('created_at', '>=', CarbonImmutable::today()->subDays(self::RECENT_DAYS - 1));
        $awaiting = User::query()
            ->where('role', UserRole::Learner)
            ->where('is_active', true)
            ->whereNull('last_login_at');

        return [
            'term' => $term === null ? null : ['id' => $term->id, 'label' => $term->label, 'open' => TermRegistrar::isOpen($term)],
            'progress' => $term === null ? [] : self::progress($term),
            'unsectioned' => $unsectioned === null ? [] : (clone $unsectioned)
                ->with(['learner.user', 'learner.program:id,code'])
                ->oldest()
                ->limit(self::LISTED)
                ->get()
                ->map(fn (TermRegistration $registration): array => [
                    'id' => $registration->id,
                    'name' => $registration->learner->user->last_name.', '.$registration->learner->user->first_name,
                    'program' => $registration->learner->program->code,
                    'year_level' => $registration->year_level,
                ]),
            'unsectionedCount' => $unsectioned?->count() ?? 0,
            'recent' => (clone $recent)
                ->with(['user', 'program:id,code'])
                ->latest()
                ->limit(self::LISTED)
                ->get()
                ->map(fn (Learner $learner): array => [
                    'id' => $learner->id,
                    'name' => $learner->user->last_name.', '.$learner->user->first_name,
                    'student_number' => $learner->student_number,
                    'program' => $learner->program->code,
                    'admitted_on' => $learner->created_at?->toDateString(),
                ]),
            'recentCount' => $recent->count(),
            'awaiting' => (clone $awaiting)
                ->with(['learner.program:id,code'])
                ->oldest()
                ->limit(self::LISTED)
                ->get()
                ->map(fn (User $user): array => [
                    'learner_id' => $user->learner?->id,
                    'name' => $user->last_name.', '.$user->first_name,
                    'program' => $user->learner?->program->code,
                    'added_on' => $user->created_at?->toDateString(),
                ]),
            'awaitingCount' => $awaiting->count(),
        ];
    }

    /**
     * Per program and year level: registered for the term, and (while it
     * is open) still waiting on the list carried over from the source
     * terms, at the year level the list proposes. Matches the Registration
     * page's list.
     *
     * @return list<array{program_id: int, college: string, program: string, year_level: int, registered: int, waiting: int}>
     */
    private static function progress(Term $term): array
    {
        $counts = [];
        $add = function (int $programId, int $year, string $column) use (&$counts): void {
            $counts[$programId][$year] ??= ['registered' => 0, 'waiting' => 0];
            $counts[$programId][$year][$column]++;
        };

        TermRegistration::query()
            ->join('learners', 'learners.id', '=', 'term_registrations.learner_id')
            ->where('term_id', $term->id)
            ->where('term_registrations.status', RegistrationStatus::Registered)
            ->get(['learners.program_id', 'term_registrations.year_level'])
            ->each(fn (TermRegistration $registration) => $add((int) $registration->getAttribute('program_id'), $registration->year_level, 'registered'));

        if (TermRegistrar::isOpen($term)) {
            $sourceTermIds = TermRegistrar::sourceTerms($term)->pluck('id');
            $waiting = Learner::query()
                ->where('status', LearnerStatus::Active)
                ->whereHas('user', fn ($user) => $user->where('is_active', true))
                ->whereDoesntHave('termRegistrations', fn ($registrations) => $registrations->where('term_id', $term->id))
                ->whereHas('termRegistrations', fn ($registrations) => $registrations->whereIn('term_id', $sourceTermIds))
                ->get(['id', 'program_id', 'year_level']);
            // Each one's latest registration in the source terms.
            $sources = TermRegistration::query()
                ->join('terms', 'terms.id', '=', 'term_registrations.term_id')
                ->whereIn('learner_id', $waiting->modelKeys())
                ->whereIn('term_id', $sourceTermIds)
                ->orderByDesc('terms.starts_on')
                ->get(['term_registrations.learner_id', 'term_registrations.year_level'])
                ->unique('learner_id')
                ->keyBy('learner_id');

            foreach ($waiting as $learner) {
                $add($learner->program_id, TermRegistrar::proposedYearLevel($term, $learner, $sources->get($learner->id)), 'waiting');
            }
        }

        $programs = Program::with('college:id,code')->findMany(array_keys($counts))->keyBy('id');
        $rows = [];

        foreach ($counts as $programId => $years) {
            $program = $programs->get($programId);

            foreach ($years as $year => $count) {
                $rows[] = [
                    'program_id' => $programId,
                    'college' => $program->college->code ?? '',
                    'program' => $program->code ?? '',
                    'year_level' => $year,
                    'registered' => $count['registered'],
                    'waiting' => $count['waiting'],
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['college'], $a['program'], $a['year_level']] <=> [$b['college'], $b['program'], $b['year_level']]);

        return $rows;
    }
}
