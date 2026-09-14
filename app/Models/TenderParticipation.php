<?php

namespace App\Models;

use App\Enums\ParticipationStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $team_id
 * @property int|null $assignee_id
 * @property int $tender_id
 * @property ParticipationStage $stage
 * @property string|null $loss_reason
 * @property int $version
 * @property int $economics_version
 * @property Tender $tender
 */
class TenderParticipation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stage' => ParticipationStage::class,
            'version' => 'integer',
            'economics_version' => 'integer',
            'planned_revenue' => 'decimal:2',
            'planned_cost' => 'decimal:2',
            'security_cost' => 'decimal:2',
            'commission_cost' => 'decimal:2',
            'other_cost' => 'decimal:2',
            'actual_revenue' => 'decimal:2',
            'actual_cost' => 'decimal:2',
        ];
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

    /** @return HasMany<ParticipationApprovalRequest, $this> */
    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ParticipationApprovalRequest::class, 'participation_id');
    }
}
