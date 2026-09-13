<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $participation_id
 * @property int|null $assignee_id
 * @property bool $reminder_enabled
 * @property string $title
 * @property Carbon|null $due_on
 * @property Carbon|null $completed_at
 * @property int $version
 * @property TenderParticipation $participation
 */
class TenderChecklistItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reminder_enabled' => 'boolean', 'due_on' => 'date', 'completed_at' => 'datetime', 'version' => 'integer'];
    }

    /** @return BelongsTo<TenderParticipation, $this> */
    public function participation(): BelongsTo
    {
        return $this->belongsTo(TenderParticipation::class, 'participation_id');
    }
}
