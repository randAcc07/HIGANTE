<?php

namespace Database\Factories;

use App\Enums\ExcuseReason;
use App\Enums\ExcuseStatus;
use App\Models\AbsenceExcuse;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pending one-day illness excuse.
 *
 * @extends Factory<AbsenceExcuse>
 */
class AbsenceExcuseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $day = CarbonImmutable::instance(fake()->dateTimeBetween('2026-08-10', '2026-12-18'))->startOfDay();

        return [
            'learner_id' => Learner::factory(),
            'date_from' => $day,
            'date_to' => $day,
            'reason' => ExcuseReason::Illness,
            'remarks' => null,
            'status' => ExcuseStatus::Pending,
            'submitted_by' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_remarks' => null,
        ];
    }

    public function medical(): static
    {
        return $this->state(['reason' => ExcuseReason::Medical]);
    }

    public function approved(): static
    {
        return $this->state(['status' => ExcuseStatus::Approved, 'reviewed_at' => now()]);
    }
}
