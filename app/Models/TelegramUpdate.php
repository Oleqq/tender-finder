<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed>|null $payment_payload
 * @property array{outcome: string, cancelled_count: int}|null $command_result
 */
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
        'command_result',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'payment_payload' => 'array',
            'payment_attempts' => 'integer',
            'command_result' => 'array',
        ];
    }
}
