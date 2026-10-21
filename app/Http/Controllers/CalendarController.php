<?php

namespace App\Http\Controllers;

use App\Attendance\Standing;
use App\Enums\UserRole;
use App\Http\Requests\CalendarEventRequest;
use App\Models\CalendarEvent;
use App\Models\College;
use App\Models\Term;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Holidays, suspensions and college events for one term at a time. The
 * admin and the registrar see every event; everyone else sees campus-wide
 * events and their own college's.
 */
class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $terms = Term::orderByDesc('starts_on')->get();
        $term = $terms->firstWhere('id', $request->integer('term')) ?? Term::forViewing();

        $events = CalendarEvent::query()
            ->with(['college:id,code', 'programs:id,code'])
            ->when($user->role->belongsToCollege(), fn ($query) => $query->where(fn ($query) => $query
                ->whereNull('college_id')
                ->orWhere('college_id', $user->college_id)))
            ->when($term, fn ($query, Term $term) => $query
                ->where('starts_on', '<=', $term->ends_on->toDateString())
                ->where('ends_on', '>=', $term->starts_on->toDateString()))
            ->orderBy('starts_on')
            ->orderBy('ends_on')
            ->get()
            ->map(fn (CalendarEvent $event): array => [
                ...$event->only(['id', 'title', 'description', 'suspends_classes', 'memo_number', 'memo_name']),
                'suspends_from' => $event->suspends_from === null ? null : substr($event->suspends_from, 0, 5),
                'suspends_until' => $event->suspends_until === null ? null : substr($event->suspends_until, 0, 5),
                'program_ids' => $event->programs->modelKeys(),
                'programs' => $event->programs->sortBy('code')->pluck('code')->values(),
                'year_levels' => $event->year_levels ?? [],
                'memo_url' => $event->memo_path === null ? null : route('calendar.events.memo', $event, false),
                'starts_on' => $event->starts_on->toDateString(),
                'ends_on' => $event->ends_on->toDateString(),
                'college' => $event->college?->code,
                'can_edit' => $user->can('update', $event),
            ]);

        return Inertia::render('calendar/Index', [
            'terms' => $terms->map(fn (Term $term): array => ['id' => $term->id, 'label' => $term->label]),
            'term' => $term === null ? null : [
                'id' => $term->id,
                'label' => $term->label,
                'starts_on' => $term->starts_on->toDateString(),
                'ends_on' => $term->ends_on->toDateString(),
            ],
            'events' => $events,
            'canCreate' => $user->can('create', CalendarEvent::class),
            // Where a new event goes: the dean's college, or campus-wide.
            'newEventScope' => $user->role === UserRole::Dean ? $user->college?->code : null,
            // Programs a suspension can be narrowed to: the dean's own
            // college's, or any (the admin's campus-wide events).
            'programOptions' => College::query()
                ->when($user->role === UserRole::Dean, fn ($query) => $query->whereKey($user->college_id))
                ->with(['programs' => fn ($programs) => $programs->orderBy('code')->select(['id', 'college_id', 'code', 'name'])])
                ->orderBy('code')
                ->get(['id', 'code']),
        ]);
    }

    public function store(CalendarEventRequest $request, Standing $standing): RedirectResponse
    {
        Gate::authorize('create', CalendarEvent::class);

        /** @var User $user */
        $user = $request->user();

        $event = CalendarEvent::create([
            ...self::fields($request),
            'college_id' => $user->role === UserRole::Dean ? $user->college_id : null,
            'created_by' => $user->id,
        ]);
        $event->programs()->sync($request->boolean('suspends_classes') ? $request->input('program_ids', []) : []);
        $this->saveMemo($request, $event);
        self::recount($standing, $event);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event added.')]);

        return back();
    }

    public function update(CalendarEventRequest $request, CalendarEvent $event, Standing $standing): RedirectResponse
    {
        Gate::authorize('update', $event);

        $before = clone $event;
        $event->update(self::fields($request));
        $event->programs()->sync($request->boolean('suspends_classes') ? $request->input('program_ids', []) : []);
        $this->saveMemo($request, $event);
        self::recount($standing, $before);
        self::recount($standing, $event);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event updated.')]);

        return back();
    }

    public function destroy(CalendarEvent $event, Standing $standing): RedirectResponse
    {
        Gate::authorize('delete', $event);

        if ($event->memo_path !== null) {
            Storage::delete($event->memo_path);
        }

        $event->delete();
        self::recount($standing, $event);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event deleted.')]);

        return back();
    }

    /**
     * The memorandum attached to an event, for anyone who can see the event.
     */
    public function memo(CalendarEvent $event): StreamedResponse
    {
        Gate::authorize('view', $event);
        abort_if($event->memo_path === null || ! Storage::exists($event->memo_path), 404);

        return Storage::response($event->memo_path, $event->memo_name ?? 'memo.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private static function fields(CalendarEventRequest $request): array
    {
        $fields = $request->safe()->only(['title', 'description', 'starts_on', 'ends_on', 'suspends_classes', 'suspends_from', 'suspends_until', 'memo_number']);
        $years = array_values(array_map('intval', (array) $request->input('year_levels', [])));
        sort($years);
        $fields['year_levels'] = $years === [] ? null : $years;

        if (! $request->boolean('suspends_classes')) {
            $fields['suspends_from'] = $fields['suspends_until'] = $fields['year_levels'] = null;
        }

        return $fields;
    }

    private function saveMemo(CalendarEventRequest $request, CalendarEvent $event): void
    {
        $file = $request->file('memo');
        $replace = $file instanceof UploadedFile;

        if (($replace || $request->boolean('remove_memo')) && $event->memo_path !== null) {
            Storage::delete($event->memo_path);
            $event->forceFill(['memo_path' => null, 'memo_name' => null])->save();
        }

        if ($replace) {
            $event->forceFill([
                'memo_path' => $file->store('calendar-memos'),
                'memo_name' => $file->getClientOriginalName(),
            ])->save();
        }
    }

    /**
     * A suspension moves which meetings count: recount everyone marked on
     * its days (before and after a change).
     */
    private static function recount(Standing $standing, CalendarEvent $event): void
    {
        if ($event->suspends_classes || $event->getOriginal('suspends_classes')) {
            $standing->refreshDates($event->college_id, $event->starts_on, $event->ends_on);
        }
    }
}
