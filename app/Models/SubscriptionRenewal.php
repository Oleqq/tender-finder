<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property int $subscription_id
 * @property string $provider
 * @property string $plan_code
 * @property int $duration_months
 * @property int $amount_minor
 * @property string $currency
 * @property string $state
 * @property string $notice_status
 * @property int $attempts
 * @property Carbon $consented_at
 * @property Carbon $next_charge_at
 * @property Carbon $notice_due_at
 * @property Carbon|null $notice_sent_at
 * @property Carbon|null $charge_not_before
 * @property Carbon|null $retry_at
 * @property Carbon|null $cancelled_at
 * @property Subscription|null $subscription
 */
final class SubscriptionRenewal extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'duration_months' => 'integer', 'amount_minor' => 'integer', 'attempts' => 'integer',
            'consented_at' => 'datetime', 'next_charge_at' => 'datetime', 'notice_due_at' => 'datetime',
            'notice_sent_at' => 'datetime', 'charge_not_before' => 'datetime', 'retry_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
