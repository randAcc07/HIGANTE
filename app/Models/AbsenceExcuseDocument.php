<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AbsenceExcuseDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file supporting an excuse: certificate, letter, photo.
 *
 * @property int $id
 * @property int $absence_excuse_id
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $uploaded_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AbsenceExcuse $absenceExcuse
 */
#[Fillable(['absence_excuse_id', 'path', 'original_name', 'mime_type', 'size_bytes', 'uploaded_by'])]
class AbsenceExcuseDocument extends Model
{
    /** @use HasFactory<AbsenceExcuseDocumentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<AbsenceExcuse, $this>
     */
    public function absenceExcuse(): BelongsTo
    {
        return $this->belongsTo(AbsenceExcuse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
