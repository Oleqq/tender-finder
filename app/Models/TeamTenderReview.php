<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $tender_id
 * @property int|null $assignee_id
 * @property string $status
 * @property int $version
 * @property Carbon|null $due_at
 * @property Carbon|null $assigned_at
 * @property Carbon|null $sla_alerted_at
 * @property Team $team
 * @property Tender $tender
 */
final class TeamTenderReview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'due_at' => 'datetime', 'assigned_at' => 'datetime', 'sla_alerted_at' => 'datetime'];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Tender, $this> */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    /** @return HasMany<TeamTenderReviewComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TeamTenderReviewComment::class, 'review_id');
    }
}
