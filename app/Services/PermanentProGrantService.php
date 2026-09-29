<?php

namespace App\Services;

use App\Enums\QueryStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\SearchQuery;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PermanentProGrantService
{
    public function __construct(
        private readonly SupportAccessService $supportAccess,
        private readonly PlanCatalog $plans,
        private readonly AccessChangeNotificationService $notifier,
    ) {}

    public function grant(string $telegramId, bool $developerMode, string $reason): Entitlement
    {
        if (preg_match('/^\d+$/', $telegramId) !== 1 || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите Telegram ID и причину от 10 до 500 символов.']);
        }

        return DB::transaction(function () use ($telegramId, $developerMode, $reason): Entitlement {
            /** @var User|null $user */
            $user = User::query()->where('telegram_id', $telegramId)->lockForUpdate()->first();
            if ($user === null || $user->role !== UserRole::Subscriber) {
                throw ValidationException::withMessages(['telegram_id' => 'Подписчик с таким Telegram ID не найден.']);
            }

            $blockReason = $this->supportAccess->grantBlockReason($user);
            if ($blockReason !== null) {
                throw ValidationException::withMessages(['telegram_id' => $blockReason]);
            }

            $startsAt = now();
            $plan = $this->plans->pro();
            $subscription = Subscription::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'source' => SubscriptionSource::AdminGrant,
                'status' => SubscriptionStatus::Active,
                'starts_at' => $startsAt,
                'ends_at' => null,
            ]);
            $entitlement = Entitlement::query()->create([
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'code' => 'active_queries',
                'status' => SubscriptionStatus::Active,
                'value' => (int) ($plan->limits['active_queries'] ?? 0),
                'starts_at' => $startsAt,
                'ends_at' => null,
                'metadata' => [
                    'source' => SubscriptionSource::AdminGrant->value,
                    'developer_mode' => $developerMode,
                ],
            ]);

            $label = $developerMode ? 'Про (Developer Mode)' : 'Про';
            $ticket = SupportTicket::query()->create([
                'user_id' => $user->id,
                'category' => 'access',
                'status' => 'resolved',
            ]);
            $ticket->messages()->create([
                'author_id' => null,
                'is_staff' => true,
                'body' => "По запросу владельца для проверки приложения включён тариф {$label} без даты окончания. Роль аккаунта остаётся обычной.",
                'created_at' => $startsAt,
            ]);
            $event = $ticket->events()->create([
                'actor_id' => null,
                'action' => 'access_granted',
                'reason' => $reason,
                'access_entitlement_id' => $entitlement->id,
                'access_plan_code' => $plan->code,
                'access_ends_at' => null,
                'created_at' => $startsAt,
            ]);

            SearchQuery::query()
                ->where('user_id', $user->id)
                ->where('status', QueryStatus::Frozen)
                ->update([
                    'status' => QueryStatus::Active->value,
                    'frozen_at' => null,
                    'updated_at' => $startsAt,
                ]);

            $this->notifier->granted($entitlement, $event->id);

            return $entitlement;
        });
    }
}
