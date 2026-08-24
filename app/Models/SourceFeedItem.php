<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SourceFeedItem extends Model
{
    protected $fillable = [
        'source_feed_id',
        'external_id',
        'canonical_url',
        'url_hash',
        'title',
        'summary',
        'published_at',
        'content_hash',
        'discovered_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'discovered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SourceFeed, $this> */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(SourceFeed::class, 'source_feed_id');
    }

    /** @return HasOne<Tender, $this> */
    public function tender(): HasOne
    {
        return $this->hasOne(Tender::class);
    }
}
