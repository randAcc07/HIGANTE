<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceAudit>
 */
class AttendanceAuditFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attendance_record_id' => AttendanceRecord::factory(),
            'changed_by' => null,
            'old_status' => null,
            'new_status' => AttendanceStatus::Present,
            'old_absence_excuse_id' => null,
            'new_absence_excuse_id' => null,
            'changed_at' => now(),
        ];
    }
}
