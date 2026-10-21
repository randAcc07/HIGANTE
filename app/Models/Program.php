<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProgramFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A degree program, e.g. BS Civil Engineering (what the school calls a course).
 *
 * @property int $id
 * @property int $college_id
 * @property string $code
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read College $college
 */
#[Fillable(['college_id', 'code', 'name'])]
class Program extends Model
{
    /** @use HasFactory<ProgramFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<College, $this>
     */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /**
     * @return HasMany<Learner, $this>
     */
    public function learners(): HasMany
    {
        return $this->hasMany(Learner::class);
    }

    /**
     * The program's curriculum, one line per subject.
     *
     * @return HasMany<CurriculumSubject, $this>
     */
    public function curriculum(): HasMany
    {
        return $this->hasMany(CurriculumSubject::class);
    }

    /**
     * @return HasMany<Section, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }
}
