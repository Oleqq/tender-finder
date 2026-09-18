<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceFeedSearchQuery extends Model
{
    protected $fillable = ['source_feed_id', 'search_query_id'];

    /** @return BelongsTo<SourceFeed, $this> */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(SourceFeed::class, 'source_feed_id');
    }

    /** @return BelongsTo<SearchQuery, $this> */
    public function searchQuery(): BelongsTo
    {
        return $this->belongsTo(SearchQuery::class);
    }
}
