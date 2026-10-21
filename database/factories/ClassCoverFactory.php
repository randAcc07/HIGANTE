<?php

namespace Database\Factories;

use App\Models\ClassCover;
use App\Models\Schedule;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassCover>
 */
class ClassCoverFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'schedule_id' => Schedule::factory(),
            'substitute_id' => Teacher::factory(),
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addWeek()->toDateString(),
        ];
    }
}
