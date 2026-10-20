<?php

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Services\PlanCatalog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('reports unmatched charges and refunds without changing local payment state', function () {
    Log::spy();
    config()->set('tender.telegram.bot_token', 'telegram-test-token');
    config()->set('tender.telegram.owner_id', '700001');
    config()->set('tender.telegram.superadmin_ids', null);
    $user = User::factory()->create(['telegram_id' => '700100']);
    $plan = app(PlanCatalog::class)->basic();
    $payment = Payment::query()->create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'provider' => 'telegram_stars',
        'status' => PaymentStatus::Paid,
        'currency' => 'XTR',
        'amount' => 250,
        'invoice_payload' => 'tf-stars:known',
        'telegram_payment_charge_id' => 'known-charge',
        'paid_at' => now(),
    ]);
    Http::fake([
        'https://api.telegram.org/*/getStarTransactions' => Http::response([
            'ok' => true,
            'result' => ['transactions' => [
                [
                    'id' => 'known-charge', 'amount' => 250,
                    'source' => ['type' => 'user', 'transaction_type' => 'invoice_payment', 'invoice_payload' => 'tf-stars:known'],
                ],
                [
                    'id' => 'orphan-charge', 'amount' => 250,
                    'source' => ['type' => 'user', 'transaction_type' => 'invoice_payment', 'invoice_payload' => 'tf-stars:orphan'],
                ],
                ['id' => 'known-charge', 'amount' => 250, 'receiver' => ['type' => 'user']],
            ]],
        ]),
        'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $this->artisan('billing:reconcile-stars', ['--max' => 100])
        ->expectsOutputToContain('2 discrepancies.')
        ->assertExitCode(1);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(Payment::query()->count())->toBe(1);
    Log::shouldHaveReceived('warning')->with('billing.stars.reconciliation_finished', [
        'inspected' => 3,
        'invoices' => 2,
        'discrepancies' => 2,
        'max' => 100,
    ])->once();
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/getStarTransactions')
        && $request['offset'] === 0 && $request['limit'] === 100);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
        && $request['chat_id'] === '700001' && str_contains($request['text'], '2 расхождений')
        && ! str_contains($request['text'], 'orphan-charge'));
});

it('records API failures without exposing the bot token', function () {
    Log::spy();
    config()->set('tender.telegram.bot_token', 'telegram-test-token');
    config()->set('tender.telegram.owner_id', '700001');
    config()->set('tender.telegram.superadmin_ids', null);
    Http::fake([
        'https://api.telegram.org/*/getStarTransactions' => Http::response([
            'ok' => false,
            'description' => 'Temporary server error',
        ], 503),
        'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $this->artisan('billing:reconcile-stars', ['--max' => 100])
        ->expectsOutputToContain('telegram_api_unavailable')
        ->assertExitCode(1);

    Log::shouldHaveReceived('error')->with('billing.stars.reconciliation_unavailable', [
        'failure_code' => 'telegram_api_unavailable',
        'inspected' => 0,
        'max' => 100,
    ])->once();
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
        && $request['chat_id'] === '700001' && str_contains($request['text'], 'telegram_api_unavailable'));
});
