<?php

namespace Database\Factories;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Learner;
use App\Models\SubjectOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The learner is in the offering's section program (and so its college).
 *
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
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
            'learner_id' => fn (array $attributes) => Learner::factory()->state([
                'program_id' => self::offering($attributes['subject_offering_id'])->section->program_id,
            ]),
            'batch_id' => null,
            'status' => EnrollmentStatus::Enrolled,
            'warning_level' => 0,
            'flagged_for_drop_at' => null,
            'drop_confirmed_at' => null,
            'drop_confirmed_by' => null,
        ];
    }

    protected static function offering(int $id): SubjectOffering
    {
        return SubjectOffering::with('section')->findOrFail($id);
    }
}
