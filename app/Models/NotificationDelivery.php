<?php

namespace App\Models;

use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property NotificationStatus $status
 * @property string $type
 * @property array<string, mixed>|null $payload
 * @property User $user
 */
class NotificationDelivery extends Model
{
    protected $fillable = [
        'user_id',
        'tender_id',
        'search_query_id',
        'type',
        'status',
        'idempotency_key',
        'payload',
        'scheduled_at',
        'sent_at',
        'failed_at',
        'failure_code',
    ];

    protected function casts(): array
    {
        return [
            'status' => NotificationStatus::class,
            'payload' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
