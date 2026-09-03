<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotClient
{
    /** @param array<string, mixed> $parameters */
    private function call(string $method, array $parameters): void
    {
        $token = config('tender.telegram.bot_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Telegram bot is not configured.');
        }

        $response = Http::acceptJson()
            ->timeout(max(1, (int) config('tender.telegram.bot_request_timeout_seconds', 5)))
            ->post("https://api.telegram.org/bot{$token}/{$method}", $parameters);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RequestException($response);
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
}
