<?php

namespace App\Services;

use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupportAccessService
{
    public function __construct(
        private readonly AccessService $access,
        private readonly ConsentService $consents,
        private readonly PlanCatalog $plans,
        private readonly AccessFreezeService $freeze,
    ) {}

    public function grantBlockReason(User $user): ?string
    {
        if ($this->access->hasActiveAccess($user)) {
            return 'У пользователя уже есть активный доступ.';
        }
        if (! (bool) config('tender.legal.documents_published', false)) {
            return 'Юридические документы ещё не опубликованы.';
        }
        try {
            $hasConsents = $this->consents->hasCurrentRequiredConsents($user);
        } catch (LegalDocumentsUnavailableException) {
            return 'Юридические документы ещё не опубликованы.';
        }
        if (! $hasConsents) {
            return 'Пользователь сначала должен принять действующие оферту и политику.';
        }

        return null;
    }

    public function activeGrantFor(User $user): ?Entitlement
    {
        return Entitlement::query()
            ->with(['subscription', 'plan'])
            ->where('user_id', $user->id)
            ->where('code', 'active_queries')
            ->where('status', SubscriptionStatus::Active)
            ->where('starts_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->whereHas('subscription', fn ($query) => $query->where('source', SubscriptionSource::AdminGrant))
            ->latest('id')
            ->first();
    }

    public function grant(SupportTicket $ticket, User $actor, int $days, string $reason): void
    {
        if ($days < 1 || $days > 7 || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите срок от 1 до 7 дней и причину от 10 до 500 символов.']);
        }

        DB::transaction(function () use ($ticket, $actor, $days, $reason): void {
            /** @var SupportTicket $lockedTicket */
            $lockedTicket = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($lockedTicket->user_id);

            $blockReason = $this->grantBlockReason($user);
            if ($blockReason !== null) {
                throw ValidationException::withMessages(['days' => $blockReason]);
            }

            $startsAt = now();
            $endsAt = $startsAt->copy()->addDays($days);
            $plan = $this->plans->basic();
            $subscription = Subscription::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'source' => SubscriptionSource::AdminGrant,
                'status' => SubscriptionStatus::Active,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
            $entitlement = Entitlement::query()->create([
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'code' => 'active_queries',
                'status' => SubscriptionStatus::Active,
                'value' => (int) ($plan->limits['active_queries'] ?? 0),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'metadata' => ['source' => SubscriptionSource::AdminGrant->value],
            ]);
            $lockedTicket->events()->create([
                'actor_id' => $actor->id,
                'action' => 'access_granted',
                'reason' => $reason,
                'access_entitlement_id' => $entitlement->id,
                'access_plan_code' => $plan->code,
                'access_ends_at' => $endsAt,
                'created_at' => $startsAt,
            ]);
            $lockedTicket->touch();
        });
    }

    public function revoke(SupportTicket $ticket, User $actor, string $reason): void
    {
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Причина должна содержать от 10 до 500 символов.']);
        }

        DB::transaction(function () use ($ticket, $actor, $reason): void {
            /** @var SupportTicket $lockedTicket */
            $lockedTicket = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($lockedTicket->user_id);
            $entitlement = $this->activeGrantFor($user);
            if ($entitlement === null) {
                throw ValidationException::withMessages(['reason' => 'Активного ручного доступа нет.']);
            }

            $now = now();
            $originalEnd = $entitlement->ends_at;
            $entitlement->forceFill([
                'status' => SubscriptionStatus::Cancelled,
                'ends_at' => $now,
            ])->save();
            $entitlement->subscription?->forceFill([
                'status' => SubscriptionStatus::Cancelled,
                'ends_at' => $now,
                'cancelled_at' => $now,
            ])->save();
            $lockedTicket->events()->create([
                'actor_id' => $actor->id,
                'action' => 'access_revoked',
                'reason' => $reason,
                'access_entitlement_id' => $entitlement->id,
                'access_plan_code' => $entitlement->plan?->code,
                'access_ends_at' => $originalEnd,
                'created_at' => $now,
            ]);
            $lockedTicket->touch();

            $this->freeze->freezeIfInactive($user, $now);
        });
    }
}
