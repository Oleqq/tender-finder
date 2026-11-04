<?php

use App\Models\SubscriptionOrder;
use App\Services\TBankApiException;
use App\Services\TBankCheckoutRequestFactory;
use App\Services\TBankClient;
use App\Services\TBankPaymentVerifier;
use App\Services\TBankToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('tender.payments.tbank', [
        'enabled' => false, 'notifications_enabled' => false,
        'terminal_key' => 'test-terminal', 'password' => 'test-secret',
        'public_base_url' => 'https://shop.example.test', 'request_timeout_seconds' => 15,
        // Test fixture only, not an approved fiscal regime for the actual IP.
        'receipt' => ['taxation' => 'usn_income', 'tax' => 'none',
            'payment_method' => 'full_prepayment', 'payment_object' => 'service'],
    ]);
    Http::preventStrayRequests();
});

function tbankOrder(): SubscriptionOrder
{
    return new SubscriptionOrder([
        'id' => '445c93f1-71e4-4ae6-b07a-6851783a42ea', 'provider' => 'tbank',
        'is_demo' => false, 'status' => 'pending', 'currency' => 'RUB',
        'plan_code' => 'basic', 'duration_months' => 1, 'amount_minor' => 34900,
        'expires_at' => now()->addMinutes(30),
        'terms' => ['code' => 'basic', 'duration_months' => 1, 'amount_minor' => 34900, 'auto_renew' => false],
    ]);
}

function tbankNotification(array $overrides = []): array
{
    $data = array_replace([
        'TerminalKey' => 'test-terminal', 'OrderId' => tbankOrder()->id,
        'PaymentId' => '123456789', 'Amount' => 34900, 'Status' => 'CONFIRMED',
        'Success' => true, 'ErrorCode' => '0',
    ], $overrides);
    $data['Token'] = app(TBankToken::class)->sign($data, 'test-secret');

    return $data;
}

it('matches the official token example and excludes nested receipt data', function () {
    $payload = [
        'TerminalKey' => 'MerchantTerminalKey', 'Amount' => 19200,
        'OrderId' => '21090', 'Description' => 'Подарочная карта на 1000 рублей',
        'DATA' => ['Email' => 'buyer@example.test'], 'Receipt' => ['Items' => []], 'Token' => 'ignored',
    ];
    expect(app(TBankToken::class)->sign($payload, 'usaf8fw8fsw21g'))
        ->toBe('0024a00af7c350a3a67ca168ce06502aa72772456662e38696d48b56ee9c97d9');
    expect(app(TBankToken::class)->sign(['Success' => true], 'secret'))->toBe(hash('sha256', 'secrettrue'));
    expect(app(TBankToken::class)->sign(['Success' => false], 'secret'))->toBe(hash('sha256', 'secretfalse'));
});

it('validates all signed scalar fields and rejects malformed tokens', function () {
    $data = tbankNotification();
    expect(app(TBankToken::class)->verify($data, 'test-secret'))->toBeTrue();
    $data['Amount'] = 1;
    expect(app(TBankToken::class)->verify($data, 'test-secret'))->toBeFalse()
        ->and(app(TBankToken::class)->verify(['Token' => []], 'test-secret'))->toBeFalse();
});

it('does not create a bank payment while new sales are disabled', function () {
    Http::fake();
    expect(fn () => app(TBankClient::class)->initialize([]))
        ->toThrow(TBankApiException::class, 'tbank_new_payments_disabled');
    Http::assertNothingSent();
});

it('builds a receipt from the server order and signs a single Init request', function () {
    config()->set('tender.payments.tbank.enabled', true);
    Http::fake(['https://securepay.tinkoff.ru/v2/Init' => Http::response([
        'Success' => true, 'ErrorCode' => '0', 'TerminalKey' => 'test-terminal',
        'PaymentId' => '123456789', 'PaymentURL' => 'https://securepay.tinkoff.ru/test',
    ])]);
    $body = app(TBankCheckoutRequestFactory::class)->make(tbankOrder(), 'buyer@example.test');
    $response = app(TBankClient::class)->initialize($body);
    expect($body['Amount'])->toBe(34900)
        ->and($body['Receipt']['Items'][0]['Amount'])->toBe(34900)
        ->and($body['NotificationURL'])->toBe('https://shop.example.test/payments/tbank/notifications')
        ->and($body['PayType'])->toBe('O')->and($body)->not->toHaveKeys(['Recurrent', 'CustomerKey'])
        ->and($response['PaymentId'])->toBe('123456789');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['TerminalKey'] === 'test-terminal'
        && $request['Token'] === app(TBankToken::class)->sign($body + ['TerminalKey' => 'test-terminal'], 'test-secret')
        && ! array_key_exists('Password', $request->data()));
});

it('rejects incomplete fiscal settings and unsafe return hosts', function (string $key, mixed $value, string $error) {
    config()->set('tender.payments.tbank.'.$key, $value);
    expect(fn () => app(TBankCheckoutRequestFactory::class)->make(tbankOrder(), 'buyer@example.test'))
        ->toThrow(TBankApiException::class, $error);
})->with([
    ['receipt.taxation', null, 'tbank_receipt_settings_missing'],
    ['receipt.tax', null, 'tbank_receipt_settings_missing'],
    ['receipt.payment_method', null, 'tbank_receipt_settings_missing'],
    ['receipt.payment_object', null, 'tbank_receipt_settings_missing'],
    ['public_base_url', 'http://shop.example.test', 'tbank_invalid_public_url'],
    ['public_base_url', 'https://user@shop.example.test', 'tbank_invalid_public_url'],
    ['public_base_url', 'https://shop.example.test/?redirect=evil', 'tbank_invalid_public_url'],
]);

it('does not turn demo or altered orders into real bank requests', function (array $changes) {
    $order = tbankOrder()->fill($changes);
    expect(fn () => app(TBankCheckoutRequestFactory::class)->make($order, 'buyer@example.test'))
        ->toThrow(TBankApiException::class, 'tbank_invalid_order');
})->with([
    [['is_demo' => true]], [['provider' => 'demo']], [['currency' => 'XTR']],
    [['amount_minor' => 1]], [['status' => 'succeeded']],
    [['expires_at' => '2000-01-01T00:00:00Z']],
    [['terms' => ['code' => 'basic', 'duration_months' => 1, 'amount_minor' => 34900, 'auto_renew' => true]]],
]);

it('verifies completed payment read-only while new sales are stopped', function (string $status, bool $confirmed) {
    Http::fake(['https://securepay.tinkoff.ru/v2/GetState' => Http::response([
        'Success' => true, 'ErrorCode' => '0', 'TerminalKey' => 'test-terminal',
        'PaymentId' => '123456789', 'OrderId' => tbankOrder()->id, 'Amount' => 34900, 'Status' => $status,
    ])]);
    expect(app(TBankPaymentVerifier::class)->isConfirmed('123456789', tbankOrder()->id, 34900))->toBe($confirmed);
    $this->assertDatabaseCount('subscriptions', 0);
    Http::assertSentCount(1);
})->with([['CONFIRMED', true], ['AUTHORIZED', false], ['NEW', false], ['REFUNDED', false], ['PARTIAL_REFUNDED', false]]);

it('rejects mismatched bank terminal payment order or amount', function (array $changes, string $error) {
    Http::fake(['https://securepay.tinkoff.ru/v2/GetState' => Http::response(array_replace([
        'Success' => true, 'ErrorCode' => '0', 'TerminalKey' => 'test-terminal',
        'PaymentId' => '123456789', 'OrderId' => tbankOrder()->id, 'Amount' => 34900, 'Status' => 'CONFIRMED',
    ], $changes))]);
    expect(fn () => app(TBankPaymentVerifier::class)->isConfirmed('123456789', tbankOrder()->id, 34900))
        ->toThrow(TBankApiException::class, $error);
})->with([
    [['TerminalKey' => 'other-shop'], 'tbank_terminal_mismatch'],
    [['PaymentId' => '987654321'], 'tbank_payment_mismatch'],
    [['OrderId' => 'another-order'], 'tbank_payment_mismatch'],
    [['Amount' => 1], 'tbank_payment_mismatch'],
]);

it('classifies failed requests without leaking provider messages or retrying Init', function () {
    config()->set('tender.payments.tbank.enabled', true);
    Http::fake(['*' => Http::response(['Message' => 'secret test-secret buyer@example.test'], 503)]);
    try {
        app(TBankClient::class)->initialize(app(TBankCheckoutRequestFactory::class)->make(tbankOrder(), 'buyer@example.test'));
        $this->fail('Expected bank error');
    } catch (TBankApiException $exception) {
        expect($exception->failureCode)->toBe('tbank_http_503')
            ->and($exception->retryable)->toBeFalse()
            ->and($exception->getMessage())->not->toContain('test-secret', 'buyer@example.test');
    }
    Http::assertSentCount(1);
});

it('uses CheckOrder for reconciliation without opening new payments', function () {
    Http::fake(['https://securepay.tinkoff.ru/v2/CheckOrder' => Http::response([
        'Success' => true, 'ErrorCode' => '0', 'TerminalKey' => 'test-terminal', 'Payments' => [],
    ])]);
    expect(app(TBankClient::class)->checkOrder(tbankOrder()->id)['Payments'])->toBe([]);
    Http::assertSentCount(1);
});

it('rejects invalid payment identifiers before any network request', function () {
    Http::fake();
    expect(fn () => app(TBankClient::class)->getState('../Init'))->toThrow(TBankApiException::class, 'tbank_invalid_payment_id');
    Http::assertNothingSent();
});

it('keeps the bank notification endpoint closed by default', function () {
    $this->postJson('/payments/tbank/notifications', tbankNotification())->assertStatus(503);
    $this->assertDatabaseCount('tbank_notifications', 0);
});

it('durably acknowledges duplicate signed notifications without granting access', function () {
    config()->set('tender.payments.tbank.notifications_enabled', true);
    $data = tbankNotification(['Pan' => '430000******0777', 'ExpDate' => '1230', 'RebillId' => 'private-rebill']);
    $this->postJson('/payments/tbank/notifications', $data)->assertOk()->assertContent('OK');
    // Optional unsigned nested data and JSON key order must not affect the operational event key.
    $data['DATA'] = ['Email' => 'private@example.test'];
    $this->postJson('/payments/tbank/notifications', array_reverse($data, true))->assertOk()->assertContent('OK');
    $this->assertDatabaseCount('tbank_notifications', 1);
    $row = (array) DB::table('tbank_notifications')->first();
    expect($row['state'])->toBe('received')
        ->and(json_encode($row))->not->toContain('private-rebill', '430000', 'private@example.test', $data['Token']);
    $this->assertDatabaseCount('subscriptions', 0);
    $this->assertDatabaseCount('subscription_orders', 0);
});

it('keeps different bank states for later reconciliation without assuming order of delivery', function () {
    config()->set('tender.payments.tbank.notifications_enabled', true);
    foreach (['CONFIRMED', 'AUTHORIZED', 'REFUNDED'] as $status) {
        $this->postJson('/payments/tbank/notifications', tbankNotification(['Status' => $status]))->assertOk();
    }
    $this->assertDatabaseCount('tbank_notifications', 3);
    $this->assertDatabaseCount('subscriptions', 0);
});

it('rejects invalid signatures and other terminals without saving payloads', function (string $field, mixed $value) {
    config()->set('tender.payments.tbank.notifications_enabled', true);
    $data = tbankNotification();
    $data[$field] = $value;
    $this->postJson('/payments/tbank/notifications', $data)->assertForbidden();
    $this->assertDatabaseCount('tbank_notifications', 0);
})->with([['Token', str_repeat('a', 64)], ['Token', []], ['Amount', 1], ['TerminalKey', 'another-terminal']]);

it('rejects malformed but correctly signed notifications', function (array $changes) {
    config()->set('tender.payments.tbank.notifications_enabled', true);
    $this->postJson('/payments/tbank/notifications', tbankNotification($changes))->assertStatus(400);
    $this->assertDatabaseCount('tbank_notifications', 0);
})->with([
    [['PaymentId' => '../Init']], [['OrderId' => str_repeat('a', 51)]], [['Amount' => -1]],
    [['Success' => 'true']], [['Status' => '']], [['ErrorCode' => []]],
]);

it('rejects malformed or unsuccessful provider responses without exposing their contents', function (mixed $body, string $error) {
    Http::fake(['*' => Http::response($body)]);
    expect(fn () => app(TBankClient::class)->getState('123456789'))->toThrow(TBankApiException::class, $error);
    Http::assertSentCount(1);
})->with([
    ['not-json', 'tbank_invalid_response'],
    [[], 'tbank_invalid_response'],
    [['Success' => 'true'], 'tbank_invalid_response'],
    [['Success' => false, 'ErrorCode' => '999', 'Details' => 'secret'], 'tbank_operation_rejected'],
    [['Success' => true, 'ErrorCode' => '999'], 'tbank_operation_rejected'],
]);

it('fails closed with missing credentials before sending anything', function () {
    config()->set('tender.payments.tbank.password', '');
    Http::fake();
    expect(fn () => app(TBankClient::class)->getState('123456789'))
        ->toThrow(TBankApiException::class, 'tbank_credentials_missing');
    Http::assertNothingSent();
});

it('classifies a connection failure without retrying or leaking the request', function () {
    Http::fake(['*' => Http::failedConnection()]);
    expect(fn () => app(TBankClient::class)->getState('123456789'))
        ->toThrow(TBankApiException::class, 'tbank_network_unavailable');
});

it('rejects malformed JSON and oversized requests without saving a notification', function () {
    config()->set('tender.payments.tbank.notifications_enabled', true);
    $this->call('POST', '/payments/tbank/notifications', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{')->assertStatus(400);
    $this->postJson('/payments/tbank/notifications', ['padding' => str_repeat('x', 65537)])->assertStatus(400);
    $this->assertDatabaseCount('tbank_notifications', 0);
});

it('fails a notification instead of acknowledging when the inbox cannot persist it', function () {
    config()->set('tender.payments.tbank.notifications_enabled', true);
    Schema::drop('tbank_notifications');
    $this->postJson('/payments/tbank/notifications', tbankNotification())->assertStatus(500);
});
