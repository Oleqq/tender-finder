<?php

namespace App\Services;

use App\Telegram\TelegramDeliveryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TelegramBotClient
{
    /** @param array<string, mixed> $parameters */
    private function call(string $method, array $parameters): mixed
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

        return $response->json('result');
    }

    /** @param array<string, mixed> $destination
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendMessage(string $chatId, string $text, array $destination = [], ?array $replyMarkup = null): void
    {
        $parameters = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];
        $keyboard = $replyMarkup ?? $this->miniAppKeyboard($destination);
        if ($keyboard !== null) {
            $parameters['reply_markup'] = $keyboard;
        }

        $this->call('sendMessage', $parameters);
    }

    /** @param array<string, mixed> $destination */
    public function sendNotification(string $chatId, string $text, array $destination = []): void
    {
        $this->sendMessage($chatId, $text, $destination);
    }

    public function answerPreCheckoutQuery(string $queryId, bool $ok, ?string $errorMessage = null): void
    {
        $parameters = ['pre_checkout_query_id' => $queryId, 'ok' => $ok];

        if (! $ok && $errorMessage !== null) {
            $parameters['error_message'] = $errorMessage;
        }

        $this->call('answerPreCheckoutQuery', $parameters);
    }

    public function syncCommands(): void
    {
        foreach (['', 'ru'] as $language) {
            $this->call('setMyCommands', ['commands' => TelegramCommands::menu(), 'language_code' => $language]);
        }
    }

    public function answerCallbackQuery(string $queryId, string $text): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $queryId, 'text' => $text, 'show_alert' => true]);
    }

    public function enableConsentCallbacks(): void
    {
        $secret = config('tender.telegram.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            throw new \RuntimeException('Секрет webhook не настроен.');
        }
        $info = $this->call('getWebhookInfo', []);
        if (! is_array($info) || ! is_string($info['url'] ?? null) || $info['url'] === '') {
            throw new \RuntimeException('Настроенный webhook бота не найден.');
        }
        $allowed = $info['allowed_updates'] ?? [];
        // Empty means all default types, including callback_query. Preserve it.
        if ($allowed === [] || in_array('callback_query', $allowed, true)) {
            return;
        }
        $this->call('setWebhook', [
            'url' => $info['url'],
            'secret_token' => $secret,
            'max_connections' => $info['max_connections'] ?? 40,
            'allowed_updates' => [...$allowed, 'callback_query'],
            'drop_pending_updates' => false,
        ]);
    }

    /** @param array<string, mixed> $destination
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}|null
     */
    private function miniAppKeyboard(array $destination = []): ?array
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
                'web_app' => ['url' => rtrim($url, '/').'/open?'.http_build_query(MiniAppDestination::normalize($destination))],
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
