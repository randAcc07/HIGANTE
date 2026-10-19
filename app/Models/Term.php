<?php

namespace App\Models;

use App\Enums\Semester;
use Carbon\CarbonImmutable;
use Database\Factories\TermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $school_year
 * @property Semester $semester
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AttendancePolicy|null $attendancePolicy
 */
#[Fillable(['school_year', 'semester', 'starts_on', 'ends_on'])]
class Term extends Model
{
    /** @use HasFactory<TermFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'semester' => Semester::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * @return HasOne<AttendancePolicy, $this>
     */
    public function attendancePolicy(): HasOne
    {
        return $this->hasOne(AttendancePolicy::class);
    }

    /**
     * @return HasMany<Section, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    /**
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    /**
     * @return HasMany<TermRegistration, $this>
     */
    public function termRegistrations(): HasMany
    {
        return $this->hasMany(TermRegistration::class);
    }
}
