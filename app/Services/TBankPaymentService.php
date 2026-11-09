<?php

namespace App\Services;

use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Models\SubscriptionOrder;
use App\Models\TBankPaymentAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class TBankPaymentService
{
    public function initialize(User $owner, string $orderId): TBankPaymentAttempt
    {
        // Init must never run before the durable attempt marker has actually committed.
        if (DB::transactionLevel() !== 0) {
            throw new TBankApiException('tbank_init_inside_transaction');
        }
        if (config('tender.payments.tbank.enabled') !== true) {
            throw new TBankApiException('tbank_new_payments_disabled');
        }
        $order = SubscriptionOrder::query()->where('user_id', $owner->id)->where('provider', 'tbank')->where('is_demo', false)->findOrFail($orderId);
        $candidate = TBankPaymentAttempt::query()->where('order_id', $order->id)->firstOrFail();
        $send = $this->locked($candidate->id, function (User $user, SubscriptionOrder $order, TBankPaymentAttempt $attempt): bool {
            if ($attempt->state !== 'prepared') {
                return false;
            }
            app(TBankOrderService::class)->assertCanPurchase($user);
            abort_unless($order->status === 'pending' && $order->expires_at->gt(now()->addMinute()), 409);
            $this->assertSnapshot($order, $attempt);
            $this->assertTerminal($attempt);
            $attempt->forceFill(['state' => 'initializing', 'init_started_at' => now(), 'next_check_at' => now()->addMinutes(5)])->save();

            return true;
        });
        if (! $send) {
            // Retry is a read of this user's persisted attempt, never another Init.
            return $candidate->refresh();
        }

        try {
            $response = app(TBankClient::class)->initialize($candidate->refresh()->request_payload);
            $id = $response['PaymentId'] ?? null;
            $url = $response['PaymentURL'] ?? null;
            if ((! is_string($id) && ! is_int($id)) || preg_match('/\A[0-9]{1,20}\z/D', (string) $id) !== 1
                || ($response['OrderId'] ?? null) !== $order->id || ($response['Amount'] ?? null) !== $order->amount_minor
                || ! is_string($url) || ! $this->allowedPaymentUrl($url)) {
                throw new TBankApiException('tbank_invalid_init_result');
            }

            return $this->locked($candidate->id, function (User $user, SubscriptionOrder $order, TBankPaymentAttempt $attempt) use ($id, $url): TBankPaymentAttempt {
                if (($attempt->payment_id !== null && $attempt->payment_id !== (string) $id)
                    || TBankPaymentAttempt::query()->where('terminal_key', $attempt->terminal_key)->where('payment_id', (string) $id)->whereKeyNot($attempt->id)->exists()) {
                    return $this->review($order, $attempt, 'tbank_payment_id_conflict');
                }
                if (in_array($attempt->state, ['initializing', 'ambiguous', 'pending'], true)) {
                    $attempt->forceFill(['payment_id' => (string) $id, 'payment_url' => $url, 'state' => 'pending',
                        'failure_code' => null, 'next_check_at' => now(), 'check_token' => null])->save();
                }

                return $attempt;
            });
        } catch (TBankApiException $exception) {
            $this->locked($candidate->id, function (User $user, SubscriptionOrder $order, TBankPaymentAttempt $attempt) use ($exception): void {
                if ($attempt->payment_id === null && $attempt->state === 'initializing') {
                    $attempt->forceFill(['state' => 'ambiguous', 'failure_code' => $exception->failureCode, 'next_check_at' => now()->addMinute()])->save();
                }
            });
            throw $exception;
        }
    }

    public function reconcile(string $id, bool $retryReview = false): TBankPaymentAttempt
    {
        if (DB::transactionLevel() !== 0) {
            throw new TBankApiException('tbank_check_inside_transaction');
        }
        if (config('tender.payments.tbank.reconciliation_enabled') !== true) {
            throw new TBankApiException('tbank_reconciliation_disabled');
        }
        $claim = $this->locked($id, function (User $user, SubscriptionOrder $order, TBankPaymentAttempt $attempt) use ($retryReview): ?string {
            if ($attempt->state === 'prepared'
                || (! $retryReview && in_array($attempt->state, ['succeeded', 'canceled', 'review'], true))
                || ($attempt->check_token !== null && $attempt->next_check_at?->isFuture())
                || (! $retryReview && $attempt->next_check_at?->isFuture())) {
                return null;
            }
            try {
                $this->assertTerminal($attempt);
                $this->assertSnapshot($order, $attempt);
                app(TBankOrderService::class)->assertOwner($user);
            } catch (TBankApiException|HttpException $exception) {
                $this->review($order, $attempt, $exception instanceof TBankApiException ? $exception->failureCode : 'tbank_owner_ineligible');

                return null;
            }
            $token = (string) Str::uuid();
            $attempt->forceFill(['check_token' => $token, 'check_count' => $attempt->check_count + 1, 'next_check_at' => now()->addMinutes(5)])->save();

            return $token;
        });
        if ($claim === null) {
            return TBankPaymentAttempt::query()->findOrFail($id);
        }
        $attempt = TBankPaymentAttempt::query()->findOrFail($id);
        try {
            $paymentId = $attempt->payment_id ?? $this->recoverPaymentId($attempt);
            $payment = app(TBankPaymentVerifier::class)->verifiedState($paymentId, $attempt->order_id, $attempt->amount_minor);

            return $this->locked($id, function (User $user, SubscriptionOrder $order, TBankPaymentAttempt $current) use ($claim, $paymentId, $payment): TBankPaymentAttempt {
                if ($current->check_token !== $claim) {
                    return $current;
                }
                $this->assertSnapshot($order, $current);
                if ($current->payment_id !== null && $current->payment_id !== $paymentId) {
                    return $this->review($order, $current, 'tbank_payment_id_conflict');
                }
                if (TBankPaymentAttempt::query()->where('terminal_key', $current->terminal_key)->where('payment_id', $paymentId)->whereKeyNot($current->id)->exists()) {
                    return $this->review($order, $current, 'tbank_payment_id_conflict');
                }
                $current->payment_id = $paymentId;
                $current->bank_status = $payment['Status'];
                $current->check_token = null;
                $current->failure_code = null;
                if ($payment['Status'] === 'CONFIRMED') {
                    return $this->grant($user, $order, $current);
                }
                if (in_array($payment['Status'], ['REFUNDING', 'REFUNDED', 'PARTIAL_REFUNDED', 'REVERSING', 'REVERSED', 'PARTIAL_REVERSED'], true)) {
                    return $this->review($order, $current, 'tbank_refund_requires_review');
                }
                if ($order->subscription_id !== null) {
                    return $this->review($order, $current, 'tbank_bank_state_regressed');
                }
                if (in_array($payment['Status'], ['CANCELED', 'DEADLINE_EXPIRED', 'REJECTED', 'AUTH_FAIL'], true)) {
                    $current->forceFill(['state' => 'canceled', 'next_check_at' => null])->save();
                    app(TBankOrderService::class)->transition($order, 'canceled');

                    return $current;
                }
                if (! in_array($payment['Status'], ['NEW', 'FORM_SHOWED', 'AUTHORIZING', '3DS_CHECKING', '3DS_CHECKED', 'AUTHORIZED', 'PAY_CHECKING', 'CONFIRMING'], true)) {
                    return $this->review($order, $current, 'tbank_unknown_bank_status');
                }
                $current->forceFill(['state' => 'pending', 'next_check_at' => now()->addMinutes(2)])->save();

                return $current;
            });
        } catch (TBankApiException $exception) {
            return $this->locked($id, function (User $user, SubscriptionOrder $order, TBankPaymentAttempt $current) use ($claim, $exception): TBankPaymentAttempt {
                if ($current->check_token !== $claim) {
                    return $current;
                }
                if (! $exception->retryable || $current->check_count >= 12) {
                    return $this->review($order, $current, $exception->failureCode);
                }
                $current->forceFill(['failure_code' => $exception->failureCode, 'check_token' => null,
                    'next_check_at' => now()->addSeconds(min(3600, 60 * (2 ** min($current->check_count, 6))))])->save();

                return $current;
            });
        }
    }

    private function recoverPaymentId(TBankPaymentAttempt $attempt): string
    {
        $response = app(TBankClient::class)->checkOrder($attempt->order_id);
        $payments = $response['Payments'] ?? null;
        if (($response['OrderId'] ?? null) !== $attempt->order_id || ! is_array($payments) || ! array_is_list($payments)) {
            throw new TBankApiException('tbank_invalid_order_state');
        }
        if ($payments === []) {
            throw new TBankApiException('tbank_init_not_found', true);
        }
        if (count($payments) !== 1 || ! is_array($payments[0]) || ($payments[0]['Amount'] ?? null) !== $attempt->amount_minor) {
            throw new TBankApiException('tbank_ambiguous_order_payments');
        }
        $id = $payments[0]['PaymentId'] ?? null;
        if ((! is_string($id) && ! is_int($id)) || preg_match('/\A[0-9]{1,20}\z/D', (string) $id) !== 1) {
            throw new TBankApiException('tbank_invalid_payment_id');
        }

        return (string) $id;
    }

    private function grant(User $user, SubscriptionOrder $order, TBankPaymentAttempt $attempt): TBankPaymentAttempt
    {
        if ($order->status === 'succeeded' && $order->subscription_id !== null) {
            $attempt->forceFill(['state' => 'succeeded', 'next_check_at' => null])->save();

            return $attempt;
        }
        if (! in_array($order->status, ['pending', 'review'], true) || $order->subscription_id !== null) {
            return $this->review($order, $attempt, 'tbank_order_not_fulfillable');
        }
        try {
            app(TBankOrderService::class)->assertCanPurchase($user);
        } catch (HttpException|LegalDocumentsUnavailableException) {
            return $this->review($order, $attempt, 'tbank_access_or_consent_changed');
        }
        app(AccessFreezeService::class)->freezeIfInactive($user, now());
        $plan = app(PlanCatalog::class)->byCode($order->plan_code);
        if ($plan === null) {
            return $this->review($order, $attempt, 'tbank_plan_unavailable');
        }
        // A delayed webhook must not consume the paid period before access is issued.
        $start = now()->startOfSecond();
        $end = app(SubscriptionCatalog::class)->endAt($start, $order->duration_months);
        $subscription = Subscription::query()->create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'source' => SubscriptionSource::TBank,
            'status' => SubscriptionStatus::Active, 'starts_at' => $start, 'ends_at' => $end, 'auto_renew' => false,
        ]);
        Entitlement::query()->create([
            'user_id' => $user->id, 'subscription_id' => $subscription->id, 'plan_id' => $plan->id,
            'code' => 'active_queries', 'value' => $order->terms['active_query_limit'],
            'status' => SubscriptionStatus::Active, 'starts_at' => $start, 'ends_at' => $end,
            'metadata' => ['source' => 'tbank', 'order_id' => $order->id, 'attempt_id' => $attempt->id],
        ]);
        $order->forceFill(['starts_at' => $start, 'ends_at' => $end, 'subscription_id' => $subscription->id])->save();
        app(TBankOrderService::class)->transition($order, 'succeeded');
        $attempt->forceFill(['state' => 'succeeded', 'confirmed_at' => $start, 'next_check_at' => null])->save();

        return $attempt;
    }

    private function assertSnapshot(SubscriptionOrder $order, TBankPaymentAttempt $attempt): void
    {
        if ($order->provider !== 'tbank' || $order->is_demo || $order->currency !== 'RUB'
            || $order->amount_minor !== $attempt->amount_minor || $order->amount_minor <= 0
            || ($attempt->request_payload['Amount'] ?? null) !== $order->amount_minor
            || ($attempt->request_payload['OrderId'] ?? null) !== $order->id
            || ($order->terms['amount_minor'] ?? null) !== $order->amount_minor
            || ($order->terms['code'] ?? null) !== $order->plan_code
            || ($order->terms['duration_months'] ?? null) !== $order->duration_months
            || ($order->terms['auto_renew'] ?? false) !== false
            || ! is_int($order->terms['active_query_limit'] ?? null) || $order->terms['active_query_limit'] < 1
            || ! in_array($order->duration_months, [1, 3, 12], true)) {
            throw new TBankApiException('tbank_order_snapshot_mismatch');
        }
    }

    private function assertTerminal(TBankPaymentAttempt $attempt): void
    {
        if ($attempt->terminal_key !== config('tender.payments.tbank.terminal_key')) {
            throw new TBankApiException('tbank_terminal_mismatch');
        }
    }

    private function allowedPaymentUrl(string $url): bool
    {
        $parts = parse_url($url);

        return strlen($url) <= 2048 && $parts !== false && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'securepay.tinkoff.ru'
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['port']) && ! isset($parts['fragment']);
    }

    private function review(SubscriptionOrder $order, TBankPaymentAttempt $attempt, string $code): TBankPaymentAttempt
    {
        $attempt->forceFill(['state' => 'review', 'failure_code' => $code, 'check_token' => null, 'next_check_at' => null])->save();
        if ($order->subscription_id === null) {
            app(TBankOrderService::class)->transition($order, 'review');
        }

        return $attempt;
    }

    /**
     * @template T
     *
     * @param  callable(User, SubscriptionOrder, TBankPaymentAttempt): T  $action
     * @return T
     */
    private function locked(string $id, callable $action): mixed
    {
        $candidate = TBankPaymentAttempt::query()->findOrFail($id);
        $ownerId = SubscriptionOrder::query()->findOrFail($candidate->order_id)->user_id;

        return DB::transaction(function () use ($id, $candidate, $ownerId, $action): mixed {
            $user = User::query()->lockForUpdate()->findOrFail($ownerId);
            $order = SubscriptionOrder::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($candidate->order_id);
            $attempt = TBankPaymentAttempt::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($id);

            return $action($user, $order, $attempt);
        });
    }
}
