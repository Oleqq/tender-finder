<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceRun extends Model
{
    protected $fillable = [
        'source_feed_id',
        'source',
        'status',
        'started_at',
        'finished_at',
        'items_seen',
        'items_created',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'items_seen' => 'integer',
            'items_created' => 'integer',
        ];
    }

    /** @return BelongsTo<SourceFeed, $this> */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(SourceFeed::class, 'source_feed_id');
    }
}
