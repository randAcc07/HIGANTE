<?php

namespace App\Models;

use App\Enums\DropReason;
use App\Enums\EnrollmentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A learner in one class. warning_level is the highest warning count
 * reached (each newly reached one is notified); reaching the drop count only
 * flags the enrollment, and the dean confirms or dismisses it. A dismissal
 * holds until equivalent absences pass drop_dismissed_absences; an absence
 * drop can be reinstated. Standing keeps these columns; see
 * App\Attendance\Standing.
 *
 * @property int $id
 * @property int $learner_id
 * @property int $subject_offering_id
 * @property int|null $batch_id
 * @property EnrollmentStatus $status
 * @property int $warning_level
 * @property CarbonImmutable|null $flagged_for_drop_at
 * @property CarbonImmutable|null $drop_confirmed_at
 * @property int|null $drop_confirmed_by
 * @property DropReason|null $drop_reason
 * @property CarbonImmutable|null $drop_dismissed_at
 * @property int|null $drop_dismissed_by
 * @property int|null $drop_dismissed_absences
 * @property CarbonImmutable|null $reinstated_at
 * @property int|null $reinstated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Learner $learner
 * @property-read SubjectOffering $subjectOffering
 * @property-read Batch|null $batch
 */
#[Fillable(['learner_id', 'subject_offering_id', 'batch_id', 'status', 'warning_level', 'flagged_for_drop_at', 'drop_confirmed_at', 'drop_confirmed_by', 'drop_reason', 'drop_dismissed_at', 'drop_dismissed_by', 'drop_dismissed_absences', 'reinstated_at', 'reinstated_by'])]
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'flagged_for_drop_at' => 'datetime',
            'drop_confirmed_at' => 'datetime',
            'drop_reason' => DropReason::class,
            'drop_dismissed_at' => 'datetime',
            'reinstated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Learner, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /**
     * @return BelongsTo<SubjectOffering, $this>
     */
    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class);
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dropConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'drop_confirmed_by');
    }

    /**
     * @return HasMany<AttendanceRecord, $this>
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
