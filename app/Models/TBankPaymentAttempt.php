<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $order_id
 * @property string $terminal_key
 * @property string|null $payment_id
 * @property int $amount_minor
 * @property array<string, mixed> $request_payload
 * @property string|null $payment_url
 * @property string $state
 * @property string|null $bank_status
 * @property string|null $failure_code
 * @property Carbon|null $init_started_at
 * @property Carbon|null $next_check_at
 * @property string|null $check_token
 * @property int $check_count
 * @property Carbon|null $confirmed_at
 */
final class TBankPaymentAttempt extends Model
{
    protected $table = 'tbank_payment_attempts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['request_payload', 'payment_url', 'terminal_key', 'check_token'];

    protected function casts(): array
    {
        return [
            'request_payload' => 'encrypted:array', 'payment_url' => 'encrypted',
            'amount_minor' => 'integer', 'check_count' => 'integer',
            'init_started_at' => 'datetime', 'next_check_at' => 'datetime', 'confirmed_at' => 'datetime',
        ];
    }
}
