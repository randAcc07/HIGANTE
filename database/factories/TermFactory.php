<?php

namespace Database\Factories;

use App\Enums\Semester;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A first semester, August to December, of a unique school year.
 *
 * @extends Factory<Term>
 */
class TermFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2000, 2999);

        return [
            'school_year' => $year.'-'.($year + 1),
            'semester' => Semester::First,
            'starts_on' => CarbonImmutable::create($year, 8, 10),
            'ends_on' => CarbonImmutable::create($year, 12, 18),
        ];
    }
}
