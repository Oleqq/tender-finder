<?php

namespace App\Services;

final class RostenderQuotaReservation
{
    private bool $settled = false;

    public function __construct(
        private readonly RostenderQuotaGuard $quota,
        private readonly string $usageDate,
    ) {}

    public function settle(bool $successful): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        $this->quota->settle($this->usageDate, $successful);
    }
}
