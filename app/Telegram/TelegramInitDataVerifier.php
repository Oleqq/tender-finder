<?php

namespace App\Telegram;

use Carbon\CarbonImmutable;
use JsonException;

class TelegramInitDataVerifier
{
    public function verify(string $initData): VerifiedTelegramUser
    {
        $token = config('tender.telegram.bot_token');

        if (! is_string($token) || $token === '') {
            throw new TelegramInitDataException('Telegram verification is not configured.');
        }

        $data = $this->parse($initData);
        $hash = $data['hash'] ?? null;

        if (! is_string($hash) || ! preg_match('/\A[0-9a-f]{64}\z/i', $hash)) {
            throw new TelegramInitDataException('Telegram signature is missing.');
        }

        unset($data['hash']);
        ksort($data, SORT_STRING);
        $dataCheckString = collect($data)
            ->map(fn (string $value, string $key): string => $key.'='.$value)
            ->implode("\n");
        $secretKey = hash_hmac('sha256', 'WebAppData', $token, true);
        $expectedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($expectedHash, $hash)) {
            throw new TelegramInitDataException('Telegram signature is invalid.');
        }

        $authDate = filter_var($data['auth_date'] ?? null, FILTER_VALIDATE_INT);

        if (! is_int($authDate) || $authDate <= 0) {
            throw new TelegramInitDataException('Telegram auth date is invalid.');
        }

        $now = CarbonImmutable::now()->timestamp;
        $maxAge = max(1, (int) config('tender.telegram.init_data_max_age_seconds', 300));

        if ($authDate > $now + 30 || $now - $authDate > $maxAge) {
            throw new TelegramInitDataException('Telegram auth data has expired.');
        }

        $user = $this->decodeUser($data['user'] ?? null);
        $id = $user['id'] ?? null;
        $firstName = $user['first_name'] ?? null;

        if (! (is_int($id) || (is_string($id) && ctype_digit($id))) || (int) $id <= 0 || ! is_string($firstName) || $firstName === '') {
            throw new TelegramInitDataException('Telegram user is invalid.');
        }

        return new VerifiedTelegramUser(
            id: (string) $id,
            firstName: $this->limit($firstName, 255),
            lastName: $this->optionalString($user['last_name'] ?? null, 255),
            username: $this->optionalString($user['username'] ?? null, 255),
            languageCode: $this->optionalString($user['language_code'] ?? null, 16),
            authenticatedAt: $authDate,
        );
    }

    /** @return array<string, string> */
    private function parse(string $initData): array
    {
        if ($initData === '' || strlen($initData) > 8192) {
            throw new TelegramInitDataException('Telegram payload is invalid.');
        }

        $data = [];

        foreach (explode('&', $initData) as $pair) {
            $parts = explode('=', $pair, 2);

            if (count($parts) !== 2) {
                throw new TelegramInitDataException('Telegram payload is malformed.');
            }

            [$rawKey, $rawValue] = $parts;
            $key = rawurldecode($rawKey);

            if ($key === '' || isset($data[$key])) {
                throw new TelegramInitDataException('Telegram payload is malformed.');
            }

            $data[$key] = rawurldecode($rawValue);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function decodeUser(mixed $rawUser): array
    {
        if (! is_string($rawUser)) {
            throw new TelegramInitDataException('Telegram user is missing.');
        }

        try {
            $user = json_decode($rawUser, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new TelegramInitDataException('Telegram user is malformed.');
        }

        if (! is_array($user)) {
            throw new TelegramInitDataException('Telegram user is malformed.');
        }

        return $user;
    }

    private function optionalString(mixed $value, int $limit): ?string
    {
        return is_string($value) && $value !== '' ? $this->limit($value, $limit) : null;
    }

    private function limit(string $value, int $limit): string
    {
        return mb_substr(trim($value), 0, $limit);
    }
}
