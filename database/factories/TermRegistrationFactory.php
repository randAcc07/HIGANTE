<?php

namespace Database\Factories;

use App\Enums\RegistrationStatus;
use App\Models\Learner;
use App\Models\Term;
use App\Models\TermRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Registered but not yet sectioned.
 *
 * @extends Factory<TermRegistration>
 */
class TermRegistrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'term_id' => Term::factory(),
            'year_level' => fn (array $attributes) => self::learner($attributes['learner_id'])->year_level,
            'section_id' => null,
            'is_irregular' => false,
            'status' => RegistrationStatus::Registered,
            'registered_by' => null,
        ];
    }

    protected static function learner(int $id): Learner
    {
        return Learner::findOrFail($id);
    }
}
