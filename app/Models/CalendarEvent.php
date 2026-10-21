<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A holiday or suspension, campus-wide (no college) or for one college. A
 * suspension covers whole days unless it has a window: it then suspends the
 * classes that start at or after suspends_from and before suspends_until
 * (either end may be open). memo_* is the memorandum that announced it.
 *
 * @property int $id
 * @property int|null $college_id
 * @property string $title
 * @property string|null $description
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property bool $suspends_classes
 * @property string|null $suspends_from "HH:MM:SS"
 * @property string|null $suspends_until "HH:MM:SS"
 * @property string|null $memo_number
 * @property string|null $memo_path
 * @property string|null $memo_name
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read College|null $college
 */
// memo_path and memo_name are set only by uploading a memo.
#[Fillable(['college_id', 'title', 'description', 'starts_on', 'ends_on', 'suspends_classes', 'suspends_from', 'suspends_until', 'memo_number', 'created_by'])]
class CalendarEvent extends Model
{
    /** @use HasFactory<CalendarEventFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'suspends_classes' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<College, $this>
     */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
