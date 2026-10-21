<?php

namespace Database\Factories;

use App\Enums\DecisionStatus;
use App\Models\AbsenceExcuse;
use App\Models\ExcuseDecision;
use App\Models\SubjectOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pending decision for one class of an excuse.
 *
 * @extends Factory<ExcuseDecision>
 */
class ExcuseDecisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'absence_excuse_id' => AbsenceExcuse::factory(),
            'subject_offering_id' => SubjectOffering::factory(),
            'status' => DecisionStatus::Pending,
        ];
    }
}
