<?php

namespace App\Telegram;

final readonly class VerifiedTelegramUser
{
    public function __construct(
        public string $id,
        public string $firstName,
        public ?string $lastName,
        public ?string $username,
        public ?string $languageCode,
        public int $authenticatedAt,
    ) {}

    public function displayName(): string
    {
        return trim($this->firstName.' '.($this->lastName ?? ''));
    }
}
