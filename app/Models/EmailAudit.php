<?php

namespace App\Models;

use App\Enums\EmailChangeMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of a user's email: a completed self-service change, or an
 * admin override with its reason.
 *
 * @property int $id
 * @property int $user_id
 * @property string $old_email
 * @property string $new_email
 * @property EmailChangeMethod $method
 * @property int|null $changed_by
 * @property string|null $reason
 * @property CarbonImmutable $changed_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'old_email', 'new_email', 'method', 'changed_by', 'reason', 'changed_at'])]
#[WithoutTimestamps]
class EmailAudit extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => EmailChangeMethod::class,
            'changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
