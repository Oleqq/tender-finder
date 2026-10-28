<?php

namespace App\Services;

use App\Enums\AccessState;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Models\SubscriptionOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SubscriptionOrderService
{
    public function __construct(
        private readonly SubscriptionCatalog $catalog,
        private readonly AccessService $access,
        private readonly CheckoutProvider $provider,
    ) {}

    public function create(User $user, string $code, int $months, string $key): SubscriptionOrder
    {
        return DB::transaction(function () use ($user, $code, $months, $key): SubscriptionOrder {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->assertDemoUser($user);
            $terms = $this->catalog->offer($code, $months);
            $terms['offer_version'] = config('tender.legal.offer_version');
            $terms['privacy_version'] = config('tender.legal.privacy_version');
            $existing = SubscriptionOrder::query()->where('user_id', $user->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                abort_unless($existing->plan_code === $code && $existing->duration_months === $months, 409, 'Ключ заказа уже использован с другими условиями.');

                return $existing;
            }
            abort_unless($this->access->snapshotFor($user)->state === AccessState::Expired, 409, 'Покупка доступна после окончания текущего периода.');
            abort_unless(app(ConsentService::class)->hasCurrentRequiredConsents($user), 409, 'Примите актуальные документы.');
            // This also cancels queued historical deliveries if the scheduler was late.
            app(AccessFreezeService::class)->freezeIfInactive($user, now());
            $pending = SubscriptionOrder::query()->where('user_id', $user->id)->where('status', 'pending')->first();
            if ($pending !== null && $pending->expires_at->isFuture()) {
                abort_unless($pending->plan_code === $code && $pending->duration_months === $months, 409, 'Сначала отмените открытый заказ.');

                return $pending;
            }
            if ($pending !== null) {
                $this->transition($pending, 'canceled');
            }
            $start = now()->startOfSecond();
            $order = SubscriptionOrder::query()->create([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'plan_code' => $code,
                'duration_months' => $months, 'amount_minor' => $terms['amount_minor'], 'currency' => 'RUB',
                'terms' => $terms, 'provider' => 'demo', 'is_demo' => true, 'status' => 'pending',
                'idempotency_key' => $key, 'starts_at' => $start,
                'ends_at' => $this->catalog->endAt($start, $months), 'expires_at' => $start->copy()->addMinutes(30),
            ]);
            $this->event($order, null, 'pending');

            return $order;
        });
    }

    public function confirm(User $user, string $id, string $outcome): SubscriptionOrder
    {
        return DB::transaction(function () use ($user, $id, $outcome): SubscriptionOrder {
            // All checkout paths lock user then order, serializing two tabs/orders.
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->assertDemoUser($user);
            $order = SubscriptionOrder::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
            if ($order->status !== 'pending') {
                return $order;
            }
            if ($order->expires_at->lte(now())) {
                $this->transition($order, 'canceled');

                return $order;
            }
            $status = $this->provider->confirm($order, $outcome);
            if ($status === 'succeeded') {
                abort_unless($this->access->snapshotFor($user)->state === AccessState::Expired, 409, 'У аккаунта уже есть действующий доступ.');
                abort_unless(app(ConsentService::class)->hasCurrentRequiredConsents($user), 409, 'Примите актуальные документы.');
                app(AccessFreezeService::class)->freezeIfInactive($user, now());
                $plan = app(PlanCatalog::class)->byCode($order->plan_code);
                abort_if($plan === null, 409);
                $subscription = Subscription::query()->create([
                    'user_id' => $user->id, 'plan_id' => $plan->id,
                    'source' => SubscriptionSource::Demo, 'status' => SubscriptionStatus::Active,
                    'starts_at' => $order->starts_at, 'ends_at' => $order->ends_at, 'auto_renew' => false,
                ]);
                Entitlement::query()->create([
                    'user_id' => $user->id, 'subscription_id' => $subscription->id, 'plan_id' => $plan->id,
                    'code' => 'active_queries', 'value' => $order->terms['active_query_limit'],
                    'status' => SubscriptionStatus::Active, 'starts_at' => $order->starts_at, 'ends_at' => $order->ends_at,
                    'metadata' => ['source' => 'demo', 'is_demo' => true, 'order_id' => $order->id],
                ]);
                $order->subscription_id = $subscription->id;
            }
            if ($status !== 'pending') {
                $this->transition($order, $status);
            }

            return $order;
        });
    }

    private function assertDemoUser(User $user): void
    {
        abort_unless(config('tender.payments.demo_enabled') && $user->is_demo_guest && $user->is_browser_guest && $user->telegram_id === null, 403);
    }

    private function transition(SubscriptionOrder $order, string $status): void
    {
        $previous = $order->status;
        $order->status = $status;
        $order->save();
        $this->event($order, $previous, $status);
    }

    private function event(SubscriptionOrder $order, ?string $from, string $to): void
    {
        DB::table('subscription_order_events')->insert([
            'order_id' => $order->id, 'from_status' => $from, 'to_status' => $to, 'created_at' => now(),
        ]);
    }
}
