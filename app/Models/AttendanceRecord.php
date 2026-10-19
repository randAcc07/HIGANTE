<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One learner at one scheduled class on one date. status is always the
 * teacher's own mark; an approved excuse is linked, never written over it.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $schedule_id
 * @property CarbonImmutable $class_date
 * @property AttendanceStatus $status
 * @property int|null $absence_excuse_id
 * @property int|null $recorded_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Enrollment $enrollment
 * @property-read Schedule $schedule
 * @property-read AbsenceExcuse|null $absenceExcuse
 */
#[Fillable(['enrollment_id', 'schedule_id', 'class_date', 'status', 'absence_excuse_id', 'recorded_by'])]
class AttendanceRecord extends Model
{
    /** @use HasFactory<AttendanceRecordFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_date' => 'date:Y-m-d',
            'status' => AttendanceStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<Schedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /**
     * @return BelongsTo<AbsenceExcuse, $this>
     */
    public function absenceExcuse(): BelongsTo
    {
        return $this->belongsTo(AbsenceExcuse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return HasMany<AttendanceAudit, $this>
     */
    public function audits(): HasMany
    {
        return $this->hasMany(AttendanceAudit::class);
    }
}
