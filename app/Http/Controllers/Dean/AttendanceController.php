<?php

namespace App\Http\Controllers\Dean;

use App\Attendance\DaySheet;
use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dean's view of their college's attendance: one day's classes, by
 * section or teacher, each opening its class list to correct.
 */
class AttendanceController extends Controller
{
    public function index(Request $request, DaySheet $sheet): Response
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validate([
            'term' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'section' => ['nullable', 'integer'],
            'teacher' => ['nullable', 'integer'],
        ]) + ['term' => null, 'date' => null, 'section' => null, 'teacher' => null];

        $terms = Term::orderByDesc('starts_on')->get();
        $term = $terms->firstWhere('id', $filters['term']) ?? Term::forViewing();
        $date = $filters['date'] !== null ? CarbonImmutable::parse($filters['date']) : CarbonImmutable::today();
        $programIds = Program::where('college_id', $user->college_id)->pluck('id');

        $schedules = Schedule::query()
            ->where('term_id', $term?->id)
            ->whereHas('subjectOffering.section', fn ($section) => $section->whereIn('program_id', $programIds))
            ->when($filters['section'], fn ($query, int $section) => $query->whereHas('subjectOffering', fn ($offering) => $offering->where('section_id', $section)))
            ->when($filters['teacher'], fn ($query, int $teacher) => $query->where('teacher_id', $teacher));

        return Inertia::render('dean/attendance/Index', [
            'terms' => $terms->map(fn (Term $term): array => ['id' => $term->id, 'label' => $term->label, 'phase' => $term->phase()->value]),
            'term' => $term === null ? null : ['id' => $term->id, 'label' => $term->label, 'phase' => $term->phase()->value, 'starts_on' => $term->starts_on->toDateString(), 'ends_on' => $term->ends_on->toDateString()],
            'date' => $date->toDateString(),
            'today' => CarbonImmutable::today()->toDateString(),
            'sections' => $term === null ? [] : Section::with('program:id,code')
                ->where('term_id', $term->id)
                ->whereIn('program_id', $programIds)
                ->orderBy('program_id')->orderBy('year_level')->orderBy('name')
                ->get(['id', 'program_id', 'name']),
            'teachers' => ScheduleBlocks::teacherOptions($user->college_id ?? 0),
            'classes' => $term === null ? [] : $sheet->rows($schedules, $date),
            'filters' => $filters,
        ]);
    }
}
