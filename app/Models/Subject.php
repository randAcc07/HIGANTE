<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subject such as CE201 Statics, owned by one college and offerable to any
 * section of that college's programs.
 *
 * @property int $id
 * @property int $college_id
 * @property string $code
 * @property string $title
 * @property string $lecture_units
 * @property string $laboratory_units
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read College $college
 */
#[Fillable(['college_id', 'code', 'title', 'lecture_units', 'laboratory_units'])]
class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lecture_units' => 'decimal:1',
            'laboratory_units' => 'decimal:1',
        ];
    }

    /**
     * @return BelongsTo<College, $this>
     */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /**
     * @return HasMany<SubjectOffering, $this>
     */
    public function offerings(): HasMany
    {
        return $this->hasMany(SubjectOffering::class);
    }
}
