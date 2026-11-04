<?php

use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Jobs\ProcessTelegramUpdate;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Models\SubscriptionOrder;
use App\Models\SubscriptionRenewal;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\PlanCatalog;
use App\Services\SubscriptionCatalog;
use App\Services\SubscriptionRenewalService;
use App\Services\TelegramBotClient;
use App\Services\YooKassaApiException;
use App\Services\YooKassaClient;
use App\Telegram\TelegramDeliveryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 09:00:00 UTC');
    config()->set('tender.payments.demo_enabled', true);
    config()->set('tender.local_mvp_full_access.enabled', false);
    config()->set('tender.temporary_full_access_until', null);
    config()->set('tender.legal.documents_published', true);
    config()->set('tender.telegram.bot_token', 'renewal-test-token');
    config()->set('tender.telegram.webhook_secret', 'renewal-test-secret');
    config()->set('tests.renewal_failure', false);
    Http::fake(fn (Request $request) => str_starts_with($request->url(), 'https://api.yookassa.ru/')
        ? Http::response(['id' => 'test-payment', 'status' => 'pending'])
        : (config('tests.renewal_failure') ? Http::response(['ok' => false], 403) : Http::response(['ok' => true, 'result' => true])));
});
afterEach(fn () => Carbon::setTestNow());

function renewalDemoGuest(): User
{
    $user = User::factory()->create();
    $user->forceFill(['is_demo_guest' => true, 'is_browser_guest' => true, 'telegram_id' => null])->save();
    test()->actingAs($user)->postJson('/consents', ['documents' => ['terms', 'offer', 'privacy', 'consent']])->assertCreated();
    test()->postJson('/demo/expire')->assertOk();

    return $user->refresh();
}

function renewalRealFixture(?User $owner = null): array
{
    $user = $owner ?? User::factory()->create(['telegram_id' => '99887766']);
    $subscription = Subscription::query()->create([
        'user_id' => $user->id, 'plan_id' => app(PlanCatalog::class)->pro()->id,
        'source' => SubscriptionSource::YooKassa, 'status' => SubscriptionStatus::Active,
        'starts_at' => now(), 'ends_at' => now()->addDays(30), 'auto_renew' => true,
    ]);
    $terms = app(SubscriptionCatalog::class)->offer('pro', 1);
    $terms['auto_renew'] = true;
    $terms['auto_renew_consent_provider'] = 'yookassa';
    $terms['auto_renew_consent_version'] = SubscriptionRenewalService::CONSENT_VERSION;
    $terms['auto_renew_consented_at'] = now()->toAtomString();
    $order = SubscriptionOrder::query()->create([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'plan_code' => 'pro', 'duration_months' => 1,
        'amount_minor' => 59900, 'currency' => 'RUB', 'terms' => $terms,
        'provider' => 'yookassa', 'is_demo' => false, 'status' => 'succeeded',
        'subscription_id' => $subscription->id, 'idempotency_key' => (string) Str::uuid(),
        'starts_at' => now(), 'ends_at' => $subscription->ends_at, 'expires_at' => now()->addMinutes(30),
    ]);
    $renewal = app(SubscriptionRenewalService::class)->registerConfirmed($order);

    return [$user, $subscription, $order, $renewal];
}

it('defaults to one-off purchase and records explicit renewal consent only on selected confirmed orders', function () {
    renewalDemoGuest();
    $payload = ['plan_code' => 'pro', 'duration_months' => 3, 'idempotency_key' => (string) Str::uuid()];
    $order = $this->postJson('/demo/orders', $payload)->assertOk()->assertJsonPath('order.terms.auto_renew', false)->json('order');
    $this->postJson('/demo/orders', [...$payload, 'auto_renew' => true])->assertConflict();
    $this->postJson('/demo/orders/'.$order['id'].'/confirm', ['outcome' => 'canceled'])->assertOk();
    $payload['idempotency_key'] = (string) Str::uuid();
    $order = $this->postJson('/demo/orders', [...$payload, 'auto_renew' => true])->assertOk()
        ->assertJsonPath('order.terms.auto_renew', true)->assertJsonPath('order.terms.auto_renew_consent_version', SubscriptionRenewalService::CONSENT_VERSION)->json('order');
    expect(SubscriptionRenewal::query()->count())->toBe(0);
    $this->postJson('/demo/orders/'.$order['id'].'/confirm', ['outcome' => 'succeeded'])->assertOk();
    $this->postJson('/demo/orders/'.$order['id'].'/confirm', ['outcome' => 'succeeded'])->assertOk();
    $renewal = SubscriptionRenewal::query()->sole();
    expect($renewal->provider)->toBe('demo')->and($renewal->amount_minor)->toBe(169900)
        ->and($renewal->subscription->auto_renew)->toBeTrue()->and($renewal->consented_at)->not->toBeNull();
    expect(DB::table('subscription_renewal_events')->count())->toBe(1);
    Http::assertNothingSent();
});

it('sends one due notice with exact amount date and signed cancellation button at least 24 hours before charge', function () {
    [$user, $subscription, $order, $renewal] = renewalRealFixture();
    $service = app(SubscriptionRenewalService::class);
    expect($service->processDue())->toBe(0);
    Carbon::setTestNow($renewal->notice_due_at);
    expect($service->processDue())->toBe(1)->and($service->processDue())->toBe(0);
    $renewal->refresh();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r['chat_id'] === $user->telegram_id && str_contains($r['text'], '599,00 ₽')
        && str_contains($r['text'], 'МСК') && str_contains($r['text'], $service->cancelUrl($renewal))
        && $r['reply_markup']['inline_keyboard'][0][0]['url'] === $service->cancelUrl($renewal));
    expect($renewal->notice_status)->toBe('sent')->and($service->readyForCharge($renewal))->toBeFalse();
    Carbon::setTestNow($renewal->charge_not_before->copy()->subSecond());
    expect($service->readyForCharge($renewal))->toBeFalse();
    Carbon::setTestNow($renewal->charge_not_before);
    expect($service->readyForCharge($renewal))->toBeTrue();
    $service->cancel($renewal);
    expect($service->readyForCharge($renewal))->toBeFalse();
});

it('postpones a late notice and never marks failed delivery as a charge permission', function () {
    [, , , $renewal] = renewalRealFixture();
    $service = app(SubscriptionRenewalService::class);
    Carbon::setTestNow($renewal->next_charge_at->copy()->addHour());
    expect($service->processDue())->toBe(1);
    $renewal->refresh();
    expect($renewal->charge_not_before->gte(now()->addHours(24)))->toBeTrue();
    expect($service->readyForCharge($renewal))->toBeFalse();
});

it('does not send a canceled notice and preserves the paid entitlement and end date', function () {
    [$owner, $subscription, , $renewal] = renewalRealFixture();
    $entitlement = Entitlement::query()->create(['user_id' => $owner->id, 'subscription_id' => $subscription->id,
        'plan_id' => $subscription->plan_id, 'code' => 'active_queries', 'value' => 10,
        'status' => SubscriptionStatus::Active, 'starts_at' => now(), 'ends_at' => $subscription->ends_at]);
    $end = $subscription->ends_at->toAtomString();
    $this->actingAs(User::factory()->create())->postJson('/subscriptions/renewals/'.$renewal->id.'/cancel')->assertNotFound();
    $this->actingAs($owner)->postJson('/subscriptions/renewals/'.$renewal->id.'/cancel')->assertOk();
    $this->postJson('/subscriptions/renewals/'.$renewal->id.'/cancel')->assertOk();
    expect($subscription->refresh()->auto_renew)->toBeFalse()->and($subscription->ends_at->toAtomString())->toBe($end)
        ->and($entitlement->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(DB::table('subscription_renewal_events')->where('action', 'cancelled')->count())->toBe(1);
    Carbon::setTestNow($renewal->next_charge_at);
    expect(app(SubscriptionRenewalService::class)->processDue())->toBe(0);
    Http::assertNothingSent();
});

it('uses a signed read-only cancellation link with explicit POST confirmation and no login requirement', function () {
    [, $subscription, , $renewal] = renewalRealFixture();
    $url = app(SubscriptionRenewalService::class)->cancelUrl($renewal);
    $this->get($url)->assertOk()->assertSee('Отменить автопродление')->assertHeader('Referrer-Policy', 'no-referrer');
    expect($subscription->refresh()->auto_renew)->toBeTrue();
    $this->get('/renewal/'.$renewal->id.'/cancel')->assertForbidden();
    $this->post(str_replace($renewal->id, (string) Str::uuid(), $url))->assertForbidden();
    $this->post($url)->assertOk()->assertSee('Автопродление отключено');
    expect($subscription->refresh()->auto_renew)->toBeFalse();
});

it('keeps demo renewal notices local and does not call payment or Telegram APIs', function () {
    renewalDemoGuest();
    $order = $this->postJson('/demo/orders', ['plan_code' => 'basic', 'duration_months' => 1,
        'auto_renew' => true, 'idempotency_key' => (string) Str::uuid()])->json('order');
    $this->postJson('/demo/orders/'.$order['id'].'/confirm', ['outcome' => 'succeeded'])->assertOk();
    $renewal = SubscriptionRenewal::query()->sole();
    Carbon::setTestNow($renewal->notice_due_at);
    $this->artisan('subscriptions:send-renewal-notices')->assertSuccessful();
    expect($renewal->refresh()->notice_status)->toBe('demo_recorded')
        ->and(app(SubscriptionRenewalService::class)->readyForCharge($renewal))->toBeFalse();
    Http::assertNothingSent();
});

it('keeps a failed notice ineligible for a charge and retries without shortening the cancellation window', function () {
    [, , , $renewal] = renewalRealFixture();
    $service = app(SubscriptionRenewalService::class);
    Carbon::setTestNow($renewal->notice_due_at);
    config()->set('tests.renewal_failure', true);
    expect($service->processDue())->toBe(0);
    expect($renewal->refresh()->notice_status)->toBe('failed')->and($renewal->notice_sent_at)->toBeNull();
    Carbon::setTestNow($renewal->next_charge_at->copy()->addDays(2));
    expect($service->readyForCharge($renewal))->toBeFalse();
    config()->set('tests.renewal_failure', false);
    expect($service->processDue())->toBe(1);
    expect($renewal->refresh()->charge_not_before->gte(now()->addHours(24)))->toBeTrue();
    expect($service->readyForCharge($renewal))->toBeFalse();
});

it('handles cancel through the webhook using the private chat actor', function () {
    [$owner, , , $renewal] = renewalRealFixture();
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'renewal-test-secret')->postJson('/telegram/webhook', [
        'update_id' => 933001,
        'message' => ['text' => '/cancel', 'from' => ['id' => (int) $owner->telegram_id],
            'chat' => ['id' => (int) $owner->telegram_id, 'type' => 'private']],
    ])->assertOk();
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage')
        && str_contains(html_entity_decode($request['text']), 'Автопродление отключено'));
    expect($renewal->refresh()->state)->toBe('cancelled')->and($renewal->subscription->auto_renew)->toBeFalse();
});

function sendRenewalCancel(User $user, int $updateId = 944001, array $message = []): void
{
    test()->withHeader('X-Telegram-Bot-Api-Secret-Token', 'renewal-test-secret')->postJson('/telegram/webhook', [
        'update_id' => $updateId,
        'message' => array_replace_recursive(['text' => '/cancel', 'from' => ['id' => (int) $user->telegram_id],
            'chat' => ['id' => (int) $user->telegram_id, 'type' => 'private']], $message),
    ])->assertOk();
}

it('cancels all owned renewals immediately without touching other users or paid access', function () {
    [$owner, $subscription, , $first] = renewalRealFixture();
    [, $secondSubscription, , $second] = renewalRealFixture($owner);
    $other = User::factory()->create(['telegram_id' => '44556677']);
    [, $otherSubscription, , $otherRenewal] = renewalRealFixture($other);
    $entitlement = Entitlement::query()->create(['user_id' => $owner->id, 'subscription_id' => $subscription->id,
        'plan_id' => $subscription->plan_id, 'code' => 'active_queries', 'value' => 10,
        'status' => SubscriptionStatus::Active, 'starts_at' => now(), 'ends_at' => $subscription->ends_at]);
    $end = $subscription->ends_at->toAtomString();
    $first->forceFill(['retry_at' => now()->addMinutes(5), 'notice_status' => 'failed'])->save();
    sendRenewalCancel($owner);
    expect($first->refresh()->state)->toBe('cancelled')->and($first->retry_at)->toBeNull()
        ->and($second->refresh()->state)->toBe('cancelled')
        ->and($subscription->refresh()->auto_renew)->toBeFalse()->and($secondSubscription->refresh()->auto_renew)->toBeFalse()
        ->and($otherRenewal->refresh()->state)->toBe('active')->and($otherSubscription->refresh()->auto_renew)->toBeTrue()
        ->and($subscription->ends_at->toAtomString())->toBe($end)->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($entitlement->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(TelegramUpdate::query()->sole()->command_result)->toBe(['outcome' => 'cancelled', 'cancelled_count' => 2]);
});

it('does not repeat a cancellation when the webhook is replayed after a new opt in', function () {
    [$owner, , , $old] = renewalRealFixture();
    sendRenewalCancel($owner);
    [, , , $new] = renewalRealFixture($owner);
    sendRenewalCancel($owner);
    expect($new->refresh()->state)->toBe('active')
        ->and(DB::table('subscription_renewal_events')->where('action', 'cancelled')->count())->toBe(1);
    sendRenewalCancel($owner, 944002);
    expect($new->refresh()->state)->toBe('cancelled');
});

it('keeps a durable result when the bot reply fails so retry cannot revoke a later consent', function () {
    [$owner, , , $old] = renewalRealFixture();
    $update = TelegramUpdate::query()->create(['telegram_update_id' => 944003, 'type' => 'message', 'status' => 'received', 'received_at' => now()]);
    $job = new ProcessTelegramUpdate(944003, '/cancel', $owner->telegram_id, $owner->telegram_id);
    config()->set('tests.renewal_failure', true);
    expect(fn () => $job->handle(app(TelegramBotClient::class)))->toThrow(TelegramDeliveryException::class);
    expect($old->refresh()->state)->toBe('cancelled')->and($update->refresh()->command_result['cancelled_count'])->toBe(1);
    [, , , $new] = renewalRealFixture($owner);
    config()->set('tests.renewal_failure', false);
    $job->handle(app(TelegramBotClient::class));
    expect($new->refresh()->state)->toBe('active')->and($update->refresh()->status)->toBe('processed');
});

it('rejects group bot and mismatched private actors without disclosing subscription links', function (array $message) {
    [$owner, $subscription, , $renewal] = renewalRealFixture();
    sendRenewalCancel($owner, 944004, $message);
    expect($renewal->refresh()->state)->toBe('active')->and($subscription->refresh()->auto_renew)->toBeTrue();
    Http::assertSent(fn (Request $request) => str_contains($request['text'], 'личный чат') && ! str_contains($request['text'], $renewal->id));
})->with([
    'group' => [['chat' => ['id' => -12345, 'type' => 'group']]],
    'another private chat' => [['chat' => ['id' => 44556677]]],
    'bot actor' => [['from' => ['is_bot' => true]]],
]);

it('handles cancel for an unknown actor without creating an account or subscription', function () {
    $actor = User::factory()->make(['telegram_id' => '99887766']);
    sendRenewalCancel($actor);
    expect(User::query()->count())->toBe(0)->and(SubscriptionRenewal::query()->count())->toBe(0);
    Http::assertSent(fn (Request $request) => str_contains($request['text'], 'Автопродление не включено'));
});

it('disables recurring flags even when a real schedule was never registered', function () {
    [$owner, $subscription, , $renewal] = renewalRealFixture();
    $renewal->delete();
    sendRenewalCancel($owner);
    expect($subscription->refresh()->auto_renew)->toBeFalse()->and($subscription->status)->toBe(SubscriptionStatus::Active);
});

it('does not send notices or retries after a Telegram cancellation made before or after notification', function (bool $afterNotice) {
    [$owner, , , $renewal] = renewalRealFixture();
    $service = app(SubscriptionRenewalService::class);
    Carbon::setTestNow($renewal->notice_due_at);
    if ($afterNotice) {
        expect($service->processDue())->toBe(1);
    }
    sendRenewalCancel($owner);
    Http::fake();
    Carbon::setTestNow($renewal->next_charge_at->copy()->addDays(3));
    expect($service->processDue())->toBe(0)->and($service->readyForCharge($renewal))->toBeFalse();
    Http::assertNothingSent();
})->with([false, true]);

it('blocks retired saved method payments and refuses a stale queued attempt', function () {
    [$owner, $subscription, , $renewal] = renewalRealFixture();
    $service = app(SubscriptionRenewalService::class);
    Carbon::setTestNow($renewal->notice_due_at);
    expect($service->processDue())->toBe(1);
    Carbon::setTestNow($renewal->refresh()->charge_not_before);
    config()->set('tender.payments.yookassa.enabled', true);
    config()->set('tender.payments.yookassa.shop_id', 'test-shop');
    config()->set('tender.payments.yookassa.secret_key', 'test-only-secret');
    Http::fake(['https://api.yookassa.ru/*' => Http::response(['id' => 'test-payment', 'status' => 'pending'])]);
    $client = app(YooKassaClient::class);
    $payload = ['payment_method_id' => 'verified-test-method', 'capture' => true, 'amount' => ['value' => '0.01', 'currency' => 'USD']];
    expect(fn () => $client->createRenewalPayment($renewal, $payload, 'test-cycle-one'))
        ->toThrow(YooKassaApiException::class, 'yookassa_new_payments_disabled');
    Http::assertNothingSent();
    $service->cancel($renewal->fresh());
    Http::fake();
    expect($client->createRenewalPayment($renewal, $payload, 'test-cycle-one'))->toBeNull();
    Http::assertNothingSent();
    expect($subscription->refresh()->auto_renew)->toBeFalse();
});

it('cannot bypass cancellation guards through the low level saved method API', function () {
    expect(fn () => app(YooKassaClient::class)->createPayment(['payment_method_id' => 'test-method'], 'test-cycle'))
        ->toThrow(YooKassaApiException::class, 'yookassa_renewal_context_required');
    Http::assertNothingSent();
});

it('repairs a cancelled schedule from the prior release without adding another cancellation event', function () {
    [, $subscription, , $renewal] = renewalRealFixture();
    $service = app(SubscriptionRenewalService::class);
    $service->cancel($renewal);
    $subscription->forceFill(['auto_renew' => true])->save();
    $renewal->forceFill(['retry_at' => now()->addMinutes(5), 'charge_not_before' => now()->addDay()])->save();
    $service->cancel($renewal);
    expect($renewal->refresh()->retry_at)->toBeNull()->and($renewal->charge_not_before)->toBeNull()
        ->and($subscription->refresh()->auto_renew)->toBeFalse()
        ->and(DB::table('subscription_renewal_events')->where('action', 'cancelled')->count())->toBe(1);
});
