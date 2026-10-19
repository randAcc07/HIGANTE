<?php

namespace Database\Factories;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A campus-wide, one-day class suspension.
 *
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
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
            'college_id' => null,
            'title' => ucfirst(fake()->word()).' Day',
            'description' => null,
            'starts_on' => $day,
            'ends_on' => $day,
            'suspends_classes' => true,
            'created_by' => null,
        ];
    }
}
