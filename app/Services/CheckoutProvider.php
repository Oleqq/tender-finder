<?php

namespace App\Services;

use App\Models\SubscriptionOrder;

interface CheckoutProvider
{
    /** Return the server-verified status. A scenario is accepted only by the isolated demo provider. */
    public function confirm(SubscriptionOrder $order, string $scenario): string;
}
