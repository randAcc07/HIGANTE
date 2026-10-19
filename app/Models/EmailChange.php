<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EmailChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * A pending email change. It applies once the new address has confirmed and
 * the old address has approved. The emailed links are signed URLs that
 * expire with the request, so nothing secret is stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $new_email
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $new_confirmed_at
 * @property CarbonImmutable|null $old_approved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'new_email', 'expires_at', 'new_confirmed_at', 'old_approved_at'])]
class EmailChange extends Model
{
    /** @use HasFactory<EmailChangeFactory> */
    use HasFactory, MassPrunable;

    /** How long both addresses have to respond. */
    public const LIFETIME_HOURS = 24;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'new_confirmed_at' => 'datetime',
            'old_approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isComplete(): bool
    {
        return $this->new_confirmed_at !== null && $this->old_approved_at !== null;
    }

    /**
     * A signed link for one side of the request: `new` (the new address
     * confirms) or `current` (the old address approves or denies). $action
     * is the route name suffix, e.g. "show", "confirm", "approve".
     */
    public function signedUrl(string $side, string $action = 'show'): string
    {
        return URL::temporarySignedRoute("email-change.{$side}.{$action}", $this->expires_at, ['emailChange' => $this->id]);
    }

    /**
     * Expired requests are removed by `model:prune`.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now());
    }
}
