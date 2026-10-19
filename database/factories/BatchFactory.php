<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\SubjectOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Batch>
 */
class BatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_offering_id' => SubjectOffering::factory(),
            'name' => 'Batch '.fake()->unique()->numberBetween(1, 99999),
        ];
    }
}
