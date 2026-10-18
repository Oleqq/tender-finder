<?php

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Services\PlanCatalog;
use Illuminate\Support\Facades\Http;

it('reports unmatched charges and refunds without changing local payment state', function () {
    config()->set('tender.telegram.bot_token', 'telegram-test-token');
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
    Http::fake(['https://api.telegram.org/*/getStarTransactions' => Http::response([
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
    ])]);

    $this->artisan('billing:reconcile-stars', ['--max' => 100])
        ->expectsOutputToContain('2 discrepancies.')
        ->assertExitCode(1);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(Payment::query()->count())->toBe(1);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/getStarTransactions')
        && $request['offset'] === 0 && $request['limit'] === 100);
});
