<?php

namespace Database\Factories;

use App\Enums\DutyStatus;
use App\Models\College;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Teacher>
 */
class TeacherFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->teacher(),
            'employee_number' => fake()->unique()->numerify('EMP-#####'),
            'duty_status' => DutyStatus::Active,
        ];
    }

    public function forCollege(College $college): static
    {
        return $this->state(['user_id' => User::factory()->teacher($college)]);
    }

    public function onLeave(): static
    {
        return $this->state(['duty_status' => DutyStatus::OnLeave]);
    }
}
