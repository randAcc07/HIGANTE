<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SubjectOfferingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subject taught to a section in its term. teacher_id is the default
 * copied onto new schedules; each schedule's own teacher is the one that
 * counts.
 *
 * @property int $id
 * @property int $section_id
 * @property int $subject_id
 * @property int|null $teacher_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Section $section
 * @property-read Subject $subject
 * @property-read Teacher|null $teacher
 */
#[Fillable(['section_id', 'subject_id', 'teacher_id'])]
class SubjectOffering extends Model
{
    /** @use HasFactory<SubjectOfferingFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return HasMany<Batch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /**
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    /**
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
