<?php

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\Entitlement;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\PlanCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config()->set('tender.telegram.bot_token', 'telegram-test-token');
    config()->set('tender.telegram.owner_id', '700001');
    config()->set('tender.telegram.superadmin_ids', '700001,700002');
    Cache::flush();
    Http::preventStrayRequests();
});

it('notifies administrators about stalled payment updates once per incident', function () {
    Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    TelegramUpdate::query()->create([
        'telegram_update_id' => 912345,
        'type' => 'successful_payment',
        'status' => 'failed',
        'received_at' => now()->subMinutes(10),
        'payment_attempts' => 5,
    ]);

    $this->artisan('billing:check-stars-incidents')
        ->expectsOutputToContain('Stalled payment updates: 1; paid periods without access: 0.')
        ->assertExitCode(0);
    $this->artisan('billing:check-stars-incidents')->assertExitCode(0);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request['chat_id'] === '700001'
        && str_contains($request['text'], '912345'));
});

it('alerts for a paid period without access and ignores a valid paid period', function () {
    Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    $user = User::factory()->create(['telegram_id' => '700100']);
    $plan = app(PlanCatalog::class)->basic();
    $subscription = Subscription::query()->create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'source' => SubscriptionSource::TelegramStars,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(30),
    ]);
    $payment = Payment::query()->create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'subscription_id' => $subscription->id,
        'provider' => 'telegram_stars',
        'status' => PaymentStatus::Paid,
        'currency' => 'XTR',
        'amount' => 250,
        'invoice_payload' => 'tf-stars:paid',
        'telegram_payment_charge_id' => 'paid-charge',
        'paid_at' => now()->subMinutes(10),
        'period_ends_at' => $subscription->ends_at,
    ]);
    $entitlement = Entitlement::query()->create([
        'user_id' => $user->id,
        'subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'code' => 'active_queries',
        'status' => SubscriptionStatus::Active,
        'value' => 3,
        'starts_at' => now()->subDay(),
        'ends_at' => $subscription->ends_at,
    ]);

    $this->artisan('billing:check-stars-incidents')
        ->expectsOutputToContain('paid periods without access: 0.')
        ->assertExitCode(0);
    Http::assertNothingSent();

    $entitlement->delete();
    $this->artisan('billing:check-stars-incidents')
        ->expectsOutputToContain('paid periods without access: 1.')
        ->assertExitCode(0);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request['chat_id'] === '700002'
        && str_contains($request['text'], 'Платежи #'.$payment->id));
});

it('retries an alert after Telegram rejects its delivery', function () {
    Log::spy();
    Http::fake(['https://api.telegram.org/*/sendMessage' => Http::sequence()
        ->push(['ok' => false, 'description' => 'Unavailable'], 503)
        ->push(['ok' => true, 'result' => ['message_id' => 2]])]);
    config()->set('tender.telegram.superadmin_ids', null);
    TelegramUpdate::query()->create([
        'telegram_update_id' => 912346,
        'type' => 'successful_payment',
        'status' => 'received',
        'received_at' => now()->subMinutes(10),
    ]);

    $this->artisan('billing:check-stars-incidents')->assertExitCode(0);
    $this->artisan('billing:check-stars-incidents')->assertExitCode(0);

    Http::assertSentCount(2);
    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => $message === 'billing.alert.delivery_failed'
            && $context['failure_code'] === 'telegram_api_unavailable'
    )->once();
});

it('sends a test alert without creating or inspecting payments', function () {
    Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $this->artisan('billing:check-stars-incidents', ['--test-delivery' => true])
        ->expectsOutputToContain('Test alert delivered to every configured administrator.')
        ->assertExitCode(0);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Платёж не создавался.'));
    expect(Payment::query()->count())->toBe(0);
});

it('fails the delivery check when one administrator cannot receive messages', function () {
    Http::fake(['https://api.telegram.org/*/sendMessage' => Http::sequence()
        ->push(['ok' => true, 'result' => ['message_id' => 1]])
        ->push(['ok' => false, 'description' => 'Chat not found'], 400)]);

    $this->artisan('billing:check-stars-incidents', ['--test-delivery' => true])
        ->expectsOutputToContain('Test alert could not be delivered to every configured administrator.')
        ->assertExitCode(1);

    Http::assertSentCount(2);
});
