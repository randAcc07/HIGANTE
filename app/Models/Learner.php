<?php

namespace App\Models;

use App\Enums\LearnerStatus;
use Carbon\CarbonImmutable;
use Database\Factories\LearnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A learner's student record. The program must belong to the user's college.
 *
 * @property int $id
 * @property int $user_id
 * @property int $program_id
 * @property string $student_number
 * @property int $year_level
 * @property LearnerStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Program $program
 */
#[Fillable(['user_id', 'program_id', 'student_number', 'year_level', 'status'])]
class Learner extends Model
{
    /** @use HasFactory<LearnerFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LearnerStatus::class,
        ];
    }

    /**
     * Student number, or the account's last name, first name or email.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function matching(Builder $query, string $search): void
    {
        $query->where(fn ($query) => $query
            ->where('student_number', 'like', "%{$search}%")
            ->orWhereHas('user', fn ($user) => $user
                ->where('last_name', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return HasMany<TermRegistration, $this>
     */
    public function termRegistrations(): HasMany
    {
        return $this->hasMany(TermRegistration::class);
    }

    /**
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * @return HasMany<AbsenceExcuse, $this>
     */
    public function absenceExcuses(): HasMany
    {
        return $this->hasMany(AbsenceExcuse::class);
    }
}
