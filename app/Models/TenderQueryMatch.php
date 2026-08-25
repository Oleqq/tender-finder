<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property SearchQuery $searchQuery
 * @property Tender $tender
 * @property array<string, mixed> $match_reasons
 * @property Carbon $matched_at
 */
class TenderQueryMatch extends Model
{
    protected $fillable = ['tender_id', 'search_query_id', 'match_reasons', 'matched_at'];

    protected function casts(): array
    {
        return [
            'match_reasons' => 'array',
            'matched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tender, $this> */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    /** @return BelongsTo<SearchQuery, $this> */
    public function searchQuery(): BelongsTo
    {
        return $this->belongsTo(SearchQuery::class, 'search_query_id');
    }
}
