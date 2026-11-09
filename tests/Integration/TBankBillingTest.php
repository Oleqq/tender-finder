<?php

use App\Enums\AccessState;
use App\Enums\ConsentDocument;
use App\Enums\SubscriptionStatus;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Models\SubscriptionOrder;
use App\Models\TBankNotification;
use App\Models\TBankPaymentAttempt;
use App\Models\User;
use App\Services\AccessService;
use App\Services\AdminAccessAnalyticsService;
use App\Services\ConsentService;
use App\Services\PlanCatalog;
use App\Services\TBankApiException;
use App\Services\TBankOrderService;
use App\Services\TBankPaymentService;
use App\Services\TBankReconciliationService;
use App\Services\TBankToken;
use App\Services\TrialLifecycleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Carbon::setTestNow('2026-11-09 09:00:00 UTC');
    config()->set('tender.legal.documents_published', true);
    config()->set('tender.local_mvp_full_access.enabled', false);
    config()->set('tender.temporary_full_access_until', null);
    config()->set('tender.payments.tbank', [
        'enabled' => true, 'notifications_enabled' => true, 'reconciliation_enabled' => true,
        'terminal_key' => 'billing-fixture', 'password' => 'only-a-test-secret',
        'public_base_url' => 'https://shop.example.test', 'request_timeout_seconds' => 15,
        'receipt' => ['taxation' => 'usn_income', 'tax' => 'none', 'payment_method' => 'full_prepayment', 'payment_object' => 'service'],
    ]);
    config()->set('tests.bank_payments', []);
    config()->set('tests.bank_status', 'CONFIRMED');
    config()->set('tests.bank_state_changes', []);
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        expect(DB::transactionLevel())->toBe(0);
        $rows = config('tests.bank_payments');
        if (str_ends_with($request->url(), '/Init')) {
            $attempt = TBankPaymentAttempt::query()->where('order_id', $request['OrderId'])->sole();
            expect($attempt->state)->toBe('initializing')->and($attempt->init_started_at)->not->toBeNull();
            $id = (string) (90001 + count($rows));
            $rows[$id] = ['Success' => true, 'ErrorCode' => '0', 'TerminalKey' => 'billing-fixture',
                'PaymentId' => $id, 'OrderId' => $request['OrderId'], 'Amount' => $request['Amount'], 'Status' => 'NEW'];
            config()->set('tests.bank_payments', $rows);
            if (config('tests.init_failure') === 'network') {
                throw new ConnectionException('simulated network timeout');
            }
            if (config('tests.init_failure') === 'crash') {
                throw new RuntimeException('simulated process crash');
            }

            return Http::response(array_replace($rows[$id] + ['PaymentURL' => config('tests.payment_url', 'https://securepay.tinkoff.ru/new/fixture')], config('tests.init_changes', [])));
        }
        if (str_ends_with($request->url(), '/CheckOrder')) {
            $payments = array_values(array_filter($rows, fn ($row) => $row['OrderId'] === $request['OrderId']));
            if (config('tests.ambiguous_order')) {
                $payments[] = $payments[0];
            }

            return Http::response(['Success' => true, 'ErrorCode' => '0', 'TerminalKey' => 'billing-fixture',
                'OrderId' => $request['OrderId'], 'Payments' => $payments]);
        }
        if (str_ends_with($request->url(), '/GetState')) {
            if (config('tests.reentrant_check')) {
                config()->set('tests.reentrant_check', false);
                $attempt = TBankPaymentAttempt::query()->where('payment_id', $request['PaymentId'])->sole();
                expect(app(TBankPaymentService::class)->reconcile($attempt->id, true)->check_token)->not->toBeNull();
            }
            if (config('tests.state_failure')) {
                throw new ConnectionException('simulated unavailable bank');
            }

            return Http::response(array_replace($rows[$request['PaymentId']], ['Status' => config('tests.bank_status')], config('tests.bank_state_changes')));
        }
        throw new RuntimeException('Unexpected HTTP request');
    });
});

afterEach(function () {
    Carbon::setTestNow();
    Entitlement::flushEventListeners();
});

function billingOwner(): User
{
    $user = User::factory()->create(['telegram_id' => (string) (8900000000 + User::query()->count()),
        'trial_used_at' => now()->subDays(7)]);
    app(ConsentService::class)->acceptCurrent($user, ConsentDocument::cases(), null);

    return $user;
}

function billingOrder(?User $owner = null, string $code = 'pro', int $months = 1): array
{
    $owner ??= billingOwner();
    $order = app(TBankOrderService::class)->create($owner, $code, $months, (string) Str::uuid(), 'billing@example.test');

    return [$owner, $order, TBankPaymentAttempt::query()->where('order_id', $order->id)->sole()];
}

function billingEvent(TBankPaymentAttempt $attempt, array $changes = []): TBankNotification
{
    $payload = array_replace(['TerminalKey' => 'billing-fixture', 'PaymentId' => $attempt->payment_id,
        'OrderId' => $attempt->order_id, 'Amount' => $attempt->amount_minor, 'Status' => 'CONFIRMED', 'Success' => true, 'ErrorCode' => '0'], $changes);
    $payload['Token'] = app(TBankToken::class)->sign($payload, 'only-a-test-secret');
    test()->postJson('/payments/tbank/notifications', $payload)->assertOk()->assertContent('OK');

    return TBankNotification::query()->latest('id')->firstOrFail();
}

it('creates a server priced owned one-off order with encrypted receipt and stable idempotency', function () {
    [$owner, $order, $attempt] = billingOrder();
    expect($order->amount_minor)->toBe(59900)->and($order->provider)->toBe('tbank')->and($order->is_demo)->toBeFalse()
        ->and($order->terms['auto_renew'])->toBeFalse()->and($order->terms['terms_version'])->not->toStartWith('demo')
        ->and($attempt->request_payload['Receipt']['Email'])->toBe('billing@example.test');
    $same = app(TBankOrderService::class)->create($owner, 'pro', 1, $order->idempotency_key, 'billing@example.test');
    expect($same->id)->toBe($order->id)->and(SubscriptionOrder::query()->count())->toBe(1);
    expect(DB::table('tbank_payment_attempts')->value('request_payload'))->not->toContain('billing@example.test');
    expect($attempt->toArray())->not->toHaveKeys(['request_payload', 'payment_url', 'terminal_key']);
    expect(fn () => app(TBankOrderService::class)->create($owner, 'ultra', 1, $order->idempotency_key, 'billing@example.test'))->toThrow(HttpException::class);
    expect(fn () => app(TBankOrderService::class)->create($owner, 'pro', 1, $order->idempotency_key, 'different@example.test'))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

it('refuses demo anonymous unconsented and active accounts without contacting the bank', function (string $kind) {
    $owner = billingOwner();
    if ($kind === 'demo') {
        $owner->forceFill(['is_demo_guest' => true])->save();
    }
    if ($kind === 'browser') {
        $owner->forceFill(['is_browser_guest' => true])->save();
    }
    if ($kind === 'anonymous') {
        $owner->forceFill(['telegram_id' => null])->save();
    }
    if ($kind === 'unconsented') {
        app(ConsentService::class)->revoke($owner, ConsentDocument::Privacy, null);
    }
    if ($kind === 'preview') {
        $owner->forceFill(['trial_used_at' => null])->save();
    }
    expect(fn () => billingOrder($owner))->toThrow(HttpException::class);
    expect(SubscriptionOrder::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['demo', 'browser', 'anonymous', 'unconsented', 'preview']);

it('does not send a second Init on retries and never initializes someone elses order', function () {
    [$owner, $order] = billingOrder();
    $service = app(TBankPaymentService::class);
    $attempt = $service->initialize($owner, $order->id);
    expect($attempt->payment_id)->toBe('90001')->and($attempt->payment_url)->toBe('https://securepay.tinkoff.ru/new/fixture');
    expect($service->initialize($owner, $order->id)->id)->toBe($attempt->id);
    expect(fn () => $service->initialize(billingOwner(), $order->id))->toThrow(ModelNotFoundException::class);
    Http::assertSentCount(1);
    expect(DB::table('tbank_payment_attempts')->value('payment_url'))->not->toContain('securepay');
});

it('requires an actual committed boundary before Init', function () {
    [$owner, $order] = billingOrder();
    DB::beginTransaction();
    try {
        expect(fn () => app(TBankPaymentService::class)->initialize($owner, $order->id))->toThrow(TBankApiException::class, 'tbank_init_inside_transaction');
    } finally {
        DB::rollBack();
    }
    Http::assertNothingSent();
});

it('keeps all outbound work off behind independent flags', function () {
    [$owner, $order, $attempt] = billingOrder();
    config()->set('tender.payments.tbank.enabled', false);
    config()->set('tender.payments.tbank.reconciliation_enabled', false);
    expect(fn () => app(TBankPaymentService::class)->initialize($owner, $order->id))->toThrow(TBankApiException::class, 'tbank_new_payments_disabled');
    expect(fn () => app(TBankPaymentService::class)->reconcile($attempt->id))->toThrow(TBankApiException::class, 'tbank_reconciliation_disabled');
    expect(app(TBankReconciliationService::class)->run())->toBe(['checked' => 0, 'events' => 0, 'review' => 0, 'errors' => 0]);
    $this->artisan('payments:reconcile-tbank')->assertSuccessful();
    Http::assertNothingSent();
});

it('does not expose or follow an unsafe bank payment URL', function (string $url) {
    [$owner, $order, $attempt] = billingOrder();
    config()->set('tests.payment_url', $url);
    expect(fn () => app(TBankPaymentService::class)->initialize($owner, $order->id))->toThrow(TBankApiException::class, 'tbank_invalid_init_result');
    expect($attempt->refresh()->state)->toBe('ambiguous')->and($attempt->payment_url)->toBeNull();
    Http::assertSentCount(1);
})->with(['https://securepay.tinkoff.ru.evil.test/x', 'http://securepay.tinkoff.ru/x', 'https://user@securepay.tinkoff.ru/x', 'https://securepay.tinkoff.ru:8443/x']);

it('recovers an ambiguous Init through CheckOrder without sending another payment request', function (string $failure) {
    [$owner, $order, $attempt] = billingOrder();
    config()->set('tests.init_failure', $failure);
    expect(fn () => app(TBankPaymentService::class)->initialize($owner, $order->id))->toThrow($failure === 'network' ? TBankApiException::class : RuntimeException::class);
    $this->travel(6)->minutes();
    app(TBankPaymentService::class)->initialize($owner, $order->id);
    $result = app(TBankPaymentService::class)->reconcile($attempt->id);
    expect($result->state)->toBe('succeeded')->and($order->refresh()->subscription_id)->not->toBeNull();
    // Laravel records completed responses, not the failed Init request.
    Http::assertSentCount(2);
    expect(count(config('tests.bank_payments')))->toBe(1);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/CheckOrder'));
    expect(Subscription::query()->where('source', 'tbank')->count())->toBe(1);
})->with(['network', 'crash']);

it('does not choose between multiple bank payments after an ambiguous Init', function () {
    [$owner, $order, $attempt] = billingOrder();
    config()->set('tests.init_failure', 'network');
    expect(fn () => app(TBankPaymentService::class)->initialize($owner, $order->id))->toThrow(TBankApiException::class);
    config()->set('tests.ambiguous_order', true);
    $this->travel(6)->minutes();
    expect(app(TBankPaymentService::class)->reconcile($attempt->id)->state)->toBe('review');
    expect($order->refresh()->subscription_id)->toBeNull();
    Http::assertSentCount(1);
});

it('grants exactly once after verified confirmation even when new payments have been stopped', function () {
    [$owner, $order] = billingOrder(null, 'ultra', 3);
    $attempt = app(TBankPaymentService::class)->initialize($owner, $order->id);
    config()->set('tender.payments.tbank.enabled', false);
    $this->travel(2)->days();
    $service = app(TBankPaymentService::class);
    $service->reconcile($attempt->id);
    $service->reconcile($attempt->id, true);
    $order->refresh();
    expect($order->status)->toBe('succeeded')->and($order->starts_at->toAtomString())->toBe(now()->startOfSecond()->toAtomString());
    $subscription = Subscription::query()->where('source', 'tbank')->sole();
    expect($subscription->auto_renew)->toBeFalse()->and($subscription->ends_at->diffInDays($subscription->starts_at, true))->toBeGreaterThan(80);
    expect(Entitlement::query()->where('subscription_id', $subscription->id)->sole()->value)->toBe(30)
        ->and(app(AccessService::class)->snapshotFor($owner)->state)->toBe(AccessState::Active);
    expect(DB::table('subscription_order_events')->where('to_status', 'succeeded')->count())->toBe(1);
    expect(app(AdminAccessAnalyticsService::class)->snapshot()['access']['paid'])->toBe(1);
});

it('does not grant on held canceled refunded or unknown bank states', function (string $status, string $state) {
    [$owner, $order] = billingOrder();
    $attempt = app(TBankPaymentService::class)->initialize($owner, $order->id);
    config()->set('tests.bank_status', $status);
    expect(app(TBankPaymentService::class)->reconcile($attempt->id)->state)->toBe($state);
    expect($order->refresh()->subscription_id)->toBeNull()->and(Entitlement::query()->count())->toBe(0);
})->with([['AUTHORIZED', 'pending'], ['NEW', 'pending'], ['REJECTED', 'canceled'], ['CANCELED', 'canceled'], ['DEADLINE_EXPIRED', 'canceled'], ['REFUNDED', 'review'], ['PARTIAL_REFUNDED', 'review'], ['UNKNOWN_NEW_STATE', 'review']]);

it('rejects mismatched authoritative bank responses before access is issued', function (array $changes) {
    [$owner, $order] = billingOrder();
    $attempt = app(TBankPaymentService::class)->initialize($owner, $order->id);
    config()->set('tests.bank_state_changes', $changes);
    expect(app(TBankPaymentService::class)->reconcile($attempt->id)->state)->toBe('review');
    expect($order->refresh()->subscription_id)->toBeNull();
})->with([[['Amount' => 1]], [['Amount' => '59900']], [['OrderId' => 'other-order']], [['PaymentId' => '999']], [['TerminalKey' => 'wrong-terminal']]]);

it('treats a signed notification as a trigger and deduplicates both delivery and access', function () {
    [$owner, $order] = billingOrder();
    $attempt = app(TBankPaymentService::class)->initialize($owner, $order->id);
    config()->set('tests.bank_status', 'NEW');
    $event = billingEvent($attempt);
    expect(app(TBankReconciliationService::class)->process($event->id))->toBe('processed');
    expect($order->refresh()->subscription_id)->toBeNull();
    config()->set('tests.bank_status', 'CONFIRMED');
    $next = billingEvent($attempt, ['Status' => 'AUTHORIZED']);
    app(TBankReconciliationService::class)->process($next->id);
    billingEvent($attempt, ['Status' => 'AUTHORIZED']);
    app(TBankReconciliationService::class)->process($next->id);
    expect(TBankNotification::query()->count())->toBe(2)->and(Subscription::query()->where('source', 'tbank')->count())->toBe(1);
    Http::assertSentCount(3);
});

it('retries an unknown order and rejects foreign payment envelopes without a bank request', function () {
    [$owner, $order] = billingOrder();
    $attempt = app(TBankPaymentService::class)->initialize($owner, $order->id);
    $unknown = billingEvent($attempt, ['OrderId' => (string) Str::uuid()]);
    $foreign = billingEvent($attempt, ['PaymentId' => '888888']);
    $wrongAmount = billingEvent($attempt, ['Amount' => 1]);
    $consumer = app(TBankReconciliationService::class);
    expect($consumer->process($unknown->id))->toBe('retry')->and($consumer->process($foreign->id))->toBe('rejected')
        ->and($consumer->process($wrongAmount->id))->toBe('rejected');
    expect($order->refresh()->subscription_id)->toBeNull();
    Http::assertSentCount(1);
});

it('recovers after a local database failure without a partial or duplicate grant', function () {
    [$owner, $order] = billingOrder();
    $attempt = app(TBankPaymentService::class)->initialize($owner, $order->id);
    $fail = true;
    Entitlement::creating(function () use (&$fail) {
        if ($fail) {
            $fail = false;
            throw new RuntimeException('simulated database interruption');
        }
    });
    expect(fn () => app(TBankPaymentService::class)->reconcile($attempt->id))->toThrow(RuntimeException::class);
    expect(Subscription::query()->where('source', 'tbank')->count())->toBe(0)->and($order->refresh()->status)->toBe('pending');
    $this->travel(6)->minutes();
    expect(app(TBankPaymentService::class)->reconcile($attempt->id)->state)->toBe('succeeded');
    expect(Subscription::query()->where('source', 'tbank')->count())->toBe(1)->and(Entitlement::query()->count())->toBe(1);
});

it('holds a paid order for review after consent revocation and can safely recover later', function () {
    [$owner, $order] = billingOrder();
    $attempt = app(TBankPaymentService::class)->initialize($owner, $order->id);
    app(ConsentService::class)->revoke($owner, ConsentDocument::Privacy, null);
    expect(app(TBankPaymentService::class)->reconcile($attempt->id)->state)->toBe('review');
    app(ConsentService::class)->acceptCurrent($owner, ConsentDocument::cases(), null);
    expect(app(TBankPaymentService::class)->reconcile($attempt->id, true)->state)->toBe('succeeded');
    expect(Subscription::query()->where('source', 'tbank')->count())->toBe(1);
});

it('keeps refund review separate from automatic access changes or a second grant', function () {
    [$owner, $order] = billingOrder();
    $attempt = app(TBankPaymentService::class)->initialize($owner, $order->id);
    app(TBankPaymentService::class)->reconcile($attempt->id);
    $subscription = Subscription::query()->where('source', 'tbank')->sole();
    $end = $subscription->ends_at->toAtomString();
    config()->set('tests.bank_status', 'REFUNDED');
    $event = billingEvent($attempt, ['Status' => 'REFUNDED']);
    expect(app(TBankReconciliationService::class)->process($event->id))->toBe('review');
    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active)->and($subscription->ends_at->toAtomString())->toBe($end);
    expect($order->refresh()->status)->toBe('succeeded')->and(Subscription::query()->where('source', 'tbank')->count())->toBe(1);
});

it('expires a TBank subscription normally and protects its billing history from trial reset', function () {
    [$owner, $order] = billingOrder();
    $attempt = app(TBankPaymentService::class)->initialize($owner, $order->id);
    app(TBankPaymentService::class)->reconcile($attempt->id);
    $this->artisan('access:reset-trial-preview', ['telegram_id' => $owner->telegram_id])->assertFailed();
    $subscription = Subscription::query()->where('source', 'tbank')->sole();
    Carbon::setTestNow($subscription->ends_at->copy()->addSecond());
    app(TrialLifecycleService::class)->processDue();
    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and(app(AccessService::class)->snapshotFor($owner)->state)->toBe(AccessState::Expired);
});

it('backs off after a bank outage and recovers without reinitializing the payment', function () {
    [$owner, $order] = billingOrder();
    $attempt = app(TBankPaymentService::class)->initialize($owner, $order->id);
    config()->set('tests.state_failure', true);
    $result = app(TBankPaymentService::class)->reconcile($attempt->id);
    expect($result->state)->toBe('pending')->and($result->failure_code)->not->toBeNull()
        ->and($result->next_check_at->isFuture())->toBeTrue()->and($order->refresh()->subscription_id)->toBeNull();
    config()->set('tests.state_failure', false);
    app(TBankPaymentService::class)->reconcile($attempt->id);
    Http::assertSentCount(1);
    $this->travel(3)->minutes();
    expect(app(TBankPaymentService::class)->reconcile($attempt->id)->state)->toBe('succeeded');
    expect(count(config('tests.bank_payments')))->toBe(1);
});

it('keeps an unresolved bank attempt open even after the local order expires', function () {
    [$owner, $order] = billingOrder();
    config()->set('tests.init_failure', 'network');
    expect(fn () => app(TBankPaymentService::class)->initialize($owner, $order->id))->toThrow(TBankApiException::class);
    $this->travel(2)->days();
    expect(fn () => billingOrder($owner))->toThrow(HttpException::class);
    expect(SubscriptionOrder::query()->count())->toBe(1)->and(count(config('tests.bank_payments')))->toBe(1);
});

it('does not replace a grant issued while the bank payment was pending', function () {
    [$owner, $order] = billingOrder();
    $attempt = app(TBankPaymentService::class)->initialize($owner, $order->id);
    $plan = app(PlanCatalog::class)->byCode('ultra');
    $subscription = Subscription::query()->create(['user_id' => $owner->id, 'plan_id' => $plan->id,
        'source' => 'admin_grant', 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addYear(), 'auto_renew' => false]);
    Entitlement::query()->create(['user_id' => $owner->id, 'subscription_id' => $subscription->id,
        'plan_id' => $plan->id, 'code' => 'active_queries', 'value' => 30, 'status' => 'active',
        'starts_at' => now(), 'ends_at' => $subscription->ends_at]);
    $end = $subscription->ends_at->toAtomString();
    expect(app(TBankPaymentService::class)->reconcile($attempt->id)->state)->toBe('review');
    expect($order->refresh()->subscription_id)->toBeNull()->and(Subscription::query()->count())->toBe(1)
        ->and($subscription->refresh()->ends_at->toAtomString())->toBe($end);
});

it('defers an early notification until ambiguous Init is independently recovered', function () {
    [$owner, $order, $attempt] = billingOrder();
    config()->set('tests.init_failure', 'crash');
    expect(fn () => app(TBankPaymentService::class)->initialize($owner, $order->id))->toThrow(RuntimeException::class);
    $event = billingEvent($attempt, ['PaymentId' => '90001']);
    expect(app(TBankReconciliationService::class)->process($event->id))->toBe('retry');
    $this->travel(6)->minutes();
    $counts = app(TBankReconciliationService::class)->run();
    expect($counts['checked'])->toBe(1)->and($counts['events'])->toBe(1)
        ->and($event->refresh()->state)->toBe('processed')->and($order->refresh()->status)->toBe('succeeded');
    expect(Subscription::query()->where('source', 'tbank')->count())->toBe(1);
});

it('does not let a second worker bypass an active reconciliation lease', function () {
    [$owner, $order] = billingOrder();
    $attempt = app(TBankPaymentService::class)->initialize($owner, $order->id);
    config()->set('tests.reentrant_check', true);
    expect(app(TBankPaymentService::class)->reconcile($attempt->id)->state)->toBe('succeeded');
    Http::assertSentCount(2);
    expect(Subscription::query()->where('source', 'tbank')->count())->toBe(1);
});

it('does not bind another orders scoped bank payment identifier', function () {
    [$owner, $order] = billingOrder();
    app(TBankPaymentService::class)->initialize($owner, $order->id);
    [$otherOwner, $otherOrder] = billingOrder();
    config()->set('tests.init_changes', ['PaymentId' => '90001']);
    $result = app(TBankPaymentService::class)->initialize($otherOwner, $otherOrder->id);
    expect($result->state)->toBe('review')->and($result->failure_code)->toBe('tbank_payment_id_conflict')
        ->and($result->payment_id)->toBeNull()->and($otherOrder->refresh()->subscription_id)->toBeNull();
});
