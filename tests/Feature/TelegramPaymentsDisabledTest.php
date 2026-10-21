<?php

use App\Models\Payment;
use App\Models\TelegramUpdate;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('has no Telegram payment route even if an old environment flag is set', function () {
    config()->set('tender.payments.telegram_stars.enabled', true);
    $this->actingAs(User::factory()->create());

    $this->postJson('/billing/invoice', ['plan' => 'basic'])->assertNotFound();
    $this->getJson('/billing/status')->assertNotFound();
    $this->post('/billing/subscriptions/1/cancel')->assertNotFound();
    $this->post('/billing/subscriptions/1/resume')->assertNotFound();
    $this->post('/billing/admin/payments/1/refund')->assertNotFound();
    $this->get('/plans')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Plans')->missing('billing'));

    expect(Payment::query()->count())->toBe(0);
});

it('rejects Telegram pre-checkout and never creates a payment', function () {
    config()->set('tender.telegram.webhook_secret', 'test-secret');
    config()->set('tender.telegram.bot_token', 'test-bot-token');
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

    $oldCheckout = [
        'update_id' => 800001,
        'pre_checkout_query' => [
            'id' => 'checkout-1',
            'from' => ['id' => 12345],
            'currency' => 'XTR',
            'total_amount' => 250,
            'invoice_payload' => 'tf-stars:old-invoice',
        ],
    ];

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
        ->postJson('/telegram/webhook', $oldCheckout)->assertOk();
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
        ->postJson('/telegram/webhook', $oldCheckout)->assertOk();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerPreCheckoutQuery')
        && $request['pre_checkout_query_id'] === 'checkout-1'
        && $request['ok'] === false);
    expect(Payment::query()->count())->toBe(0);
});

it('answers the old subscribe command without sending an invoice', function () {
    config()->set('tender.telegram.webhook_secret', 'test-secret');
    config()->set('tender.telegram.bot_token', 'test-bot-token');
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
        ->postJson('/telegram/webhook', [
            'update_id' => 800003,
            'message' => ['chat' => ['id' => 12345], 'text' => '/subscribe basic'],
        ])->assertOk();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
        && str_contains($request['text'], 'отключена'));
    expect(Payment::query()->count())->toBe(0);
});

it('records unexpected legacy payment updates for review without granting access', function () {
    config()->set('tender.telegram.webhook_secret', 'test-secret');
    Queue::fake();

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
        ->postJson('/telegram/webhook', [
            'update_id' => 800002,
            'message' => [
                'chat' => ['id' => 12345],
                'successful_payment' => [
                    'currency' => 'XTR',
                    'total_amount' => 250,
                    'invoice_payload' => 'tf-stars:old-invoice',
                    'telegram_payment_charge_id' => 'old-charge',
                ],
            ],
        ])->assertOk();

    expect(TelegramUpdate::query()->where('telegram_update_id', 800002)->value('status'))->toBe('ignored')
        ->and(Payment::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
