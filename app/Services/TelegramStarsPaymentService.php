<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\QueryStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\Entitlement;
use App\Models\Payment;
use App\Models\SearchQuery;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramStarsPaymentService
{
    public function __construct(
        private readonly PlanCatalog $plans,
        private readonly TelegramBotClient $bot,
    ) {}

    /**
     * Sends a Stars invoice to a private Telegram chat. A user is deliberately
     * required to have a verified Mini App identity before an invoice exists.
     */
    public function issueInvoiceForChat(string $chatId, string $planCode): ?string
    {
        if (! $this->isEnabled()) {
            return 'Оплата временно недоступна: стоимость в Telegram Stars ещё не утверждена.';
        }

        /** @var User|null $user */
        $user = User::query()->where('telegram_id', $chatId)->first();

        if ($user === null) {
            return 'Сначала откройте Tender Finder как Mini App из Telegram, затем повторите команду.';
        }

        $plan = $this->plans->byCode($planCode);

        if ($plan === null || ! $plan->is_active) {
            return 'Этот тариф сейчас недоступен.';
        }

        $amount = $this->priceFor($planCode);

        if ($amount < 1) {
            return 'Для этого тарифа ещё не задана стоимость в Telegram Stars.';
        }

        /** @var Payment $payment */
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'provider' => 'telegram_stars',
            'status' => PaymentStatus::Pending,
            'currency' => 'XTR',
            'amount' => $amount,
            'invoice_payload' => 'tf-stars:'.Str::uuid()->toString(),
            'metadata' => ['plan_code' => $planCode],
        ]);

        try {
            $this->bot->sendStarsInvoice(
                chatId: $chatId,
                title: "Tender Finder — {$plan->name}",
                description: 'Доступ к мониторингу тендеров на 30 дней.',
                payload: $payment->invoice_payload,
                amount: $payment->amount,
                subscriptionPeriodSeconds: $this->subscriptionPeriodSeconds(),
            );
        } catch (\Throwable $exception) {
            $payment->forceFill(['status' => PaymentStatus::Failed])->save();

            throw $exception;
        }

        return null;
    }

    /** @param array<string, mixed> $query */
    public function validatePreCheckout(array $query): ?string
    {
        $payment = $this->paymentForIncomingCharge($query);

        if ($payment === null || $payment->status !== PaymentStatus::Pending) {
            return 'Счёт не найден или уже устарел. Запросите новый через /subscribe.';
        }

        $telegramUserId = data_get($query, 'from.id');

        if ((string) $telegramUserId !== (string) $payment->user->telegram_id) {
            return 'Этот счёт создан для другого пользователя.';
        }

        return null;
    }

    /**
     * Records Telegram's confirmed payment and creates the entitlement exactly
     * once. The Bot API is the source of truth; browser callbacks are ignored.
     *
     * @param  array<string, mixed>  $paymentData
     */
    public function settleSuccessfulPayment(array $paymentData): void
    {
        DB::transaction(function () use ($paymentData): void {
            $incoming = $this->paymentForIncomingCharge($paymentData, true);

            if ($incoming === null) {
                throw new TelegramStarsPaymentException('Unknown Telegram Stars invoice payload.');
            }

            $chargeId = (string) data_get($paymentData, 'telegram_payment_charge_id');

            if ($chargeId === '') {
                throw new TelegramStarsPaymentException('Telegram payment charge ID is missing.');
            }

            if ($incoming->status === PaymentStatus::Paid && $incoming->telegram_payment_charge_id === $chargeId) {
                return;
            }

            $payment = $incoming;

            // Recurring Stars charges reuse the original invoice payload. Keep
            // one immutable accounting record per charge so a renewal extends
            // access but cannot overwrite the first payment.
            if ($incoming->status === PaymentStatus::Paid) {
                $payment = Payment::query()->firstOrCreate(
                    ['telegram_payment_charge_id' => $chargeId],
                    [
                        'user_id' => $incoming->user_id,
                        'plan_id' => $incoming->plan_id,
                        'provider' => 'telegram_stars',
                        'status' => PaymentStatus::Pending,
                        'currency' => $incoming->currency,
                        'amount' => $incoming->amount,
                        'invoice_payload' => 'renewal:'.hash('sha256', $chargeId),
                        'metadata' => ['renewal_of_payment_id' => $incoming->id],
                    ],
                );

                if ($payment->status === PaymentStatus::Paid) {
                    return;
                }
            }

            if ($this->paymentForIncomingCharge($paymentData) === null) {
                throw new TelegramStarsPaymentException('Telegram Stars payment amount does not match the invoice.');
            }

            $payment->forceFill([
                'status' => PaymentStatus::Paid,
                'telegram_payment_charge_id' => $chargeId,
                'provider_payment_charge_id' => data_get($paymentData, 'provider_payment_charge_id'),
                'paid_at' => now(),
                'metadata' => [
                    ...($payment->metadata ?? []),
                    'is_recurring' => (bool) data_get($paymentData, 'is_recurring', false),
                    'is_first_recurring' => (bool) data_get($paymentData, 'is_first_recurring', false),
                ],
            ])->save();

            $this->grantAccess($payment);
        });
    }

    private function grantAccess(Payment $payment): void
    {
        $startsAt = now();
        $endsAt = $startsAt->copy()->addSeconds($this->subscriptionPeriodSeconds());
        $plan = $payment->plan;
        $activeQueryLimit = is_numeric($plan->limits['active_queries'] ?? null)
            ? (int) $plan->limits['active_queries']
            : 0;

        $subscription = Subscription::query()->create([
            'user_id' => $payment->user_id,
            'plan_id' => $plan->id,
            'source' => SubscriptionSource::TelegramStars,
            'status' => SubscriptionStatus::Active,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        Entitlement::query()->create([
            'user_id' => $payment->user_id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'code' => 'active_queries',
            'status' => SubscriptionStatus::Active,
            'value' => $activeQueryLimit,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'metadata' => ['payment_id' => $payment->id],
        ]);

        SearchQuery::query()
            ->where('user_id', $payment->user_id)
            ->where('status', QueryStatus::Frozen)
            ->update([
                'status' => QueryStatus::Active->value,
                'frozen_at' => null,
                'updated_at' => $startsAt,
            ]);
    }

    /** @param array<string, mixed> $data */
    private function paymentForIncomingCharge(array $data, bool $lock = false): ?Payment
    {
        $payload = data_get($data, 'invoice_payload');
        $currency = data_get($data, 'currency');
        $amount = data_get($data, 'total_amount');

        if (! is_string($payload) || $payload === '' || $currency !== 'XTR' || ! is_int($amount) || $amount < 1) {
            return null;
        }

        $query = Payment::query()
            ->with(['user', 'plan'])
            ->where('invoice_payload', $payload)
            ->where('provider', 'telegram_stars')
            ->where('currency', 'XTR')
            ->where('amount', $amount);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function isEnabled(): bool
    {
        return (bool) config('tender.payments.telegram_stars.enabled');
    }

    private function priceFor(string $planCode): int
    {
        return match ($planCode) {
            PlanCatalog::BASIC_CODE => (int) config('tender.payments.telegram_stars.basic_price_xtr'),
            PlanCatalog::PRO_CODE => (int) config('tender.payments.telegram_stars.pro_price_xtr'),
            default => 0,
        };
    }

    private function subscriptionPeriodSeconds(): int
    {
        return max(1, (int) config('tender.payments.telegram_stars.subscription_period_seconds'));
    }
}
