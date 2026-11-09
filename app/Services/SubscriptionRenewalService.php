<?php

namespace App\Services;

use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionOrder;
use App\Models\SubscriptionRenewal;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Telegram\TelegramDeliveryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

final class SubscriptionRenewalService
{
    public const CONSENT_VERSION = 'auto-renew-2026-11-02-v1';

    /** The real provider must verify succeeded through its API before calling this boundary. */
    public function registerConfirmed(SubscriptionOrder $order): ?SubscriptionRenewal
    {
        if (($order->terms['auto_renew'] ?? false) !== true) {
            return null;
        }

        return DB::transaction(function () use ($order): SubscriptionRenewal {
            User::query()->whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            $order = SubscriptionOrder::query()->lockForUpdate()->findOrFail($order->id);
            $subscription = Subscription::query()->whereKey($order->subscription_id)->lockForUpdate()->firstOrFail();
            abort_unless($order->status === 'succeeded' && $order->user_id === $subscription->user_id
                && $subscription->status === SubscriptionStatus::Active && $subscription->auto_renew
                && (($order->is_demo && $order->provider === 'demo' && $subscription->source === SubscriptionSource::Demo)
                    || (! $order->is_demo && $order->provider === 'yookassa' && $subscription->source === SubscriptionSource::YooKassa)), 409);
            abort_unless(($order->terms['auto_renew_consent_version'] ?? null) === self::CONSENT_VERSION
                && is_string($order->terms['auto_renew_consented_at'] ?? null)
                && ($order->terms['auto_renew_consent_provider'] ?? null) === $order->provider, 409, 'Нет отдельного согласия на автопродление.');
            abort_unless($order->currency === 'RUB' && $order->amount_minor > 0
                && in_array($order->duration_months, [1, 3, 12], true)
                && ($order->terms['amount_minor'] ?? null) === $order->amount_minor, 409);

            $renewal = SubscriptionRenewal::query()->firstOrCreate(['subscription_id' => $subscription->id], [
                'id' => (string) Str::uuid(), 'user_id' => $order->user_id, 'provider' => $order->provider,
                'plan_code' => $order->plan_code, 'duration_months' => $order->duration_months,
                'amount_minor' => $order->amount_minor, 'currency' => $order->currency,
                'consent_version' => self::CONSENT_VERSION, 'consented_at' => $order->terms['auto_renew_consented_at'],
                'next_charge_at' => $order->ends_at, 'notice_due_at' => $order->ends_at->copy()->subHours(24)->subMinutes(5),
            ]);
            if ($renewal->wasRecentlyCreated) {
                $this->event($renewal, 'enabled');
            }

            return $renewal;
        });
    }

    public function cancel(SubscriptionRenewal $renewal): SubscriptionRenewal
    {
        return DB::transaction(function () use ($renewal): SubscriptionRenewal {
            User::query()->whereKey($renewal->user_id)->lockForUpdate()->firstOrFail();
            $renewal = SubscriptionRenewal::query()->lockForUpdate()->findOrFail($renewal->id);
            // Repair the flag even on an idempotent retry, without shortening paid access.
            $renewal->subscription()->where('user_id', $renewal->user_id)->lockForUpdate()->first()?->forceFill(['auto_renew' => false])->save();
            if ($renewal->state === 'cancelled') {
                if ($renewal->retry_at !== null || $renewal->charge_not_before !== null) {
                    $renewal->forceFill(['retry_at' => null, 'charge_not_before' => null])->save();
                }

                return $renewal;
            }
            $renewal->forceFill(['state' => 'cancelled', 'cancelled_at' => now(), 'retry_at' => null, 'charge_not_before' => null])->save();
            $this->event($renewal, 'cancelled');

            return $renewal;
        });
    }

    /**
     * Commit cancellation before replying to Telegram. A lost reply must not
     * cancel a new consent when the same update/job is retried later.
     *
     * @return array{outcome: string, cancelled_count: int}
     */
    public function cancelFromTelegram(TelegramUpdate $update, ?string $actor, string $chatId): array
    {
        return DB::transaction(function () use ($update, $actor, $chatId): array {
            $privateActor = $actor !== null && preg_match('/\A[1-9]\d*\z/D', $actor) === 1 && $actor === $chatId;
            $user = $privateActor ? User::query()->where('telegram_id', $actor)->lockForUpdate()->first() : null;
            $update = TelegramUpdate::query()->lockForUpdate()->findOrFail($update->id);
            if ($update->command_result !== null) {
                return $update->command_result;
            }

            $ids = collect();
            if ($user !== null) {
                $items = SubscriptionRenewal::query()->where('user_id', $user->id)->where('state', 'active')->orderBy('id')->get();
                foreach ($items as $item) {
                    $this->cancel($item);
                    $ids->push($item->subscription_id);
                }
                // Stop future recurring use even if registration of its schedule failed.
                $subscriptions = Subscription::query()->where('user_id', $user->id)->where('auto_renew', true)
                    ->whereIn('source', [SubscriptionSource::YooKassa, SubscriptionSource::TBank, SubscriptionSource::Demo])->orderBy('id')->lockForUpdate()->get();
                foreach ($subscriptions as $subscription) {
                    $subscription->forceFill(['auto_renew' => false])->save();
                    $ids->push($subscription->id);
                }
            }

            $result = ['outcome' => $privateActor ? ($ids->isEmpty() ? 'none' : 'cancelled') : 'private_chat_required',
                'cancelled_count' => $ids->unique()->count()];
            $update->forceFill(['command_result' => $result])->save();

            return $result;
        });
    }

    public function cancelUrl(SubscriptionRenewal $renewal): string
    {
        return URL::signedRoute('renewals.cancel-link', ['renewal' => $renewal->id]);
    }

    /** @return array<string, mixed> */
    public function present(SubscriptionRenewal $renewal): array
    {
        return [
            'id' => $renewal->id, 'plan_code' => $renewal->plan_code, 'provider' => $renewal->provider,
            'amount_minor' => $renewal->amount_minor, 'duration_months' => $renewal->duration_months,
            'next_charge_at' => ($renewal->charge_not_before ?? $renewal->next_charge_at)->toAtomString(),
            'state' => $renewal->state, 'notice_status' => $renewal->notice_status,
            'notice_sent_at' => $renewal->notice_sent_at?->toAtomString(), 'cancel_url' => $this->cancelUrl($renewal),
        ];
    }

    public function processDue(): int
    {
        $count = 0;
        $ids = SubscriptionRenewal::query()->where('state', 'active')->whereNull('notice_sent_at')
            ->where('notice_due_at', '<=', now())->where(fn ($q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', now()))
            ->orderBy('notice_due_at')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            $count += (int) $this->deliver((string) $id);
        }

        return $count;
    }

    private function deliver(string $id): bool
    {
        $candidate = SubscriptionRenewal::query()->find($id);
        if ($candidate === null) {
            return false;
        }

        return DB::transaction(function () use ($candidate): bool {
            $user = User::query()->whereKey($candidate->user_id)->lockForUpdate()->first();
            $renewal = SubscriptionRenewal::query()->whereKey($candidate->id)->lockForUpdate()->first();
            if ($user === null || $renewal === null || $renewal->state !== 'active' || $renewal->notice_sent_at !== null
                || $renewal->notice_due_at->isFuture() || ($renewal->retry_at !== null && $renewal->retry_at->isFuture())) {
                return false;
            }
            $subscription = $renewal->subscription()->lockForUpdate()->first();
            if ($subscription !== null && ! in_array($subscription->source, [SubscriptionSource::Demo, SubscriptionSource::YooKassa], true)) {
                $renewal->forceFill(['notice_status' => 'blocked', 'failure_code' => 'unsupported_subscription_source', 'retry_at' => now()->addHours(6)])->save();

                return false;
            }
            if ($subscription === null || ! $subscription->auto_renew || $subscription->user_id !== $user->id
                || $subscription->status === SubscriptionStatus::Cancelled) {
                $this->cancel($renewal);

                return false;
            }
            $demo = $renewal->provider === 'demo' && $subscription->source === SubscriptionSource::Demo && $user->is_demo_guest;
            if (! $demo && ($renewal->provider !== 'yookassa' || $subscription->source !== SubscriptionSource::YooKassa || $user->is_demo_guest)) {
                return false;
            }
            // A delayed scheduler or retry must never leave less than 24h to cancel.
            $effectiveCharge = $renewal->next_charge_at->max(now()->addHours(24)->addMinutes(5));
            try {
                if (! $demo) {
                    if ($user->telegram_id === null) {
                        throw new TelegramDeliveryException('telegram_chat_unavailable', false);
                    }
                    $amount = number_format($renewal->amount_minor / 100, 2, ',', ' ');
                    $when = $effectiveCharge->copy()->timezone('Europe/Moscow')->format('d.m.Y H:i');
                    $url = $this->cancelUrl($renewal);
                    $text = "Предстоящее автопродление TenderFinder\nТариф: ".ucfirst($renewal->plan_code)."\nСумма: {$amount} ₽ за {$renewal->duration_months} мес.\nСписание запланировано на {$when} МСК, не ранее чем через 24 часа после уведомления.\nОтмена автопродления: {$url}\nПосле отмены оплаченный доступ сохранится до конца срока.";
                    app(TelegramBotClient::class)->sendMessage($user->telegram_id, $text, [], [
                        'inline_keyboard' => [[['text' => 'Отменить автопродление', 'url' => $url]]],
                    ]);
                }
                $renewal->forceFill([
                    'notice_status' => $demo ? 'demo_recorded' : 'sent', 'notice_sent_at' => now(),
                    'charge_not_before' => $effectiveCharge->max(now()->addHours(24)),
                    'failure_code' => null, 'retry_at' => null,
                ])->save();
                $this->event($renewal, $demo ? 'demo_notice' : 'notice_sent');

                return true;
            } catch (TelegramDeliveryException $exception) {
                $renewal->forceFill([
                    'notice_status' => 'failed', 'attempts' => $renewal->attempts + 1,
                    'failure_code' => $exception->failureCode,
                    'retry_at' => now()->addMinutes($exception->retryable ? 5 : 60),
                ])->save();

                return false;
            }
        });
    }

    /** Read-only diagnostic; payment creation uses withChargeLock instead. */
    public function readyForCharge(SubscriptionRenewal $renewal): bool
    {
        $renewal->refresh();

        return $this->chargeAllowed($renewal, $renewal->subscription);
    }

    /**
     * Serialize the final provider request with every cancellation path.
     * No scheduler or charge worker is activated by this boundary.
     *
     * @param  callable(SubscriptionRenewal): array<string, mixed>  $charge
     * @return array<string, mixed>|null
     */
    public function withChargeLock(SubscriptionRenewal $candidate, callable $charge): ?array
    {
        return DB::transaction(function () use ($candidate, $charge): ?array {
            $user = User::query()->whereKey($candidate->user_id)->lockForUpdate()->first();
            $renewal = SubscriptionRenewal::query()->whereKey($candidate->id)->where('user_id', $candidate->user_id)->lockForUpdate()->first();
            if ($user === null || $user->is_demo_guest || $user->telegram_id === null || $renewal === null) {
                return null;
            }
            $subscription = $renewal->subscription()->lockForUpdate()->first();
            if (! $this->chargeAllowed($renewal, $subscription)) {
                return null;
            }

            return $charge($renewal);
        });
    }

    private function chargeAllowed(SubscriptionRenewal $renewal, ?Subscription $subscription): bool
    {
        if ($subscription === null) {
            return false;
        }

        return $renewal->provider === 'yookassa' && $renewal->state === 'active'
            && $renewal->consent_version === self::CONSENT_VERSION
            && $renewal->currency === 'RUB' && $renewal->amount_minor > 0
            && $renewal->next_charge_at->lte(now())
            && $renewal->notice_status === 'sent' && $renewal->notice_sent_at !== null
            && $renewal->notice_sent_at->copy()->addHours(24)->lte(now())
            && $renewal->charge_not_before !== null && $renewal->charge_not_before->lte(now())
            && $subscription->user_id === $renewal->user_id && $subscription->auto_renew
            && $subscription->source === SubscriptionSource::YooKassa
            && $subscription->status !== SubscriptionStatus::Cancelled;
    }

    private function event(SubscriptionRenewal $renewal, string $action): void
    {
        DB::table('subscription_renewal_events')->insert([
            'renewal_id' => $renewal->id, 'action' => $action,
            'snapshot' => json_encode($renewal->getAttributes(), JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
