<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ClassCoverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A substitute covering one class from starts_on to ends_on (inclusive).
 *
 * @property int $id
 * @property int $schedule_id
 * @property int $substitute_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Schedule $schedule
 * @property-read Teacher $substitute
 */
#[Fillable(['schedule_id', 'substitute_id', 'starts_on', 'ends_on', 'created_by'])]
class ClassCover extends Model
{
    /** @use HasFactory<ClassCoverFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Schedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function substitute(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'substitute_id');
    }

    /**
     * Covers whose range includes the date.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function onDate(Builder $query, CarbonImmutable $date): void
    {
        $query->where('starts_on', '<=', $date->toDateString())->where('ends_on', '>=', $date->toDateString());
    }

    /**
     * "Oct 27–Nov 7" (or one day).
     */
    public function range(): string
    {
        if ($this->starts_on->isSameDay($this->ends_on)) {
            return $this->starts_on->format('M j');
        }

        return $this->starts_on->format('M j').'–'.($this->starts_on->isSameMonth($this->ends_on) ? $this->ends_on->format('j') : $this->ends_on->format('M j'));
    }
}
