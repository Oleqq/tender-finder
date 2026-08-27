<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $query
 * @property string $source
 * @property list<int> $tender_ids
 * @property int $items_seen
 * @property int $items_matched
 * @property int $items_created
 * @property int $pages_requested
 * @property int $pages_loaded
 * @property bool $partially_loaded
 */
class LocalMvpSearchSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'query',
        'source',
        'tender_ids',
        'items_seen',
        'items_matched',
        'items_created',
        'pages_requested',
        'pages_loaded',
        'partially_loaded',
    ];

    protected function casts(): array
    {
        return [
            'tender_ids' => 'array',
            'partially_loaded' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
