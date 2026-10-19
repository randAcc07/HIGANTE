<?php

namespace App\Models;

use App\Enums\RoomType;
use Carbon\CarbonImmutable;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Campus-wide; any dean can book any room. capacity is optional and only
 * ever warns. A room with a name (a laboratory, the gym) is labelled by it;
 * otherwise by its code.
 *
 * @property int $id
 * @property string $code
 * @property string|null $name
 * @property-read string $label
 * @property string|null $building
 * @property int|null $floor
 * @property int|null $capacity
 * @property RoomType $room_type
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'name', 'building', 'floor', 'capacity', 'room_type', 'is_active'])]
#[Appends(['label'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'room_type' => RoomType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * What to call the room: its name if it has one, else its code.
     *
     * @return Attribute<string, never>
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => $this->name ?? $this->code);
    }

    /**
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }
}
