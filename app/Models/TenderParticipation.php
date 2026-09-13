<?php

namespace App\Models;

use App\Enums\ParticipationStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $tender_id
 * @property ParticipationStage $stage
 * @property string|null $loss_reason
 * @property int $version
 * @property Tender $tender
 */
class TenderParticipation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['stage' => ParticipationStage::class, 'version' => 'integer'];
    }

    /** @return BelongsTo<Tender, $this> */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    /** @return HasMany<TenderChecklistItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(TenderChecklistItem::class, 'participation_id');
    }
}
