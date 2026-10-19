<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Marked present, on the schedule's first class day of the term.
 *
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'schedule_id' => fn (array $attributes) => Schedule::factory()->state([
                'subject_offering_id' => self::enrollment($attributes['enrollment_id'])->subject_offering_id,
            ]),
            'class_date' => function (array $attributes) {
                $schedule = self::schedule($attributes['schedule_id']);
                $start = $schedule->term->starts_on;

                return $start->addDays(($schedule->weekday() - $start->dayOfWeekIso + 7) % 7);
            },
            'status' => AttendanceStatus::Present,
            'absence_excuse_id' => null,
            'recorded_by' => null,
        ];
    }

    protected static function enrollment(int $id): Enrollment
    {
        return Enrollment::findOrFail($id);
    }

    protected static function schedule(int $id): Schedule
    {
        return Schedule::with('term')->findOrFail($id);
    }

    public function late(): static
    {
        return $this->state(['status' => AttendanceStatus::Late]);
    }

    public function absent(): static
    {
        return $this->state(['status' => AttendanceStatus::Absent]);
    }
}
