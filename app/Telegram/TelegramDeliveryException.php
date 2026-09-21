<?php

namespace App\Telegram;

use RuntimeException;

final class TelegramDeliveryException extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly bool $retryable,
    ) {
        parent::__construct('Telegram notification delivery failed.');
    }
}
