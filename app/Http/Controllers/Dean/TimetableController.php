<?php

namespace App\Http\Controllers\Dean;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\User;
use App\Scheduling\ScheduleConflicts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the dean schedules classes, one week at a time, by view type: a
 * section of the college (optionally as one of its batches sees it), a
 * room, or a teacher of the college. Rooms are shared by the campus, so
 * other colleges' bookings show only as busy. (Sections, their subjects and
 * batches are set up on the section pages.)
 */
class TimetableController extends Controller
{
    public function index(Request $request, ScheduleConflicts $checker): Response
    {
        /** @var User $user */
        $user = $request->user();
        $collegeId = (int) $user->college_id;

        $terms = Term::orderByDesc('starts_on')->get();
        $term = $terms->firstWhere('id', $request->integer('term')) ?? Term::forPreparation() ?? Term::forViewing();

        $sections = $term === null ? collect() : Section::query()
            ->with('program:id,code')
            ->where('term_id', $term->id)
            ->whereHas('program', fn ($program) => $program->where('college_id', $collegeId))
            ->orderBy('program_id')
            ->orderBy('year_level')
            ->orderBy('name')
            ->get();

        $view = $request->validate([
            'view' => ['nullable', Rule::in(['section', 'room', 'teacher'])],
            'target' => ['nullable', 'integer', match ($request->input('view')) {
                'section' => Rule::in($sections->pluck('id')->all()),
                'room' => Rule::exists(Room::class, 'id'),
                'teacher' => Rule::exists(Teacher::class, 'id')->where(fn ($query) => $query->whereIn(
                    'user_id',
                    fn ($users) => $users->select('id')->from('users')->where('college_id', $collegeId),
                )),
                default => 'prohibited',
            }],
            'batch' => ['nullable', 'string', 'max:30'],
        ]) + ['view' => 'section', 'target' => null, 'batch' => null];

        $target = $view['target'] === null ? null : (int) $view['target'];
        $batch = $view['view'] === 'section' ? $view['batch'] : null;

        // A section's batches go by name across its subjects (e.g. 2-AA in
        // every lab), so a batch is picked by name.
        $batchNames = $view['view'] === 'section' && $target !== null
            ? Batch::query()
                ->whereHas('subjectOffering', fn ($offering) => $offering->where('section_id', $target))
                ->distinct()
                ->orderBy('name')
                ->pluck('name')
                ->all()
            : [];

        $classes = $term === null || $target === null ? [] : Schedule::query()
            ->with(['room', 'teacher.user', 'batch', 'subjectOffering.subject', 'subjectOffering.section.program.college'])
            ->withCount('attendanceRecords')
            ->where('term_id', $term->id)
            ->when($view['view'] === 'section', fn (Builder $query) => $query
                ->whereHas('subjectOffering', fn ($offering) => $offering->where('section_id', $target))
                ->when($batch, fn (Builder $query, string $name) => $query->where(fn ($query) => $query
                    ->whereNull('batch_id')
                    ->orWhereHas('batch', fn ($batches) => $batches->where('name', $name)))))
            ->when($view['view'] === 'room', fn (Builder $query) => $query->where('room_id', $target))
            ->when($view['view'] === 'teacher', fn (Builder $query) => $query->where('teacher_id', $target))
            ->orderBy('starts_at')
            ->get()
            ->map(function (Schedule $schedule) use ($collegeId, $checker): array {
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
                    'warnings' => $checker->warnings($schedule),
                    'has_attendance' => $schedule->attendance_records_count > 0,
                ];
            })
            ->all();

        return Inertia::render('dean/timetables/Index', [
            'terms' => $terms->map(fn (Term $term): array => ['id' => $term->id, 'label' => $term->label]),
            'term' => $term === null ? null : ['id' => $term->id, 'label' => $term->label],
            'sections' => $sections->map(fn (Section $section): array => ['id' => $section->id, 'label' => "{$section->program->code} {$section->name}"]),
            'rooms' => Room::where('is_active', true)->orderBy('building')->orderBy('code')->get(['id', 'code', 'name', 'building', 'capacity', 'room_type']),
            'teachers' => ScheduleBlocks::teacherOptions($collegeId),
            'batches' => $batchNames,
            // Every subject of the college's sections this term, for the class dialog.
            'offerings' => $term === null ? [] : SubjectOffering::query()
                ->with(['subject:id,code,title,lecture_units,laboratory_units', 'teacher.user:id,first_name,middle_name,last_name,suffix', 'batches:id,subject_offering_id,name'])
                ->withCount('enrollments')
                ->whereIn('section_id', $sections->pluck('id'))
                ->orderBy('section_id')
                ->orderBy('id')
                ->get(),
            'canEdit' => $term !== null && ! $term->isClosed(),
            'selected' => ['view' => $view['view'], 'target' => $target, 'batch' => $batch],
            'classes' => $classes,
        ]);
    }
}
