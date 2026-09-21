<?php

use App\Services\TelegramBotClient;
use App\Telegram\TelegramDeliveryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends transactional notifications with a Mini App return button', function () {
    config()->set('tender.telegram.bot_token', 'test-token');
    config()->set('tender.telegram.mini_app_url', 'https://tender-finder.example.test');
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

    app(TelegramBotClient::class)->sendNotification('test-private-chat', 'Новое совпадение');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['chat_id'] === 'test-private-chat'
            && $data['text'] === 'Новое совпадение'
            && $data['reply_markup']['inline_keyboard'][0][0]['text'] === 'Открыть Tender Finder'
            && $data['reply_markup']['inline_keyboard'][0][0]['web_app']['url'] === 'https://tender-finder.example.test';
    });
});

it('classifies a blocked private chat without exposing Telegram response details', function () {
    config()->set('tender.telegram.bot_token', 'test-token');
    Http::fake(['https://api.telegram.org/*' => Http::response([
        'ok' => false,
        'description' => 'Forbidden: bot was blocked by the user',
    ], 403)]);

    try {
        app(TelegramBotClient::class)->sendNotification('test-private-chat', 'Проверка');
    } catch (TelegramDeliveryException $exception) {
        expect($exception->failureCode)->toBe('telegram_chat_blocked')
            ->and($exception->retryable)->toBeFalse();

        return;
    }

    $this->fail('Telegram delivery exception was not thrown.');
});
