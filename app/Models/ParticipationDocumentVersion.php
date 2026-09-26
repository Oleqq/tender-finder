<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $document_id
 * @property int|null $uploaded_by
 * @property string $source_kind
 * @property string|null $source_url
 * @property string|null $disk
 * @property string|null $storage_path
 * @property string|null $original_name
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property Carbon $created_at
 * @property User|null $uploader
 */
class ParticipationDocumentVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<ParticipationDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ParticipationDocument::class, 'document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
