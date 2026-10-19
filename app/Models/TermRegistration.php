<?php

namespace App\Models;

use App\Enums\RegistrationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TermRegistrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A learner registered for one term. A NULL section means the learner is
 * waiting for the dean to place them.
 *
 * @property int $id
 * @property int $learner_id
 * @property int $term_id
 * @property int $year_level
 * @property int|null $section_id
 * @property bool $is_irregular
 * @property RegistrationStatus $status
 * @property int|null $registered_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Learner $learner
 * @property-read Term $term
 * @property-read Section|null $section
 */
#[Fillable(['learner_id', 'term_id', 'year_level', 'section_id', 'is_irregular', 'status', 'registered_by'])]
class TermRegistration extends Model
{
    /** @use HasFactory<TermRegistrationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_irregular' => 'boolean',
            'status' => RegistrationStatus::class,
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
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
