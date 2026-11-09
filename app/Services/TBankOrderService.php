<?php

namespace App\Services;

use App\Enums\AccessState;
use App\Models\SubscriptionOrder;
use App\Models\TBankPaymentAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Internal boundary for a future approved external checkout, never a Telegram purchase route. */
final class TBankOrderService
{
    public function create(User $owner, string $code, int $months, string $key, string $receiptEmail): SubscriptionOrder
    {
        abort_unless(config('tender.payments.tbank.enabled') === true, 503);
        abort_unless(Str::isUuid($key) && strlen($receiptEmail) <= 64 && filter_var($receiptEmail, FILTER_VALIDATE_EMAIL) !== false, 422);
        $terminal = config('tender.payments.tbank.terminal_key');
        $password = config('tender.payments.tbank.password');
        if (! is_string($terminal) || $terminal === '' || strlen($terminal) > 64 || ! is_string($password) || $password === '') {
            throw new TBankApiException('tbank_credentials_missing');
        }

        return DB::transaction(function () use ($owner, $code, $months, $key, $receiptEmail, $terminal): SubscriptionOrder {
            $user = User::query()->lockForUpdate()->findOrFail($owner->id);
            $this->assertOwner($user);
            $existing = SubscriptionOrder::query()->where('user_id', $user->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $attempt = TBankPaymentAttempt::query()->where('order_id', $existing->id)->first();
                abort_unless($existing->provider === 'tbank' && ! $existing->is_demo && $existing->plan_code === $code
                    && $existing->duration_months === $months && $attempt !== null
                    && ($attempt->request_payload['Receipt']['Email'] ?? null) === $receiptEmail, 409);

                return $existing;
            }
            $this->assertCanPurchase($user);
            $open = TBankPaymentAttempt::query()->whereIn('order_id', SubscriptionOrder::query()->where('user_id', $user->id)->select('id'))
                ->whereNotIn('state', ['succeeded', 'canceled'])->lockForUpdate()->get();
            foreach ($open as $attempt) {
                $old = SubscriptionOrder::query()->findOrFail($attempt->order_id);
                // Only an unused link can expire locally. An ambiguous bank request must be reconciled first.
                if ($attempt->state === 'prepared' && $attempt->init_started_at === null && $old->expires_at->lte(now())) {
                    $attempt->forceFill(['state' => 'canceled'])->save();
                    $this->transition($old, 'canceled');
                } else {
                    abort(409, 'Сначала завершите или сверяйте открытый платёж.');
                }
            }
            $terms = app(SubscriptionCatalog::class)->offer($code, $months);
            $terms['terms_version'] = 'tbank-one-off-2026-11-09-v1';
            foreach (LegalDocumentRegistry::TYPES as $type) {
                $terms[$type.'_version'] = app(LegalDocumentRegistry::class)->document($type)['version'];
            }
            app(AccessFreezeService::class)->freezeIfInactive($user, now());
            $start = now()->startOfSecond();
            $order = new SubscriptionOrder([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'plan_code' => $code,
                'duration_months' => $months, 'amount_minor' => $terms['amount_minor'], 'currency' => 'RUB',
                'terms' => $terms, 'provider' => 'tbank', 'is_demo' => false, 'status' => 'pending',
                'idempotency_key' => $key, 'starts_at' => $start, 'ends_at' => app(SubscriptionCatalog::class)->endAt($start, $months),
                'expires_at' => $start->copy()->addMinutes(30),
            ]);
            $request = app(TBankCheckoutRequestFactory::class)->make($order, $receiptEmail);
            $order->save();
            TBankPaymentAttempt::query()->create([
                'id' => (string) Str::uuid(), 'order_id' => $order->id, 'terminal_key' => $terminal,
                'amount_minor' => $order->amount_minor, 'request_payload' => $request, 'state' => 'prepared',
            ]);
            $this->transition($order, 'pending', true);

            return $order;
        });
    }

    public function assertOwner(User $user): void
    {
        abort_unless(! $user->is_demo_guest && ! $user->is_browser_guest
            && is_string($user->telegram_id) && preg_match('/\A[1-9]\d*\z/D', $user->telegram_id) === 1, 403);
    }

    public function assertCanPurchase(User $user): void
    {
        $this->assertOwner($user);
        abort_unless(app(AccessService::class)->snapshotFor($user)->state === AccessState::Expired, 409, 'Покупка доступна после окончания текущего периода.');
        abort_unless(app(ConsentService::class)->hasCurrentRequiredConsents($user), 409, 'Примите актуальные документы.');
    }

    public function transition(SubscriptionOrder $order, string $to, bool $initial = false): void
    {
        $from = $initial ? null : $order->status;
        if (! $initial && $from === $to) {
            return;
        }
        $order->forceFill(['status' => $to])->save();
        DB::table('subscription_order_events')->insert(['order_id' => $order->id, 'from_status' => $from, 'to_status' => $to, 'created_at' => now()]);
    }
}
