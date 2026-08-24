<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string|null $region
 * @property string|null $budget_amount
 * @property Carbon|null $deadline_at
 * @property string $canonical_url
 * @property Carbon $created_at
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
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'budget_amount' => 'decimal:2',
            'published_at' => 'datetime',
            'deadline_at' => 'datetime',
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
}
