<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Section>
 */
class SectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $yearLevel = fake()->numberBetween(1, 4);

        return [
            'term_id' => Term::factory(),
            'program_id' => Program::factory(),
            'year_level' => $yearLevel,
            'name' => $yearLevel.fake()->unique()->bothify('?#'),
            'capacity' => null,
        ];
    }
}
