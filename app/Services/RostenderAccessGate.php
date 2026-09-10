<?php

namespace App\Services;

use App\Tenders\RostenderAccessDisabledException;

class RostenderAccessGate
{
    public function allowsDataProcessing(): bool
    {
        return config('tender.rostender.enabled', false)
            && config('tender.rostender.public_distribution_approved', false);
    }

    public function assertDataProcessingAllowed(): void
    {
        if (! $this->allowsDataProcessing()) {
            throw new RostenderAccessDisabledException;
        }
    }
}
