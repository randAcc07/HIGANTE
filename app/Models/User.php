<?php

namespace App\Models;

use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property UserRole $role
 * @property int|null $college_id
 * @property bool $is_active
 * @property string $last_name
 * @property string $first_name
 * @property string|null $middle_name
 * @property string|null $suffix
 * @property-read string $full_name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read College|null $college
 * @property-read Teacher|null $teacher
 * @property-read Learner|null $learner
 * @property-read EmailChange|null $emailChange
 *
 * role, college_id and is_active are deliberately not fillable: only the
 * admin or registrar assigns them, explicitly, never from a mass-assigned
 * form.
 */
#[Fillable(['last_name', 'first_name', 'middle_name', 'suffix', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
#[Appends(['full_name'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * "Juan P. Dela Cruz Jr." for display.
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => implode(' ', array_filter([
            $this->first_name,
            $this->middle_name ? mb_substr($this->middle_name, 0, 1).'.' : null,
            $this->last_name,
            $this->suffix,
        ])));
    }

    /**
     * @return BelongsTo<College, $this>
     */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /**
     * @return HasOne<Teacher, $this>
     */
    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    /**
     * @return HasOne<Learner, $this>
     */
    public function learner(): HasOne
    {
        return $this->hasOne(Learner::class);
    }

    /**
     * The pending email change, if any.
     *
     * @return HasOne<EmailChange, $this>
     */
    public function emailChange(): HasOne
    {
        return $this->hasOne(EmailChange::class);
    }

    /**
     * @return HasMany<EmailAudit, $this>
     */
    public function emailAudits(): HasMany
    {
        return $this->hasMany(EmailAudit::class);
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function getPasskeyDisplayName(): string
    {
        return $this->full_name;
    }
}
