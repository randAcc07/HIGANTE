<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\CalendarEventRequest;
use App\Models\CalendarEvent;
use App\Models\Term;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

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
            ->with('college:id,code')
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
                ...$event->only(['id', 'title', 'description', 'suspends_classes']),
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
        ]);
    }

    public function store(CalendarEventRequest $request): RedirectResponse
    {
        Gate::authorize('create', CalendarEvent::class);

        /** @var User $user */
        $user = $request->user();

        CalendarEvent::create([
            ...$request->validated(),
            'college_id' => $user->role === UserRole::Dean ? $user->college_id : null,
            'created_by' => $user->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event added.')]);

        return back();
    }

    public function update(CalendarEventRequest $request, CalendarEvent $event): RedirectResponse
    {
        Gate::authorize('update', $event);

        $event->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event updated.')]);

        return back();
    }

    public function destroy(CalendarEvent $event): RedirectResponse
    {
        Gate::authorize('delete', $event);

        $event->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event deleted.')]);

        return back();
    }
}
