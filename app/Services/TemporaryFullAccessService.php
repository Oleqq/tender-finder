<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Throwable;

final class TemporaryFullAccessService
{
    public function until(): ?Carbon
    {
        $value = config('tender.temporary_full_access_until');
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
            return null;
        }

        try {
            $until = Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $value, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $until;
    }

    public function isActive(): bool
    {
        return $this->until()?->isFuture() ?? false;
    }
}
