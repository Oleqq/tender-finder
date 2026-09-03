<?php

use App\Enums\PaymentStatus;
use App\Models\Entitlement;
use App\Models\Payment;
use App\Models\User;
use App\Services\AccessService;
use App\Services\TelegramStarsPaymentService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('tender.telegram.bot_token', 'telegram-test-token');
    config()->set('tender.payments.telegram_stars.enabled', true);
    config()->set('tender.payments.telegram_stars.basic_price_xtr', 250);
    config()->set('tender.payments.telegram_stars.pro_price_xtr', 600);
    config()->set('tender.payments.telegram_stars.subscription_period_seconds', 2_592_000);
});

it('creates a Stars invoice and grants Basic access only after Telegram confirms payment', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $user = User::factory()->create(['telegram_id' => '700100']);

    $result = app(TelegramStarsPaymentService::class)->issueInvoiceForChat('700100', 'basic');

    expect($result)->toBeNull();
    $payment = Payment::query()->firstOrFail();
    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->currency)->toBe('XTR')
        ->and($payment->amount)->toBe(250);

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendInvoice')
        && $request['currency'] === 'XTR'
        && $request['prices'][0]['amount'] === 250);

    $checkout = [
        'id' => 'checkout-query',
        'from' => ['id' => 700100],
        'currency' => 'XTR',
        'total_amount' => 250,
        'invoice_payload' => $payment->invoice_payload,
    ];
    expect(app(TelegramStarsPaymentService::class)->validatePreCheckout($checkout))->toBeNull();

    app(TelegramStarsPaymentService::class)->settleSuccessfulPayment([
        ...$checkout,
        'telegram_payment_charge_id' => 'telegram-charge-1',
        'provider_payment_charge_id' => 'provider-charge-1',
    ]);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(Entitlement::query()->where('user_id', $user->id)->value('value'))->toBe(3)
        ->and(app(AccessService::class)->snapshotFor($user->fresh())->planCode)->toBe('basic');
});

it('rejects a pre-checkout query that does not belong to the invoice user', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    User::factory()->create(['telegram_id' => '700101']);
    app(TelegramStarsPaymentService::class)->issueInvoiceForChat('700101', 'pro');
    $payment = Payment::query()->firstOrFail();

    expect(app(TelegramStarsPaymentService::class)->validatePreCheckout([
        'id' => 'checkout-query-other-user',
        'from' => ['id' => 700102],
        'currency' => 'XTR',
        'total_amount' => 600,
        'invoice_payload' => $payment->invoice_payload,
    ]))->toBe('Этот счёт создан для другого пользователя.');
});
