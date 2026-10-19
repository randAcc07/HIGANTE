<?php

namespace Database\Factories;

use App\Models\College;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'college_id' => College::factory(),
            'code' => strtoupper(fake()->unique()->bothify('???###')),
            'title' => ucfirst(fake()->word()).' '.ucfirst(fake()->word()),
            'lecture_units' => 3,
            'laboratory_units' => 0,
        ];
    }
}
