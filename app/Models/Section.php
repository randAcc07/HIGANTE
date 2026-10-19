<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A block section (e.g. BSCE 2A) for one term. capacity is optional and only
 * guides auto-sectioning.
 *
 * @property int $id
 * @property int $term_id
 * @property int $program_id
 * @property int $year_level
 * @property string $name
 * @property int|null $capacity
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Term $term
 * @property-read Program $program
 */
#[Fillable(['term_id', 'program_id', 'year_level', 'name', 'capacity'])]
class Section extends Model
{
    /** @use HasFactory<SectionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return HasMany<SubjectOffering, $this>
     */
    public function subjectOfferings(): HasMany
    {
        return $this->hasMany(SubjectOffering::class);
    }

    /**
     * @return HasMany<TermRegistration, $this>
     */
    public function termRegistrations(): HasMany
    {
        return $this->hasMany(TermRegistration::class);
    }
}
