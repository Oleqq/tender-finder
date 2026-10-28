<?php

namespace App\Services;

use App\Models\SubscriptionOrder;
use Illuminate\Validation\ValidationException;

final class DemoPaymentProvider implements CheckoutProvider
{
    public function confirm(SubscriptionOrder $order, string $outcome): string
    {
        abort_unless(config('tender.payments.demo_enabled') && $order->is_demo && $order->provider === 'demo', 403);
        if (! in_array($outcome, ['succeeded', 'canceled', 'failed', 'pending'], true)) {
            throw ValidationException::withMessages(['outcome' => 'Неизвестный тестовый исход.']);
        }

        return $outcome;
    }
}
