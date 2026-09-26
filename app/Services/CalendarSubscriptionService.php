<?php

namespace App\Services;

use App\Models\CalendarSubscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CalendarSubscriptionService
{
    public function current(User $user, ?Team $team): ?CalendarSubscription
    {
        return CalendarSubscription::query()->where('user_id', $user->id)
            ->when($team, fn ($query) => $query->where('team_id', $team->id), fn ($query) => $query->whereNull('team_id'))
            ->whereNull('revoked_at')->latest('id')->first();
    }

    /** @return array{active: bool, url: string|null, created_at: string|null} */
    public function present(User $user, ?Team $team): array
    {
        $subscription = $this->current($user, $team);

        return [
            'active' => $subscription !== null,
            'url' => $subscription === null ? null : route('calendar.subscription.feed', ['token' => $subscription->token]),
            'created_at' => $subscription?->created_at?->toAtomString(),
        ];
    }

    public function rotate(User $user, ?Team $team): CalendarSubscription
    {
        return DB::transaction(function () use ($user, $team): CalendarSubscription {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            CalendarSubscription::query()->where('user_id', $user->id)
                ->when($team, fn ($query) => $query->where('team_id', $team->id), fn ($query) => $query->whereNull('team_id'))
                ->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            $token = Str::random(64);

            return CalendarSubscription::query()->create([
                'user_id' => $user->id,
                'team_id' => $team?->id,
                'token_hash' => hash('sha256', $token),
                'token' => $token,
            ]);
        });
    }

    public function revoke(User $user, ?Team $team): void
    {
        CalendarSubscription::query()->where('user_id', $user->id)
            ->when($team, fn ($query) => $query->where('team_id', $team->id), fn ($query) => $query->whereNull('team_id'))
            ->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    public function resolve(string $token): CalendarSubscription
    {
        $subscription = CalendarSubscription::query()->with(['user', 'team'])
            ->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->firstOrFail();
        if ($subscription->team !== null) {
            app(TeamWorkspaceService::class)->authorize($subscription->user, $subscription->team);
            abort_if($subscription->team->archived_at !== null, 404);
        }
        if ($subscription->last_used_at === null || $subscription->last_used_at->lt(now()->subMinutes(10))) {
            $subscription->update(['last_used_at' => now()]);
        }

        return $subscription;
    }
}
