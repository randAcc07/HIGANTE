<?php

namespace Database\Factories;

use App\Enums\LearnerStatus;
use App\Models\Learner;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The learner's user belongs to the program's college.
 *
 * @extends Factory<Learner>
 */
class LearnerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            'user_id' => fn (array $attributes) => User::factory()->learner(
                self::program($attributes['program_id'])->college,
            ),
            'student_number' => fake()->unique()->numerify('2026-#####'),
            'year_level' => fake()->numberBetween(1, 4),
            'status' => LearnerStatus::Active,
        ];
    }

    protected static function program(int $id): Program
    {
        return Program::with('college')->findOrFail($id);
    }
}
