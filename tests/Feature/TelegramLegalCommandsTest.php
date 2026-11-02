<?php

use App\Services\TelegramCommands;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('answers public commands through the real webhook with no account or active subscription', function (string $command) {
    config()->set('tender.telegram.webhook_secret', 'legal-secret');
    config()->set('tender.telegram.bot_token', 'legal-test-token');
    config()->set('tender.legal.documents_published', true);
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
    $payload = ['update_id' => 912345, 'message' => ['chat' => ['id' => 123456], 'text' => $command.'@RUTenderFinder_bot']];
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'legal-secret')->postJson('/telegram/webhook', $payload)->assertOk();
    $expected = app(TelegramCommands::class)->messages($command);
    Http::assertSentCount(count($expected));
    foreach ($expected as $text) {
        expect(mb_strlen($text, 'UTF-8'))->toBeLessThanOrEqual(1800);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage') && $request['text'] === $text);
    }
    $this->postJson('/telegram/webhook', $payload)->assertOk()->assertJsonPath('duplicate', true);
    Http::assertSentCount(count($expected));
})->with(['/terms', '/privacy', '/consent', '/offer', '/info', '/paysupport', '/support']);

it('publishes the requested command menu for default and Russian language', function () {
    config()->set('tender.telegram.bot_token', 'legal-test-token');
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
    $this->artisan('telegram:sync-commands')->assertSuccessful();
    Http::assertSentCount(2);
    foreach (['', 'ru'] as $language) {
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/setMyCommands')
            && $request['language_code'] === $language && $request['commands'] === TelegramCommands::menu());
    }
});
