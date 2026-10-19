<?php

namespace Database\Factories;

use App\Enums\RoomType;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'R-'.fake()->unique()->numerify('####'),
            'building' => fake()->randomElement(['Main', 'Annex', 'Science']).' Building',
            'floor' => fake()->numberBetween(1, 4),
            'capacity' => 40,
            'room_type' => RoomType::Lecture,
            'is_active' => true,
        ];
    }

    public function laboratory(): static
    {
        return $this->state(['room_type' => RoomType::Laboratory]);
    }

    public function venue(): static
    {
        return $this->state(['room_type' => RoomType::Venue]);
    }
}
