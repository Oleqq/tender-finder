<?php

namespace App\Services;

use App\Telegram\TelegramDeliveryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TelegramBotClient
{
    /** @param array<string, mixed> $parameters */
    private function call(string $method, array $parameters): void
    {
        $token = config('tender.telegram.bot_token');

        if (! is_string($token) || $token === '') {
            throw new TelegramDeliveryException('telegram_bot_not_configured', false);
        }

        try {
            $response = Http::acceptJson()
                ->timeout(max(1, (int) config('tender.telegram.bot_request_timeout_seconds', 5)))
                ->post("https://api.telegram.org/bot{$token}/{$method}", $parameters);
        } catch (ConnectionException) {
            throw new TelegramDeliveryException('telegram_network_unavailable', true);
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new TelegramDeliveryException(
                $this->failureCode($response->status(), (string) $response->json('description', '')),
                $this->isRetryableStatus($response->status()),
            );
        }
    }

    public function sendMessage(string $chatId, string $text): void
    {
        $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ]);
    }

    public function sendNotification(string $chatId, string $text): void
    {
        $parameters = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];

        $keyboard = $this->miniAppKeyboard();
        if ($keyboard !== null) {
            $parameters['reply_markup'] = $keyboard;
        }

        $this->call('sendMessage', $parameters);
    }

    public function sendStarsInvoice(
        string $chatId,
        string $title,
        string $description,
        string $payload,
        int $amount,
        int $subscriptionPeriodSeconds,
    ): void {
        $this->call('sendInvoice', [
            'chat_id' => $chatId,
            'title' => $title,
            'description' => $description,
            'payload' => $payload,
            'currency' => 'XTR',
            'prices' => [['label' => $title, 'amount' => $amount]],
            'subscription_period' => $subscriptionPeriodSeconds,
        ]);
    }

    public function answerPreCheckoutQuery(string $queryId, bool $ok, ?string $errorMessage = null): void
    {
        $parameters = ['pre_checkout_query_id' => $queryId, 'ok' => $ok];

        if (! $ok && $errorMessage !== null) {
            $parameters['error_message'] = $errorMessage;
        }

        $this->call('answerPreCheckoutQuery', $parameters);
    }

    /** @return array{inline_keyboard: array<int, array<int, array{text: string, web_app: array{url: string}}>>}|null */
    private function miniAppKeyboard(): ?array
    {
        $url = config('tender.telegram.mini_app_url');
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https') {
            return null;
        }

        return [
            'inline_keyboard' => [[[
                'text' => 'Открыть Tender Finder',
                'web_app' => ['url' => rtrim($url, '/')],
            ]]],
        ];
    }

    private function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function failureCode(int $status, string $description): string
    {
        $description = mb_strtolower($description);

        if ($status === 401) {
            return 'telegram_bot_auth_failed';
        }

        if (str_contains($description, 'bot was blocked')) {
            return 'telegram_chat_blocked';
        }

        if (str_contains($description, 'chat not found') || str_contains($description, 'user is deactivated')) {
            return 'telegram_chat_unavailable';
        }

        if ($status === 429) {
            return 'telegram_rate_limited';
        }

        if ($status >= 500) {
            return 'telegram_api_unavailable';
        }

        return 'telegram_api_rejected';
    }
}
