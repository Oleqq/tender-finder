<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property PaymentStatus $status
 * @property int $amount
 * @property Carbon|null $paid_at
 * @property Carbon|null $refunded_at
 */
class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'provider',
        'status',
        'currency',
        'amount',
        'invoice_payload',
        'telegram_payment_charge_id',
        'provider_payment_charge_id',
        'paid_at',
        'refunded_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
