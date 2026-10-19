<?php

namespace App\Models;

use App\Enums\RoomType;
use Carbon\CarbonImmutable;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Campus-wide; any dean can book any room. capacity is optional and only
 * ever warns.
 *
 * @property int $id
 * @property string $code
 * @property string|null $building
 * @property int|null $floor
 * @property int|null $capacity
 * @property RoomType $room_type
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'building', 'floor', 'capacity', 'room_type', 'is_active'])]
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
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }
}
