<?php

namespace App\Services;

use App\Enums\AccessState;
use App\Enums\PaymentStatus;
use App\Enums\QueryStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\Entitlement;
use App\Models\Payment;
use App\Models\SearchQuery;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramStarsPaymentService
{
    public const PERIOD_SECONDS = 2_592_000;

    public function __construct(
        private readonly PlanCatalog $plans,
        private readonly TelegramBotClient $bot,
        private readonly ConsentService $consents,
        private readonly AccessFreezeService $freeze,
        private readonly AccessService $access,
    ) {}

    /** @return array{enabled: bool, prices: array{basic: int, pro: int}, period_seconds: int} */
    public function configuration(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'prices' => ['basic' => $this->priceFor('basic'), 'pro' => $this->priceFor('pro')],
            'period_seconds' => self::PERIOD_SECONDS,
        ];
    }

    public function createSubscriptionInvoice(User $user, string $planCode): string
    {
        if (! $this->isEnabled()) {
            throw new TelegramStarsPaymentException('Оплата пока не включена.');
        }
        if (! $this->consents->hasCurrentRequiredConsents($user)) {
            throw new TelegramStarsPaymentException('Сначала примите опубликованные условия и политику.');
        }
        if (! is_string($user->telegram_id) || preg_match('/^\d+$/', $user->telegram_id) !== 1) {
            throw new TelegramStarsPaymentException('Откройте приложение через Telegram, чтобы оплатить подписку.');
        }
        $access = $this->access->snapshotFor($user);
        if ($access->state === AccessState::Active && $access->endsAt === null) {
            throw new TelegramStarsPaymentException('У вас уже есть бессрочный доступ. Оплата не требуется.');
        }

        $plan = $this->plans->byCode($planCode);
        $amount = $this->priceFor($planCode);
        if ($plan === null || ! $plan->is_active || $amount < 1 || $amount > 10_000) {
            throw new TelegramStarsPaymentException('Стоимость этого тарифа ещё не утверждена.');
        }

        /** @var Payment $payment */
        $payment = DB::transaction(function () use ($user, $plan, $planCode, $amount): Payment {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (Subscription::query()->where('user_id', $user->id)
                ->where('source', SubscriptionSource::TelegramStars)
                ->where('status', SubscriptionStatus::Active)
                ->where('ends_at', '>', now())->exists()) {
                throw new TelegramStarsPaymentException('У вас уже есть оплаченный период. Дождитесь его окончания.');
            }

            Payment::query()->where('user_id', $user->id)
                ->where('provider', 'telegram_stars')->where('status', PaymentStatus::Pending)
                ->update(['status' => PaymentStatus::Failed->value]);

            return Payment::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'provider' => 'telegram_stars',
                'status' => PaymentStatus::Pending,
                'currency' => 'XTR',
                'amount' => $amount,
                'invoice_payload' => 'tf-stars:'.Str::uuid(),
                'invoice_expires_at' => now()->addMinutes(15),
                'metadata' => ['plan_code' => $planCode, 'recurring' => true],
            ]);
        });

        try {
            return $this->bot->createStarsSubscriptionInvoiceLink(
                "Tender Finder — {$plan->name}",
                'Подписка на мониторинг тендеров: каждые 30 дней.',
                $payment->invoice_payload,
                $amount,
            );
        } catch (\Throwable $exception) {
            $payment->forceFill(['status' => PaymentStatus::Failed])->save();
            throw $exception;
        }
    }

    /** Legacy bot command uses the same checkout as the Mini App. */
    public function issueInvoiceForChat(string $chatId, string $planCode): ?string
    {
        /** @var User|null $user */
        $user = User::query()->where('telegram_id', $chatId)->first();
        if ($user === null) {
            return 'Сначала откройте Tender Finder как Mini App из Telegram.';
        }
        try {
            $url = $this->createSubscriptionInvoice($user, $planCode);
        } catch (TelegramStarsPaymentException $exception) {
            return $exception->getMessage();
        }
        $this->bot->sendMessage($chatId, 'Подписка Tender Finder: '.$url);

        return null;
    }

    /** @param array<string, mixed> $query */
    public function validatePreCheckout(array $query): ?string
    {
        $payment = $this->paymentForIncomingCharge($query);
        if ($payment === null || $payment->status !== PaymentStatus::Pending
            || $payment->invoice_expires_at === null || $payment->invoice_expires_at->lte(now())) {
            return 'Счёт не найден или устарел. Откройте приложение и запросите новый.';
        }
        if ((string) data_get($query, 'from.id') !== (string) $payment->user->telegram_id) {
            return 'Этот счёт создан для другого пользователя.';
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    public function settleSuccessfulPayment(array $data): void
    {
        DB::transaction(function () use ($data): void {
            $original = $this->paymentForIncomingCharge($data, true);
            $chargeId = data_get($data, 'telegram_payment_charge_id');
            $expiration = data_get($data, 'subscription_expiration_date');
            if ($original === null || ! is_string($chargeId) || $chargeId === ''
                || ! is_int($expiration) || $expiration <= now()->timestamp
                || data_get($data, 'is_recurring') !== true) {
                throw new TelegramStarsPaymentException('Неполное или неизвестное подтверждение Telegram Stars.');
            }
            if ($original->telegram_payment_charge_id === $chargeId && $original->status === PaymentStatus::Paid) {
                return;
            }

            $endsAt = Carbon::createFromTimestampUTC($expiration);
            $payment = $original;
            if (! in_array($original->status, [PaymentStatus::Pending, PaymentStatus::Failed], true)) {
                $payment = Payment::query()->firstOrCreate(
                    ['telegram_payment_charge_id' => $chargeId],
                    [
                        'user_id' => $original->user_id,
                        'plan_id' => $original->plan_id,
                        'subscription_id' => $original->subscription_id,
                        'provider' => 'telegram_stars',
                        'status' => PaymentStatus::Pending,
                        'currency' => 'XTR',
                        'amount' => $original->amount,
                        'invoice_payload' => 'renewal:'.hash('sha256', $chargeId),
                        'metadata' => ['renewal_of_payment_id' => $original->id],
                    ],
                );
                if (in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Refunded], true)) {
                    return;
                }
            }

            $subscription = $original->subscription_id === null
                ? $this->startSubscription($original, $chargeId, $endsAt)
                : Subscription::query()->lockForUpdate()->findOrFail($original->subscription_id);
            if ($subscription->user_id !== $original->user_id || $subscription->plan_id !== $original->plan_id) {
                throw new TelegramStarsPaymentException('Подписка не соответствует счёту.');
            }
            if ($subscription->ends_at === null || $endsAt->gt($subscription->ends_at)) {
                $subscription->forceFill([
                    'status' => SubscriptionStatus::Active,
                    'ends_at' => $endsAt,
                    'renewal_failed_at' => null,
                ])->save();
                Entitlement::query()->where('subscription_id', $subscription->id)
                    ->where('code', 'active_queries')
                    ->update(['status' => SubscriptionStatus::Active->value, 'ends_at' => $endsAt]);
            }
            $payment->forceFill([
                'subscription_id' => $subscription->id,
                'status' => PaymentStatus::Paid,
                'telegram_payment_charge_id' => $chargeId,
                'provider_payment_charge_id' => data_get($data, 'provider_payment_charge_id'),
                'paid_at' => now(),
                'period_ends_at' => $endsAt,
                'metadata' => [
                    ...($payment->metadata ?? []),
                    'is_recurring' => true,
                    'is_first_recurring' => data_get($data, 'is_first_recurring') === true,
                ],
            ])->save();

            SearchQuery::query()->where('user_id', $payment->user_id)
                ->where('status', QueryStatus::Frozen)
                ->update(['status' => QueryStatus::Active->value, 'frozen_at' => null, 'updated_at' => now()]);
        });
    }

    /** @param array<string, mixed> $data */
    public function recordRefund(array $data): void
    {
        DB::transaction(function () use ($data): void {
            $chargeId = data_get($data, 'telegram_payment_charge_id');
            if (! is_string($chargeId) || $chargeId === '' || data_get($data, 'currency') !== 'XTR') {
                throw new TelegramStarsPaymentException('Неверное уведомление о возврате Stars.');
            }
            /** @var Payment|null $payment */
            $payment = Payment::query()->where('telegram_payment_charge_id', $chargeId)->lockForUpdate()->first();
            if ($payment === null || $payment->amount !== data_get($data, 'total_amount')
                || $payment->status === PaymentStatus::Pending) {
                throw new TelegramStarsPaymentException('Возврат не соответствует платёжной записи.');
            }
            if ($payment->status === PaymentStatus::Refunded) {
                return;
            }
            $payment->forceFill(['status' => PaymentStatus::Refunded, 'refunded_at' => now()])->save();
            $this->recalculateAfterRefund($payment);
        });
    }

    /** @param array<string, mixed> $data */
    public function recordSubscriptionUpdate(array $data): void
    {
        $payload = data_get($data, 'invoice_payload');
        $state = data_get($data, 'state');
        if (! is_string($payload) || ! in_array($state, ['active', 'canceled', 'failed'], true)) {
            throw new TelegramStarsPaymentException('Неверное событие подписки Telegram.');
        }
        /** @var Payment|null $first */
        $first = Payment::query()->with(['user', 'subscription'])
            ->where('invoice_payload', $payload)->whereNotNull('subscription_id')->first();
        if ($first === null || (string) data_get($data, 'user.id') !== (string) $first->user->telegram_id
            || $first->subscription === null) {
            throw new TelegramStarsPaymentException('Событие относится к другой подписке.');
        }
        $first->subscription->forceFill(match ($state) {
            'active' => ['auto_renew' => true, 'cancelled_at' => null, 'renewal_failed_at' => null],
            'canceled' => ['auto_renew' => false, 'cancelled_at' => now()],
            'failed' => ['renewal_failed_at' => now()],
        })->save();
    }

    public function setAutoRenew(User $user, Subscription $subscription, bool $cancel): void
    {
        if ($subscription->user_id !== $user->id || $subscription->source !== SubscriptionSource::TelegramStars
            || $subscription->telegram_initial_charge_id === null || $subscription->ends_at?->lte(now())) {
            throw new TelegramStarsPaymentException('Активная подписка не найдена.');
        }
        if ($subscription->auto_renew === ! $cancel) {
            return;
        }
        $this->bot->editUserStarSubscription((string) $user->telegram_id, $subscription->telegram_initial_charge_id, $cancel);
        $subscription->forceFill(['auto_renew' => ! $cancel, 'cancelled_at' => $cancel ? now() : null])->save();
    }

    public function refund(Payment $payment): void
    {
        if ($payment->provider !== 'telegram_stars' || $payment->status !== PaymentStatus::Paid
            || $payment->telegram_payment_charge_id === null || $payment->user->telegram_id === null) {
            throw new TelegramStarsPaymentException('Платёж нельзя вернуть через Telegram Stars.');
        }
        $this->bot->refundStarPayment($payment->user->telegram_id, $payment->telegram_payment_charge_id);
        $this->recordRefund([
            'telegram_payment_charge_id' => $payment->telegram_payment_charge_id,
            'currency' => 'XTR',
            'total_amount' => $payment->amount,
        ]);
    }

    private function startSubscription(Payment $payment, string $chargeId, Carbon $endsAt): Subscription
    {
        /** @var Subscription $subscription */
        $subscription = Subscription::query()->create([
            'user_id' => $payment->user_id,
            'plan_id' => $payment->plan_id,
            'source' => SubscriptionSource::TelegramStars,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
            'ends_at' => $endsAt,
            'telegram_initial_charge_id' => $chargeId,
            'auto_renew' => true,
        ]);
        $limit = $payment->plan->limits['active_queries'] ?? 0;
        Entitlement::query()->create([
            'user_id' => $payment->user_id,
            'subscription_id' => $subscription->id,
            'plan_id' => $payment->plan_id,
            'code' => 'active_queries',
            'status' => SubscriptionStatus::Active,
            'value' => is_numeric($limit) ? (int) $limit : 0,
            'starts_at' => now(),
            'ends_at' => $endsAt,
            'metadata' => ['payment_id' => $payment->id],
        ]);

        return $subscription;
    }

    private function recalculateAfterRefund(Payment $payment): void
    {
        if ($payment->subscription_id === null) {
            return;
        }
        /** @var Subscription $subscription */
        $subscription = Subscription::query()->lockForUpdate()->findOrFail($payment->subscription_id);
        $latestEnd = Payment::query()->where('subscription_id', $subscription->id)
            ->where('status', PaymentStatus::Paid)->max('period_ends_at');
        $endsAt = $latestEnd === null ? now() : Carbon::parse($latestEnd);
        $active = $endsAt->gt(now());
        $subscription->forceFill([
            'status' => $active ? SubscriptionStatus::Active : SubscriptionStatus::Expired,
            'ends_at' => $endsAt,
            'auto_renew' => $active && $subscription->auto_renew,
        ])->save();
        Entitlement::query()->where('subscription_id', $subscription->id)
            ->update(['status' => $active ? SubscriptionStatus::Active->value : SubscriptionStatus::Expired->value, 'ends_at' => $endsAt]);
        if (! $active) {
            $this->freeze->freezeIfInactive($subscription->user, now());
        }
    }

    /** @param array<string, mixed> $data */
    private function paymentForIncomingCharge(array $data, bool $lock = false): ?Payment
    {
        $payload = data_get($data, 'invoice_payload');
        $amount = data_get($data, 'total_amount');
        if (! is_string($payload) || $payload === '' || data_get($data, 'currency') !== 'XTR'
            || ! is_int($amount) || $amount < 1) {
            return null;
        }
        $query = Payment::query()->with(['user', 'plan'])
            ->where('invoice_payload', $payload)->where('provider', 'telegram_stars')
            ->where('currency', 'XTR')->where('amount', $amount);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function isEnabled(): bool
    {
        return (bool) config('tender.payments.telegram_stars.enabled')
            && (bool) config('tender.legal.documents_published')
            && (int) config('tender.payments.telegram_stars.subscription_period_seconds') === self::PERIOD_SECONDS
            && $this->priceFor('basic') >= 1 && $this->priceFor('basic') <= 10_000
            && $this->priceFor('pro') >= 1 && $this->priceFor('pro') <= 10_000
            && collect(['offer_url', 'offer_version', 'privacy_url', 'privacy_version'])
                ->every(fn (string $key): bool => is_string(config("tender.legal.{$key}"))
                    && config("tender.legal.{$key}") !== '');
    }

    private function priceFor(string $planCode): int
    {
        return match ($planCode) {
            PlanCatalog::BASIC_CODE => (int) config('tender.payments.telegram_stars.basic_price_xtr'),
            PlanCatalog::PRO_CODE => (int) config('tender.payments.telegram_stars.pro_price_xtr'),
            default => 0,
        };
    }
}
