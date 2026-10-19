<?php

namespace App\Http\Controllers\Dean;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The week of one room or one of the college's teachers. Rooms are shared
 * by the campus, so other colleges' bookings show only as busy.
 */
class TimetableController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $collegeId = (int) $user->college_id;

        $filters = $request->validate([
            'term' => ['nullable', 'integer'],
            'room' => ['nullable', 'integer', Rule::exists(Room::class, 'id')],
            'teacher' => ['nullable', 'integer', Rule::exists(Teacher::class, 'id')->where(fn ($query) => $query->whereIn(
                'user_id',
                fn ($users) => $users->select('id')->from('users')->where('college_id', $collegeId),
            ))],
        ]);

        $terms = Term::orderByDesc('starts_on')->get();
        $term = $terms->firstWhere('id', $filters['term'] ?? null) ?? Term::forPreparation() ?? Term::forViewing();
        $roomId = $filters['room'] ?? null;
        $teacherId = $roomId === null ? ($filters['teacher'] ?? null) : null;

        $classes = [];

        if ($term !== null && ($roomId !== null || $teacherId !== null)) {
            $classes = Schedule::query()
                ->with(['room', 'teacher.user', 'batch', 'subjectOffering.subject', 'subjectOffering.section.program.college'])
                ->where('term_id', $term->id)
                ->when($roomId, fn ($query, int $room) => $query->where('room_id', $room))
                ->when($teacherId, fn ($query, int $teacher) => $query->where('teacher_id', $teacher))
                ->orderBy('starts_at')
                ->get()
                ->map(function (Schedule $schedule) use ($collegeId): array {
                    $section = $schedule->subjectOffering->section;

                    if ($section->program->college_id !== $collegeId) {
                        return [
                            'id' => $schedule->id,
                            'weekday' => $schedule->weekday(),
                            'start' => $schedule->starts_at->format('H:i'),
                            'end' => $schedule->ends_at->format('H:i'),
                            'busy' => $section->program->college->code,
                        ];
                    }

                    return [
                        ...ScheduleBlocks::block($schedule),
                        'section' => "{$section->program->code} {$section->name}",
                        'section_id' => $section->id,
                    ];
                })
                ->all();
        }

        return Inertia::render('dean/timetables/Index', [
            'terms' => $terms->map(fn (Term $term): array => ['id' => $term->id, 'label' => $term->label]),
            'term' => $term === null ? null : ['id' => $term->id, 'label' => $term->label],
            'rooms' => Room::where('is_active', true)->orderBy('building')->orderBy('code')->get(['id', 'code', 'name', 'building']),
            'teachers' => ScheduleBlocks::teacherOptions($collegeId),
            'selected' => ['room' => $roomId, 'teacher' => $teacherId],
            'classes' => $classes,
        ]);
    }
}
