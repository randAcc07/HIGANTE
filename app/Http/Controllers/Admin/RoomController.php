<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RoomType;
use App\Http\Controllers\Admin\Concerns\RefusesDeletingInUse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoomRequest;
use App\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    use RefusesDeletingInUse;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(RoomType::class)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]) + ['search' => null, 'type' => null, 'status' => null];

        $rooms = Room::query()
            ->withCount('schedules')
            ->when($filters['search'], fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('building', 'like', "%{$search}%")))
            ->when($filters['type'], fn ($query, string $type) => $query->where('room_type', $type))
            ->when($filters['status'], fn ($query, string $status) => $query->where('is_active', $status === 'active'))
            ->orderBy('building')
            ->orderBy('code')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/rooms/Index', [
            'rooms' => $rooms,
            'buildings' => Room::whereNotNull('building')->distinct()->orderBy('building')->pluck('building'),
            'filters' => $filters,
        ]);
    }

    public function store(RoomRequest $request): RedirectResponse
    {
        Room::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room added.')]);

        return back();
    }

    public function update(RoomRequest $request, Room $room): RedirectResponse
    {
        $room->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room updated.')]);

        return back();
    }

    public function destroy(Room $room): RedirectResponse
    {
        $refusal = $this->refuseIfInUse($room->label, ['schedule' => $room->schedules()->count()], __('Deactivate it instead.'));

        if ($refusal !== null) {
            return $refusal;
        }

        $room->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room deleted.')]);

        return back();
    }
}
