<?php

namespace Database\Factories;

use App\Models\AttendancePolicy;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendancePolicy>
 */
class AttendancePolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            ...AttendancePolicy::HANDBOOK,
        ];
    }
}
