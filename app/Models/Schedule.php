<?php

namespace App\Models;

use App\Enums\ScheduleMode;
use App\Support\ReferenceWeek;
use Carbon\CarbonImmutable;
use Database\Factories\ScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One weekly slot of a subject offering (e.g. Statics, 2A, Mon 08:00-10:00,
 * Room 301). A NULL batch means the whole offering meets. starts_at/ends_at
 * sit on the reference week; see ReferenceWeek.
 *
 * @property int $id
 * @property int $subject_offering_id
 * @property int|null $batch_id
 * @property int $term_id
 * @property int $teacher_id
 * @property int|null $room_id
 * @property ScheduleMode $mode
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SubjectOffering $subjectOffering
 * @property-read Batch|null $batch
 * @property-read Term $term
 * @property-read Teacher $teacher
 * @property-read Room|null $room
 */
#[Fillable(['subject_offering_id', 'batch_id', 'term_id', 'teacher_id', 'room_id', 'mode', 'starts_at', 'ends_at'])]
class Schedule extends Model
{
    /** @use HasFactory<ScheduleFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ScheduleMode::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Set the weekly slot from an ISO weekday (1 = Monday) and "HH:MM" times.
     */
    public function setSlot(int $isoWeekday, string $startTime, string $endTime): static
    {
        $this->starts_at = ReferenceWeek::at($isoWeekday, $startTime);
        $this->ends_at = ReferenceWeek::at($isoWeekday, $endTime);

        return $this;
    }

    /**
     * ISO weekday, 1 = Monday ... 7 = Sunday.
     */
    public function weekday(): int
    {
        return $this->starts_at->dayOfWeekIso;
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
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return HasMany<AttendanceRecord, $this>
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
