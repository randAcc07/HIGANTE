<?php

namespace App\Models;

use App\Enums\ExcuseReason;
use App\Enums\ExcuseStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AbsenceExcuseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request to excuse a learner's absences over a date range, reviewed by
 * the dean of the learner's college. Revoked by status, never deleted.
 *
 * @property int $id
 * @property int $learner_id
 * @property CarbonImmutable $date_from
 * @property CarbonImmutable $date_to
 * @property ExcuseReason $reason
 * @property string|null $remarks
 * @property ExcuseStatus $status
 * @property int|null $submitted_by
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_remarks
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by
 * @property string|null $revoke_remarks
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Learner $learner
 */
#[Fillable(['learner_id', 'date_from', 'date_to', 'reason', 'remarks', 'status', 'submitted_by', 'reviewed_by', 'reviewed_at', 'review_remarks', 'revoked_at', 'revoked_by', 'revoke_remarks'])]
class AbsenceExcuse extends Model
{
    /** @use HasFactory<AbsenceExcuseFactory> */
    use HasFactory;

    /**
     * An excuse is due within this many days after its last day; later
     * ones are still accepted but flagged for the dean.
     */
    public const DUE_WITHIN_DAYS = 7;

    /**
     * How many days past the deadline it was submitted (0 = on time). A
     * not-yet-saved excuse counts as submitted today.
     */
    public function daysLate(): int
    {
        $due = $this->date_to->addDays(self::DUE_WITHIN_DAYS);
        $submitted = ($this->created_at ?? CarbonImmutable::now())->startOfDay();

        return max(0, (int) $due->diffInDays($submitted, false));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
            'reason' => ExcuseReason::class,
            'status' => ExcuseStatus::class,
            'reviewed_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<AbsenceExcuseDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(AbsenceExcuseDocument::class);
    }

    /**
     * @return HasMany<AttendanceRecord, $this>
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
