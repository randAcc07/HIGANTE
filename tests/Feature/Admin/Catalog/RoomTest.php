<?php

namespace Tests\Feature\Admin\Catalog;

use App\Enums\RoomType;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RoomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_rooms_can_be_filtered_by_type_status_and_search()
    {
        Room::factory()->create(['code' => 'MB-101', 'building' => 'Main Building']);
        Room::factory()->laboratory()->create(['code' => 'CL-1', 'building' => 'Science Building']);
        Room::factory()->create(['code' => 'MB-304', 'building' => 'Main Building', 'is_active' => false]);

        $this->get(route('admin.rooms.index', ['type' => 'laboratory']))
            ->assertInertia(fn (Assert $page) => $page->component('admin/rooms/Index')->has('rooms.data', 1)->where('rooms.data.0.code', 'CL-1'));

        $this->get(route('admin.rooms.index', ['status' => 'inactive']))
            ->assertInertia(fn (Assert $page) => $page->has('rooms.data', 1)->where('rooms.data.0.code', 'MB-304'));

        $this->get(route('admin.rooms.index', ['search' => 'main']))
            ->assertInertia(fn (Assert $page) => $page->has('rooms.data', 2)->has('buildings', 2));

        $this->get(route('admin.rooms.index', ['type' => 'kitchen']))->assertSessionHasErrors('type');
    }

    public function test_a_room_can_be_added_without_a_known_capacity()
    {
        $this->post(route('admin.rooms.store'), ['code' => 'GYM', 'building' => 'Gymnasium', 'floor' => null, 'capacity' => null, 'room_type' => 'lecture', 'is_active' => true])
            ->assertSessionHasNoErrors();

        $room = Room::where('code', 'GYM')->sole();
        $this->assertNull($room->capacity);
        $this->assertSame(RoomType::Lecture, $room->room_type);
    }

    public function test_room_input_is_checked()
    {
        Room::factory()->create(['code' => 'MB-101']);

        $this->post(route('admin.rooms.store'), ['code' => 'MB-101', 'capacity' => 0, 'room_type' => 'kitchen', 'is_active' => true])
            ->assertSessionHasErrors(['code', 'capacity', 'room_type']);
    }

    public function test_a_room_in_use_is_deactivated_rather_than_deleted()
    {
        $room = Schedule::factory()->create()->room;
        $this->assertNotNull($room);

        $this->delete(route('admin.rooms.destroy', $room))
            ->assertInertiaFlash('toast.message', "{$room->code} still has 1 schedule, so it can't be deleted. Deactivate it instead.");
        $this->assertModelExists($room);

        $this->put(route('admin.rooms.update', $room), [...$room->only(['code', 'building', 'floor', 'capacity']), 'room_type' => $room->room_type->value, 'is_active' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($room->refresh()->is_active);

        $unused = Room::factory()->create();
        $this->delete(route('admin.rooms.destroy', $unused));
        $this->assertModelMissing($unused);
    }
}
