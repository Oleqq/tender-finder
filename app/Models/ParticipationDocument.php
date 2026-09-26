<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $participation_id
 * @property int|null $assignee_id
 * @property int|null $checklist_item_id
 * @property string $title
 * @property string $type
 * @property string $status
 * @property int $version
 * @property Carbon|null $archived_at
 * @property Collection<int, ParticipationDocumentVersion> $versions
 */
class ParticipationDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'datetime'];
    }

    /** @return BelongsTo<TenderParticipation, $this> */
    public function participation(): BelongsTo
    {
        return $this->belongsTo(TenderParticipation::class, 'participation_id');
    }

    /** @return HasMany<ParticipationDocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ParticipationDocumentVersion::class, 'document_id');
    }
}
