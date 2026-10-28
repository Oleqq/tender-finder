<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string $plan_code
 * @property int $duration_months
 * @property int $amount_minor
 * @property string $currency
 * @property array<string, mixed> $terms
 * @property string $provider
 * @property bool $is_demo
 * @property string $status
 * @property int|null $subscription_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon $expires_at
 */
final class SubscriptionOrder extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'terms' => 'array', 'is_demo' => 'boolean',
            'duration_months' => 'integer', 'amount_minor' => 'integer',
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'expires_at' => 'datetime',
        ];
    }
}
