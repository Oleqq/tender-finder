<?php

use App\Services\TelegramCommands;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('answers public commands through the real webhook with no account or active subscription', function (string $command) {
    config()->set('tender.telegram.webhook_secret', 'legal-secret');
    config()->set('tender.telegram.bot_token', 'legal-test-token');
    config()->set('tender.telegram.mini_app_url', 'https://example.test');
    config()->set('tender.legal.documents_published', true);
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
    $payload = ['update_id' => 912345, 'message' => ['chat' => ['id' => 123456], 'text' => $command.'@RUTenderFinder_bot']];
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'legal-secret')->postJson('/telegram/webhook', $payload)->assertOk();
    $expected = app(TelegramCommands::class)->messages($command);
    Http::assertSentCount(count($expected));
    foreach ($expected as $index => $text) {
        expect(intdiv(strlen(mb_convert_encoding(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-16LE', 'UTF-8')), 2))->toBeLessThanOrEqual(4096);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage') && $request['text'] === $text && $request['parse_mode'] === 'HTML'
            && isset($request['reply_markup']) === ($index === array_key_last($expected)));
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
