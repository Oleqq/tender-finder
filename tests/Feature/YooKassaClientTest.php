<?php

use App\Services\YooKassaApiException;
use App\Services\YooKassaClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('does not create payments while the sales switch is off', function () {
    config()->set('tender.payments.yookassa.enabled', false);
    config()->set('tender.payments.yookassa.shop_id', 'test-shop');
    config()->set('tender.payments.yookassa.secret_key', 'test-secret');
    Http::fake();

    expect(fn () => app(YooKassaClient::class)->createPayment([], 'order-1'))
        ->toThrow(YooKassaApiException::class, 'yookassa_new_payments_disabled');
    Http::assertNothingSent();
});

it('sends an idempotent payment request with server-side shop credentials', function () {
    config()->set('tender.payments.yookassa.enabled', true);
    config()->set('tender.payments.yookassa.shop_id', 'test-shop');
    config()->set('tender.payments.yookassa.secret_key', 'test-secret');
    Http::fake(['https://api.yookassa.ru/v3/payments' => Http::response([
        'id' => '25bfb3dd-000f-5000-8000-11e71bbcf10d',
        'status' => 'pending',
    ])]);

    $payment = [
        'amount' => ['value' => '100.00', 'currency' => 'RUB'],
        'capture' => true,
        'confirmation' => ['type' => 'redirect', 'return_url' => 'https://shop.example.test/return'],
        'metadata' => ['order_id' => 'internal-123'],
    ];
    $response = app(YooKassaClient::class)->createPayment($payment, 'internal-123');

    expect($response['status'])->toBe('pending');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.yookassa.ru/v3/payments'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-shop:test-secret'))
        && $request->hasHeader('Idempotence-Key', 'internal-123')
        && $request->data() === $payment);
});

it('allows verified payment lookups after new sales are disabled', function () {
    config()->set('tender.payments.yookassa.enabled', false);
    config()->set('tender.payments.yookassa.shop_id', 'test-shop');
    config()->set('tender.payments.yookassa.secret_key', 'test-secret');
    Http::fake(['https://api.yookassa.ru/v3/payments/*' => Http::response([
        'id' => '25bfb3dd-000f-5000-8000-11e71bbcf10d',
        'status' => 'succeeded',
    ])]);

    $response = app(YooKassaClient::class)->getPayment('25bfb3dd-000f-5000-8000-11e71bbcf10d');

    expect($response['status'])->toBe('succeeded');
    Http::assertSentCount(1);
});

it('rejects malformed IDs before calling the provider', function () {
    Http::fake();

    expect(fn () => app(YooKassaClient::class)->getPayment('../webhooks'))
        ->toThrow(YooKassaApiException::class, 'yookassa_invalid_payment_id');
    Http::assertNothingSent();
});

it('classifies provider failures without exposing a response body or key', function () {
    config()->set('tender.payments.yookassa.shop_id', 'test-shop');
    config()->set('tender.payments.yookassa.secret_key', 'test-secret');
    Http::fake(['https://api.yookassa.ru/v3/payments/*' => Http::response([
        'description' => 'Sensitive provider details',
    ], 503)]);

    try {
        app(YooKassaClient::class)->getPayment('25bfb3dd-000f-5000-8000-11e71bbcf10d');
    } catch (YooKassaApiException $exception) {
        expect($exception->failureCode)->toBe('yookassa_http_503')
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->getMessage())->not->toContain('Sensitive provider details', 'test-secret');

        return;
    }

    $this->fail('YooKassa API exception was not thrown.');
});
