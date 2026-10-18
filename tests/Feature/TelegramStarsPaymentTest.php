<?php

use App\Enums\ConsentDocument;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Jobs\ProcessTelegramPaymentUpdate;
use App\Models\Entitlement;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\AccessService;
use App\Services\ConsentService;
use App\Services\TelegramStarsPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('tender.telegram.bot_token', 'telegram-test-token');
    config()->set('tender.telegram.webhook_secret', 'webhook-test-secret');
    config()->set('tender.payments.telegram_stars.enabled', true);
    config()->set('tender.payments.telegram_stars.basic_price_xtr', 250);
    config()->set('tender.payments.telegram_stars.pro_price_xtr', 600);
    config()->set('tender.payments.telegram_stars.subscription_period_seconds', 2_592_000);
    config()->set('tender.legal.documents_published', true);
    config()->set('tender.legal.offer_url', 'https://example.test/offer');
    config()->set('tender.legal.offer_version', '2026-10-19');
    config()->set('tender.legal.privacy_url', 'https://example.test/privacy');
    config()->set('tender.legal.privacy_version', '2026-10-19');
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => 'https://t.me/$test-invoice'])]);
});

function starsUser(string $telegramId = '700100'): User
{
    $user = User::factory()->create(['telegram_id' => $telegramId]);
    app(ConsentService::class)->acceptCurrent($user, [ConsentDocument::Offer, ConsentDocument::Privacy], null);

    return $user;
}

/** @return array<string, mixed> */
function confirmedStarsPayment(Payment $invoice, string $chargeId = 'charge-1', ?int $end = null): array
{
    return [
        'currency' => 'XTR',
        'total_amount' => $invoice->amount,
        'invoice_payload' => $invoice->invoice_payload,
        'telegram_payment_charge_id' => $chargeId,
        'provider_payment_charge_id' => 'provider-'.$chargeId,
        'is_recurring' => true,
        'is_first_recurring' => $chargeId === 'charge-1',
        'subscription_expiration_date' => $end ?? now()->addDays(30)->timestamp,
    ];
}

it('creates a recurring invoice link and grants access only after a confirmed charge', function () {
    $user = starsUser();
    $this->actingAs($user)->postJson('/billing/invoice', ['plan' => 'basic'])
        ->assertOk()->assertJsonPath('invoice_url', 'https://t.me/$test-invoice');

    $invoice = Payment::query()->sole();
    expect($invoice->status)->toBe(PaymentStatus::Pending)
        ->and($invoice->amount)->toBe(250)
        ->and(Entitlement::query()->count())->toBe(0);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/createInvoiceLink')
        && $request['currency'] === 'XTR'
        && $request['subscription_period'] === 2_592_000
        && $request['prices'][0]['amount'] === 250);

    expect(app(TelegramStarsPaymentService::class)->validatePreCheckout([
        'from' => ['id' => 700100],
        'currency' => 'XTR',
        'total_amount' => 250,
        'invoice_payload' => $invoice->invoice_payload,
    ]))->toBeNull();

    $payment = confirmedStarsPayment($invoice);
    app(TelegramStarsPaymentService::class)->settleSuccessfulPayment($payment);
    app(TelegramStarsPaymentService::class)->settleSuccessfulPayment($payment);

    expect($invoice->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(Payment::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(Entitlement::query()->count())->toBe(1)
        ->and(app(AccessService::class)->snapshotFor($user)->planCode)->toBe('basic');
});

it('rejects another buyer and refuses to issue invoices without current legal consent', function () {
    $user = User::factory()->create(['telegram_id' => '700101']);
    $this->actingAs($user)->postJson('/billing/invoice', ['plan' => 'pro'])->assertUnprocessable();
    app(ConsentService::class)->acceptCurrent($user, [ConsentDocument::Offer, ConsentDocument::Privacy], null);
    $this->actingAs($user)->postJson('/billing/invoice', ['plan' => 'pro'])->assertOk();
    $invoice = Payment::query()->sole();

    expect(app(TelegramStarsPaymentService::class)->validatePreCheckout([
        'from' => ['id' => 700102],
        'currency' => 'XTR',
        'total_amount' => 600,
        'invoice_payload' => $invoice->invoice_payload,
    ]))->toBe('Этот счёт создан для другого пользователя.');
});

it('keeps checkout closed when a price is missing and rejects an expired invoice', function () {
    $user = starsUser();
    config()->set('tender.payments.telegram_stars.pro_price_xtr', 0);
    expect(app(TelegramStarsPaymentService::class)->configuration()['enabled'])->toBeFalse();
    $this->actingAs($user)->postJson('/billing/invoice', ['plan' => 'basic'])->assertUnprocessable();
    expect(Payment::query()->count())->toBe(0);

    config()->set('tender.payments.telegram_stars.pro_price_xtr', 600);
    app(TelegramStarsPaymentService::class)->createSubscriptionInvoice($user, 'basic');
    $invoice = Payment::query()->sole();
    $invoice->forceFill(['invoice_expires_at' => now()->subSecond()])->save();

    expect(app(TelegramStarsPaymentService::class)->validatePreCheckout([
        'from' => ['id' => 700100],
        'currency' => 'XTR',
        'total_amount' => 250,
        'invoice_payload' => $invoice->invoice_payload,
    ]))->toBe('Счёт не найден или устарел. Откройте приложение и запросите новый.');
});

it('does not charge an owner who already has permanent access', function () {
    $owner = starsUser();
    $owner->forceFill(['role' => UserRole::SuperAdmin])->save();

    $this->actingAs($owner)->postJson('/billing/invoice', ['plan' => 'pro'])
        ->assertUnprocessable();
    expect(Payment::query()->count())->toBe(0);
});

it('extends one subscription on renewal and handles cancellation and refund', function () {
    $user = starsUser();
    app(TelegramStarsPaymentService::class)->createSubscriptionInvoice($user, 'basic');
    $invoice = Payment::query()->sole();
    $firstEnd = now()->addDays(30)->timestamp;
    app(TelegramStarsPaymentService::class)->settleSuccessfulPayment(confirmedStarsPayment($invoice, 'charge-1', $firstEnd));
    $subscription = Subscription::query()->sole();

    $this->actingAs($user)->post("/billing/subscriptions/{$subscription->id}/cancel")->assertRedirect();
    expect($subscription->fresh()->auto_renew)->toBeFalse();
    $this->actingAs($user)->post("/billing/subscriptions/{$subscription->id}/resume")->assertRedirect();
    expect($subscription->fresh()->auto_renew)->toBeTrue();
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/editUserStarSubscription'));

    $renewalEnd = now()->addDays(60)->timestamp;
    app(TelegramStarsPaymentService::class)->settleSuccessfulPayment(confirmedStarsPayment($invoice, 'charge-2', $renewalEnd));
    expect(Subscription::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(2)
        ->and($subscription->fresh()->ends_at->timestamp)->toBe($renewalEnd);

    app(TelegramStarsPaymentService::class)->recordRefund([
        'currency' => 'XTR', 'total_amount' => 250, 'telegram_payment_charge_id' => 'charge-2',
    ]);
    expect(Payment::query()->where('telegram_payment_charge_id', 'charge-2')->firstOrFail()->status)->toBe(PaymentStatus::Refunded)
        ->and($subscription->fresh()->ends_at->timestamp)->toBe($firstEnd);
});

it('persists a confirmed webhook for retry and never grants access twice', function () {
    Queue::fake();
    $user = starsUser();
    app(TelegramStarsPaymentService::class)->createSubscriptionInvoice($user, 'basic');
    $invoice = Payment::query()->sole();
    $payload = [
        'update_id' => 912345,
        'message' => [
            'chat' => ['id' => 700100],
            'successful_payment' => confirmedStarsPayment($invoice),
        ],
    ];
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'webhook-test-secret')
        ->postJson('/telegram/webhook', $payload)->assertOk();
    Queue::assertPushed(ProcessTelegramPaymentUpdate::class, 1);
    expect(TelegramUpdate::query()->sole()->payment_payload['telegram_payment_charge_id'])->toBe('charge-1');

    (new ProcessTelegramPaymentUpdate(912345))->handle(app(TelegramStarsPaymentService::class));
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'webhook-test-secret')
        ->postJson('/telegram/webhook', $payload)->assertOk()->assertJsonPath('duplicate', true);
    Queue::assertPushed(ProcessTelegramPaymentUpdate::class, 1);
    expect(TelegramUpdate::query()->sole()->status)->toBe('processed')
        ->and(Entitlement::query()->count())->toBe(1);
});

it('allows only a super admin to refund a paid charge with an audit reason', function () {
    $buyer = starsUser();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    app(TelegramStarsPaymentService::class)->createSubscriptionInvoice($buyer, 'basic');
    $invoice = Payment::query()->sole();
    app(TelegramStarsPaymentService::class)->settleSuccessfulPayment(confirmedStarsPayment($invoice));

    $this->actingAs($buyer)->post("/billing/admin/payments/{$invoice->id}/refund", ['reason' => 'Клиент запросил возврат'])->assertForbidden();
    $this->actingAs($admin)->post("/billing/admin/payments/{$invoice->id}/refund", ['reason' => 'Клиент запросил возврат'])->assertRedirect();
    expect($invoice->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($invoice->fresh()->subscription?->status)->toBe(SubscriptionStatus::Expired)
        ->and(DB::table('billing_events')->where('status', 'completed')->count())->toBe(1);
});
