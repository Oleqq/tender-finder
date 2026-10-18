<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramUpdate extends Model
{
    protected $fillable = [
        'telegram_update_id',
        'type',
        'status',
        'received_at',
        'processed_at',
        'failure_code',
        'payment_payload',
        'payment_attempts',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'payment_payload' => 'array',
            'payment_attempts' => 'integer',
        ];
    }
}
