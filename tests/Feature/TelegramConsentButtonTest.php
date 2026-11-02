<?php

use App\Enums\ConsentDocument;
use App\Enums\UserRole;
use App\Models\ConsentEvent;
use App\Models\Entitlement;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\LegalDocumentRegistry;
use App\Services\TelegramConsentService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('tender.telegram.webhook_secret', 'consent-secret');
    config()->set('tender.telegram.bot_token', 'test-token');
    config()->set('tender.legal.documents_published', true);
    Http::fake(fn (Request $r) => Http::response(['ok' => true, 'result' => str_ends_with($r->url(), '/getWebhookInfo') ? [
        'url' => 'https://example.test/telegram/webhook', 'max_connections' => 20,
        'allowed_updates' => ['message', 'pre_checkout_query'],
    ] : true]));
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'consent-secret');
});

function botConsentCallback(int $id, string $actor = '123456', ?string $data = null): array
{
    return ['update_id' => $id, 'callback_query' => [
        'id' => 'callback-'.$id, 'from' => ['id' => (int) $actor, 'is_bot' => false],
        'message' => ['chat' => ['id' => (int) $actor, 'type' => 'private']],
        'data' => $data ?? app(TelegramConsentService::class)->callbackData($actor),
    ]];
}

function startBotPayload(int $id): array
{
    return ['update_id' => $id, 'message' => ['text' => '/start',
        'from' => ['id' => 123456], 'chat' => ['id' => 123456, 'type' => 'private']]];
}

it('shows separate read and explicit accept buttons before recording any consent', function () {
    $this->postJson('/telegram/webhook', startBotPayload(920001))->assertOk();
    expect(User::query()->count())->toBe(0);
    expect(ConsentEvent::query()->count())->toBe(0);
    $keyboard = app(TelegramConsentService::class)->keyboard('123456');
    expect(strlen($keyboard['inline_keyboard'][1][0]['callback_data']))->toBeLessThanOrEqual(64);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
        && $r['reply_markup'] === $keyboard
        && $r['reply_markup']['inline_keyboard'][1][0]['text'] === 'Я согласен на обработку ПДн'
        && str_ends_with($r['reply_markup']['inline_keyboard'][0][0]['url'], '/consent'));
});

it('records only the explicit personal data consent with actor version time and origin once', function () {
    $this->postJson('/telegram/webhook', botConsentCallback(920002))->assertOk();
    $user = User::query()->where('telegram_id', '123456')->firstOrFail();
    expect($user->role)->toBe(UserRole::Subscriber);
    $event = ConsentEvent::query()->sole();
    expect($event->document)->toBe(ConsentDocument::Consent)
        ->and($event->document_version)->toBe(app(ConsentService::class)->currentVersion(ConsentDocument::Consent))
        ->and($event->source)->toBe('telegram_button')->and($event->telegram_update_id)->toBe(920002)
        ->and($event->occurred_at)->not->toBeNull()->and($event->ip_hash)->toBeNull();
    expect(Entitlement::query()->count())->toBe(0);
    expect(app(ConsentService::class)->hasCurrentRequiredConsents($user))->toBeFalse();
    $this->postJson('/telegram/webhook', botConsentCallback(920002))->assertOk()->assertJsonPath('duplicate', true);
    $this->postJson('/telegram/webhook', botConsentCallback(920003))->assertOk();
    expect(ConsentEvent::query()->count())->toBe(1);
    $this->postJson('/telegram/webhook', startBotPayload(920004))->assertOk();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'Документы: /terms'));
});

it('rejects forged actor stale button group chat and unpublished documents without creating an account', function (string $case) {
    $payload = botConsentCallback(920010);
    if ($case === 'actor') {
        $payload['callback_query']['from']['id'] = 654321;
    }
    if ($case === 'signature') {
        $payload['callback_query']['data'] = app(TelegramConsentService::class)->callbackData('654321');
    }
    if ($case === 'old') {
        $payload['callback_query']['data'] = 'pdn:old-version:invalid';
    }
    if ($case === 'group') {
        $payload['callback_query']['message']['chat']['type'] = 'group';
    }
    if ($case === 'unpublished') {
        config()->set('tender.legal.documents_published', false);
    }
    $this->postJson('/telegram/webhook', $payload)->assertOk();
    expect(User::query()->count())->toBe(0)->and(ConsentEvent::query()->count())->toBe(0);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery'));
})->with(['actor', 'signature', 'old', 'group', 'unpublished']);

it('requires the webhook secret and does not replay an old acceptance after revocation', function () {
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong')->postJson('/telegram/webhook', botConsentCallback(920020))->assertForbidden();
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'consent-secret')->postJson('/telegram/webhook', botConsentCallback(920020))->assertOk();
    $user = User::query()->sole();
    app(ConsentService::class)->revoke($user, ConsentDocument::Consent, null);
    expect(app(TelegramConsentService::class)->needsPrompt('123456', '123456'))->toBeTrue();
    $this->postJson('/telegram/webhook', botConsentCallback(920020))->assertOk();
    expect(ConsentEvent::query()->count())->toBe(2);
    $this->postJson('/telegram/webhook', botConsentCallback(920021))->assertOk();
    expect(ConsentEvent::query()->count())->toBe(3);
});

it('publishes the specified date in every document and downloadable Word version', function () {
    $registry = app(LegalDocumentRegistry::class);
    foreach (LegalDocumentRegistry::TYPES as $type) {
        expect($registry->text($type))->toContain('Дата последнего обновления: 02.11.2026');
        $this->get('/'.$type)->assertOk()->assertSee('Дата последнего обновления: 02.11.2026');
        $zip = new ZipArchive;
        $zip->open($registry->originalPath($type));
        expect($zip->getFromName('word/document.xml'))->toContain('Дата последнего обновления: 02.11.2026');
        $zip->close();
    }
});

it('enables callback delivery while preserving all previous webhook update types', function () {
    $this->artisan('telegram:enable-consent-callbacks')->assertSuccessful();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setWebhook')
        && $r['allowed_updates'] === ['message', 'pre_checkout_query', 'callback_query']
        && $r['drop_pending_updates'] === false && $r['secret_token'] === 'consent-secret'
        && $r['url'] === 'https://example.test/telegram/webhook' && $r['max_connections'] === 20);
});
