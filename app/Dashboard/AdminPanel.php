<?php

namespace App\Dashboard;

use App\Enums\UserRole;
use App\Models\CalendarEvent;
use App\Models\College;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The admin's dashboard: a month of campus events (every college's), how
 * ready each college is for the next term, and staff who never signed in.
 */
final class AdminPanel
{
    /** How many accounts the awaiting-setup list shows. */
    private const LISTED = 6;

    /**
     * @return array<string, mixed>
     */
    public function build(?string $month): array
    {
        $first = self::month($month);
        $last = $first->endOfMonth();
        $terms = Term::orderBy('starts_on')->get();
        $awaiting = User::query()
            ->whereIn('role', [UserRole::Registrar, UserRole::Dean, UserRole::Teacher])
            ->where('is_active', true)
            ->whereNull('last_login_at');

        return [
            'month' => [
                'first' => $first->toDateString(),
                'previous' => $first->subMonth()->format('Y-m'),
                'next' => $first->addMonth()->format('Y-m'),
                'events' => CalendarEvent::query()
                    ->with('college:id,code')
                    ->where('starts_on', '<=', $last->toDateString())
                    ->where('ends_on', '>=', $first->toDateString())
                    ->orderBy('starts_on')
                    ->get()
                    ->map(fn (CalendarEvent $event): array => [
                        'id' => $event->id,
                        'title' => $event->title,
                        'starts_on' => $event->starts_on->toDateString(),
                        'ends_on' => $event->ends_on->toDateString(),
                        'college' => $event->college?->code,
                        'suspends' => $event->suspends_classes,
                        // The Calendar page shows one term's events: the one this starts in.
                        'term_id' => ($terms->first(fn (Term $term): bool => $event->starts_on->between($term->starts_on, $term->ends_on))
                            ?? $terms->first(fn (Term $term): bool => $term->starts_on->lte($event->ends_on) && $term->ends_on->gte($event->starts_on)))?->id,
                    ])
                    ->values(),
                // Term starts and ends falling in the month.
                'marks' => $terms->flatMap(fn (Term $term): array => array_values(array_filter([
                    $term->starts_on->between($first, $last) ? ['date' => $term->starts_on->toDateString(), 'label' => __(':term starts', ['term' => $term->label])] : null,
                    $term->ends_on->between($first, $last) ? ['date' => $term->ends_on->toDateString(), 'label' => __(':term ends', ['term' => $term->label])] : null,
                ])))->values(),
            ],
            'readiness' => self::readiness(Term::nextUpcoming()),
            'awaiting' => (clone $awaiting)
                ->with('college:id,code')
                ->oldest()
                ->limit(self::LISTED)
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'role' => $user->role->label(),
                    'college' => $user->college?->code,
                    'added_on' => $user->created_at?->toDateString(),
                ]),
            'awaitingCount' => $awaiting->count(),
        ];
    }

    /**
     * The month asked for ("2026-10"), else this one; its first day.
     */
    private static function month(?string $month): CarbonImmutable
    {
        return $month !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1
            ? CarbonImmutable::parse("{$month}-01")
            : CarbonImmutable::today()->startOfMonth();
    }

    /**
     * Per college, how far the next term's setup is: sections made, their
     * subjects with a teacher and with classes, and programs with a
     * curriculum (what fills new sections).
     *
     * @return array{term: array{id: int, label: string, starts_on: string}, colleges: list<array<string, mixed>>}|null
     */
    private static function readiness(?Term $term): ?array
    {
        if ($term === null) {
            return null;
        }

        $colleges = College::query()
            ->withCount(['programs', 'programs as programs_without_curriculum_count' => fn ($programs) => $programs->whereDoesntHave('curriculum')])
            ->orderBy('code')
            ->get();

        return [
            'term' => ['id' => $term->id, 'label' => $term->label, 'starts_on' => $term->starts_on->toDateString()],
            'colleges' => array_values($colleges->map(function (College $college) use ($term): array {
                $sections = Section::where('term_id', $term->id)->whereRelation('program', 'college_id', $college->id);
                $offerings = SubjectOffering::whereIn('section_id', (clone $sections)->select('id'));

                return [
                    'id' => $college->id,
                    'code' => $college->code,
                    'name' => $college->name,
                    'programs' => (int) $college->getAttribute('programs_count'),
                    'without_curriculum' => (int) $college->getAttribute('programs_without_curriculum_count'),
                    'sections' => $sections->count(),
                    'subjects' => (clone $offerings)->count(),
                    'without_teacher' => (clone $offerings)->whereNull('teacher_id')->count(),
                    'unscheduled' => (clone $offerings)->whereDoesntHave('schedules')->count(),
                ];
            })->all()),
        ];
    }
}
