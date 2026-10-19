<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AttendanceAuditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to an attendance mark or its excuse. old_status is NULL for the
 * first mark.
 *
 * @property int $id
 * @property int $attendance_record_id
 * @property int|null $changed_by
 * @property AttendanceStatus|null $old_status
 * @property AttendanceStatus $new_status
 * @property int|null $old_absence_excuse_id
 * @property int|null $new_absence_excuse_id
 * @property CarbonImmutable $changed_at
 * @property-read AttendanceRecord $attendanceRecord
 */
#[Fillable(['attendance_record_id', 'changed_by', 'old_status', 'new_status', 'old_absence_excuse_id', 'new_absence_excuse_id', 'changed_at'])]
#[WithoutTimestamps]
class AttendanceAudit extends Model
{
    /** @use HasFactory<AttendanceAuditFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_status' => AttendanceStatus::class,
            'new_status' => AttendanceStatus::class,
            'changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AttendanceRecord, $this>
     */
    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
