<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotClient
{
    public function sendMessage(string $chatId, string $text): void
    {
        $token = config('tender.telegram.bot_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Telegram bot is not configured.');
        }

        $response = Http::acceptJson()
            ->timeout(max(1, (int) config('tender.telegram.bot_request_timeout_seconds', 5)))
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'disable_web_page_preview' => true,
            ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RequestException($response);
        }
    }
}
