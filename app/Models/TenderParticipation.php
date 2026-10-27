<?php

namespace App\Models;

use App\Enums\ParticipationStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    use SoftDeletes;

    protected $guarded = ['id'];

    /** @param Builder<TenderParticipation> $query
     * @return Builder<TenderParticipation>
     */
    public function scopeExpiredBeforeSubmission(Builder $query): Builder
    {
        return $query->whereIn('stage', ['studying', 'preparing'])
            ->whereHas('tender', fn (Builder $tender) => $tender->where('deadline_at', '<=', now()));
    }

    /** @param Builder<TenderParticipation> $query
     * @return Builder<TenderParticipation>
     */
    public function scopeCurrentList(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNotIn('stage', ['studying', 'preparing'])
            ->orWhereHas('tender', fn (Builder $tender) => $tender->whereNull('deadline_at')->orWhere('deadline_at', '>', now())));
    }

    public function expiredBeforeSubmission(): bool
    {
        return in_array($this->stage, [ParticipationStage::Studying, ParticipationStage::Preparing], true)
            && $this->tender->deadline_at !== null && $this->tender->deadline_at->lte(now());
    }

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

    /** @return HasMany<ParticipationDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ParticipationDocument::class, 'participation_id');
    }
}
