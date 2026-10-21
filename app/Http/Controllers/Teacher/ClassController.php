<?php

namespace App\Http\Controllers\Teacher;

use App\Attendance\DaySheet;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dean\ScheduleBlocks;
use App\Models\Schedule;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A teacher's own classes: one day's (today by default), each opening its
 * class list, and their week.
 */
class ClassController extends Controller
{
    public function index(Request $request, DaySheet $sheet): Response
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validate([
            'term' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $terms = Term::orderByDesc('starts_on')->get();
        $term = $terms->firstWhere('id', $filters['term'] ?? null) ?? Term::forViewing();
        $date = isset($filters['date']) ? CarbonImmutable::parse($filters['date']) : CarbonImmutable::today();
        $own = fn () => Schedule::query()->where('term_id', $term?->id)->where('teacher_id', $user->teacher?->id);

        return Inertia::render('teacher/classes/Index', [
            'terms' => $terms->map(fn (Term $term): array => ['id' => $term->id, 'label' => $term->label, 'phase' => $term->phase()->value]),
            'term' => $term === null ? null : ['id' => $term->id, 'label' => $term->label, 'phase' => $term->phase()->value, 'starts_on' => $term->starts_on->toDateString(), 'ends_on' => $term->ends_on->toDateString()],
            'date' => $date->toDateString(),
            'today' => CarbonImmutable::today()->toDateString(),
            'classes' => $term === null ? [] : $sheet->rows($own(), $date),
            'week' => $term === null ? [] : $own()
                ->with(['subjectOffering.subject', 'subjectOffering.section', 'batch', 'room', 'teacher.user'])
                ->get()
                ->map(fn (Schedule $schedule): array => ScheduleBlocks::block($schedule))
                ->values(),
        ]);
    }
}
