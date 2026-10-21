<?php

namespace App\Models;

use App\Enums\DecisionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ExcuseDecisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One class's decision on an excuse: a teacher of the class approves or
 * declines it for that class (the dean, for a class without a teacher).
 *
 * @property int $id
 * @property int $absence_excuse_id
 * @property int $subject_offering_id
 * @property DecisionStatus $status
 * @property string|null $remarks
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property int|null $revoked_by
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoke_remarks
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AbsenceExcuse $absenceExcuse
 * @property-read SubjectOffering $subjectOffering
 * @property-read User|null $decider
 */
#[Fillable(['absence_excuse_id', 'subject_offering_id', 'status', 'remarks', 'decided_by', 'decided_at', 'revoked_by', 'revoked_at', 'revoke_remarks'])]
class ExcuseDecision extends Model
{
    /** @use HasFactory<ExcuseDecisionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DecisionStatus::class,
            'decided_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AbsenceExcuse, $this>
     */
    public function absenceExcuse(): BelongsTo
    {
        return $this->belongsTo(AbsenceExcuse::class);
    }

    /**
     * @return BelongsTo<SubjectOffering, $this>
     */
    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
