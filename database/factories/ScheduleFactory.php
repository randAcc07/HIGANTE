<?php

namespace Database\Factories;

use App\Enums\ScheduleMode;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\SubjectOffering;
use App\Models\Teacher;
use App\Support\ReferenceWeek;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * By default every schedule gets its own room and its own teacher (from the
 * offering's college), so factory-made schedules never trip the overlap
 * keys. Pass a teacher or room explicitly to test clashes.
 *
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $weekday = fake()->numberBetween(1, 6);
        $startHour = fake()->numberBetween(7, 18);

        return [
            'subject_offering_id' => SubjectOffering::factory(),
            'batch_id' => null,
            'term_id' => fn (array $attributes) => self::offering($attributes['subject_offering_id'])->section->term_id,
            'teacher_id' => fn (array $attributes) => Teacher::factory()->forCollege(
                self::offering($attributes['subject_offering_id'])->section->program->college,
            ),
            'room_id' => Room::factory(),
            'mode' => ScheduleMode::Onsite,
            'starts_at' => ReferenceWeek::at($weekday, sprintf('%02d:00', $startHour)),
            'ends_at' => ReferenceWeek::at($weekday, sprintf('%02d:30', $startHour + 1)),
        ];
    }

    /**
     * Set the weekly slot from an ISO weekday (1 = Monday) and "HH:MM" times.
     */
    public function slot(int $isoWeekday, string $startTime, string $endTime): static
    {
        return $this->state([
            'starts_at' => ReferenceWeek::at($isoWeekday, $startTime),
            'ends_at' => ReferenceWeek::at($isoWeekday, $endTime),
        ]);
    }

    public function online(): static
    {
        return $this->state(['mode' => ScheduleMode::Online, 'room_id' => null]);
    }

    protected static function offering(int $id): SubjectOffering
    {
        return SubjectOffering::with('section.program.college')->findOrFail($id);
    }
}
