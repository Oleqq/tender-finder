<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ParticipationApprovalRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['required_approvals' => 'integer', 'economics_version' => 'integer', 'resolved_at' => 'datetime', 'version' => 'integer'];
    }

    /** @return BelongsTo<TenderParticipation, $this> */
    public function participation(): BelongsTo
    {
        return $this->belongsTo(TenderParticipation::class);
    }

    /** @return HasMany<ParticipationApprovalVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(ParticipationApprovalVote::class, 'approval_request_id');
    }
}
