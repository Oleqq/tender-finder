<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $watch_checked_at
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string|null $region
 * @property string|null $budget_amount
 * @property Carbon|null $published_at
 * @property Carbon|null $deadline_at
 * @property string $canonical_url
 * @property Carbon $created_at
 * @property Carbon|null $external_updated_at
 * @property Carbon|null $details_fetched_at
 */
class Tender extends Model
{
    protected $fillable = [
        'source',
        'external_id',
        'source_feed_item_id',
        'reg_number',
        'canonical_url',
        'canonical_url_hash',
        'title',
        'description',
        'region',
        'budget_amount',
        'currency',
        'published_at',
        'deadline_at',
        'external_updated_at',
        'details_fetched_at',
        'watch_checked_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'budget_amount' => 'decimal:2',
            'published_at' => 'datetime',
            'deadline_at' => 'datetime',
            'external_updated_at' => 'datetime',
            'details_fetched_at' => 'datetime',
            'watch_checked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<SourceFeedItem, $this> */
    public function sourceFeedItem(): BelongsTo
    {
        return $this->belongsTo(SourceFeedItem::class);
    }

    /** @return HasMany<TenderQueryMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(TenderQueryMatch::class);
    }

    /** @return HasMany<TenderUserState, $this> */
    public function userStates(): HasMany
    {
        return $this->hasMany(TenderUserState::class);
    }

    /** @return HasMany<TenderParticipation, $this> */
    public function participations(): HasMany
    {
        return $this->hasMany(TenderParticipation::class);
    }

    /** @return HasMany<TeamTenderReview, $this> */
    public function teamReviews(): HasMany
    {
        return $this->hasMany(TeamTenderReview::class);
    }
}
