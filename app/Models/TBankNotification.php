<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $terminal_key
 * @property string $payment_id
 * @property string $order_id
 * @property int $amount_minor
 * @property string $state
 * @property int $attempts
 * @property string|null $processing_token
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $processed_at
 */
final class TBankNotification extends Model
{
    protected $table = 'tbank_notifications';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['terminal_key', 'processing_token'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'attempts' => 'integer', 'next_retry_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
