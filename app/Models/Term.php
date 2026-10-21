<?php

namespace App\Models;

use App\Enums\Semester;
use App\Enums\TermPhase;
use Carbon\CarbonImmutable;
use Database\Factories\TermFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property CarbonImmutable|null $closed_at
 * @property int|null $closed_by
 * @property-read string $label
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AttendancePolicy|null $attendancePolicy
 */
// closed_at and closed_by are set only by closing or reopening a term.
#[Fillable(['school_year', 'semester', 'starts_on', 'ends_on'])]
#[Appends(['label'])]
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
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * "1st Semester 2026-2027"; never empty.
     *
     * @return Attribute<non-falsy-string, never>
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->semester->label()} {$this->school_year}");
    }

    /**
     * Upcoming, in session or ended by the dates (on a given day, default
     * today), unless the admin has closed it.
     */
    public function phase(?CarbonImmutable $on = null): TermPhase
    {
        $on ??= CarbonImmutable::today();

        return match (true) {
            $this->closed_at !== null => TermPhase::Closed,
            $on->lt($this->starts_on) => TermPhase::Upcoming,
            $on->lte($this->ends_on) => TermPhase::InSession,
            default => TermPhase::Ended,
        };
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * The term in session today, if any.
     */
    public static function inSession(): ?self
    {
        $today = CarbonImmutable::today()->toDateString();

        return static::whereNull('closed_at')->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->first();
    }

    /**
     * The next term to start, if any.
     */
    public static function nextUpcoming(): ?self
    {
        return static::where('starts_on', '>', CarbonImmutable::today()->toDateString())->orderBy('starts_on')->first();
    }

    /**
     * The term being prepared: the next upcoming one, else the one in
     * session. Only where that is the stated subject (the registrar's
     * dashboard panel, the admit form's "Register for"), never as a page's
     * default term.
     */
    public static function forPreparation(): ?self
    {
        return static::nextUpcoming() ?? static::inSession();
    }

    /**
     * The term every term-scoped page opens on: the one in session, else
     * the next upcoming one, else the latest to start. Pages that prepare a
     * term offer a shortcut to the next one (nextTermShortcut).
     */
    public static function forViewing(): ?self
    {
        return static::inSession() ?? static::nextUpcoming() ?? static::orderByDesc('starts_on')->first();
    }

    /**
     * For a page that prepares terms (sections, timetables, registration):
     * the next upcoming term to offer as a shortcut, unless it is the one
     * shown.
     *
     * @return array{id: int, label: string}|null
     */
    public static function nextTermShortcut(?self $shown): ?array
    {
        $next = static::nextUpcoming();

        return $next === null || $next->id === $shown?->id ? null : ['id' => $next->id, 'label' => $next->label];
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
