<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceFeed extends Model
{
    protected $fillable = [
        'canonical_url',
        'url_hash',
        'status',
        'poll_interval_seconds',
        'next_poll_at',
        'initialized_at',
        'last_attempt_at',
        'last_success_at',
        'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'next_poll_at' => 'datetime',
            'initialized_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
            'poll_interval_seconds' => 'integer',
        ];
    }

    /** @return HasMany<SourceFeedItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SourceFeedItem::class);
    }
}
