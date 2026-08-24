<?php

namespace App\Services;

use App\Enums\AccessState;
use App\Enums\QueryStatus;
use App\Models\SearchQuery;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SearchQueryService
{
    public function __construct(private readonly AccessService $access) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $user, array $attributes): SearchQuery
    {
        return DB::transaction(function () use ($user, $attributes): SearchQuery {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->assertCanHaveActiveQuery($lockedUser);

            /** @var SearchQuery $query */
            $query = SearchQuery::query()->create([
                ...$attributes,
                'user_id' => $lockedUser->id,
                'status' => QueryStatus::Active,
                'monitoring_started_at' => now(),
            ]);

            return $query;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(SearchQuery $query, array $attributes): SearchQuery
    {
        $query->fill($attributes)->save();

        return $query->refresh();
    }

    public function pause(SearchQuery $query): SearchQuery
    {
        $query->forceFill(['status' => QueryStatus::Paused, 'paused_at' => now()])->save();

        return $query->refresh();
    }

    public function resume(SearchQuery $query): SearchQuery
    {
        return DB::transaction(function () use ($query): SearchQuery {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($query->user_id);
            $this->assertCanHaveActiveQuery($user, $query->id);
            $query->forceFill([
                'status' => QueryStatus::Active,
                'paused_at' => null,
                'frozen_at' => null,
                'monitoring_started_at' => now(),
            ])->save();

            return $query->refresh();
        });
    }

    public function freeze(SearchQuery $query): SearchQuery
    {
        $query->forceFill(['status' => QueryStatus::Frozen, 'frozen_at' => now()])->save();

        return $query->refresh();
    }

    public function delete(SearchQuery $query): void
    {
        $query->forceFill(['status' => QueryStatus::Deleted])->save();
    }

    private function assertCanHaveActiveQuery(User $user, ?int $exceptQueryId = null): void
    {
        $snapshot = $this->access->snapshotFor($user);

        if (! in_array($snapshot->state, [AccessState::Trialing, AccessState::Active], true) || $snapshot->activeQueryLimit === null) {
            throw new QueryAccessDeniedException;
        }

        $activeQueries = SearchQuery::query()
            ->where('user_id', $user->id)
            ->where('status', QueryStatus::Active)
            ->when($exceptQueryId !== null, fn ($builder) => $builder->whereKeyNot($exceptQueryId))
            ->count();

        if ($activeQueries >= $snapshot->activeQueryLimit) {
            throw new QueryLimitReachedException;
        }
    }
}
