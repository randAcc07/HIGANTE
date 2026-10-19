<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AttendancePolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One term's absence rules. Per enrollment:
 *   equivalent absences = unexcused absences + floor(lates / lates_per_absence)
 *
 * @property int $id
 * @property int $term_id
 * @property int $lates_per_absence
 * @property int $drop_at_absences
 * @property list<int> $warn_at_absences
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Term $term
 */
#[Fillable(['term_id', 'lates_per_absence', 'drop_at_absences', 'warn_at_absences'])]
class AttendancePolicy extends Model
{
    /** @use HasFactory<AttendancePolicyFactory> */
    use HasFactory;

    /**
     * The school handbook: 3 lates equal 1 absence, warnings at 3 and 4
     * equivalent absences, dropped from the class at 5.
     *
     * @var array{lates_per_absence: int, drop_at_absences: int, warn_at_absences: list<int>}
     */
    public const HANDBOOK = [
        'lates_per_absence' => 3,
        'drop_at_absences' => 5,
        'warn_at_absences' => [3, 4],
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'warn_at_absences' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
