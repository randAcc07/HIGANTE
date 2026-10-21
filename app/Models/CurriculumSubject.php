<?php

namespace App\Models;

use App\Enums\Semester;
use Carbon\CarbonImmutable;
use Database\Factories\CurriculumSubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a program's curriculum: a subject of the program's college,
 * taken in a year level and semester.
 *
 * @property int $id
 * @property int $program_id
 * @property int $subject_id
 * @property int $year_level
 * @property Semester $semester
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Program $program
 * @property-read Subject $subject
 */
#[Fillable(['program_id', 'subject_id', 'year_level', 'semester'])]
class CurriculumSubject extends Model
{
    /** @use HasFactory<CurriculumSubjectFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year_level' => 'integer',
            'semester' => Semester::class,
        ];
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
