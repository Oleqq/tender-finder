<?php

namespace App\Services;

use RuntimeException;

final class TBankApiException extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($failureCode);
    }
}
