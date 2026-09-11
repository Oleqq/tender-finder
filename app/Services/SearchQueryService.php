<?php

namespace App\Services;

use App\Enums\AccessState;
use App\Enums\QueryStatus;
use App\Models\SearchQuery;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SearchQueryService
{
    public function __construct(
        private readonly AccessService $access,
        private readonly RostenderTemplateFeedService $rostenderFeeds,
    ) {}

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

            $this->rostenderFeeds->synchronize($query, $this->rostenderTemplateId($query));

            return $query;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(SearchQuery $query, array $attributes): SearchQuery
    {
        return DB::transaction(function () use ($query, $attributes): SearchQuery {
            $query = SearchQuery::query()->lockForUpdate()->findOrFail($query->id);
            if (isset($attributes['filters'])) {
                $attributes['filters'] = array_replace($query->filters ?? [], $attributes['filters']);
            }
            $query->fill($attributes)->save();
            $this->rostenderFeeds->synchronize($query, $this->rostenderTemplateId($query));

            return $query->refresh();
        });
    }

    public function pause(SearchQuery $query): SearchQuery
    {
        $query->forceFill(['status' => QueryStatus::Paused, 'paused_at' => now()])->save();
        $this->rostenderFeeds->refreshFor($query);

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
            $this->rostenderFeeds->refreshFor($query);

            return $query->refresh();
        });
    }

    public function freeze(SearchQuery $query): SearchQuery
    {
        $query->forceFill(['status' => QueryStatus::Frozen, 'frozen_at' => now()])->save();
        $this->rostenderFeeds->refreshFor($query);

        return $query->refresh();
    }

    public function delete(SearchQuery $query): void
    {
        $query->forceFill(['status' => QueryStatus::Deleted])->save();
        $this->rostenderFeeds->detach($query);
    }

    private function rostenderTemplateId(SearchQuery $query): ?int
    {
        $filters = is_array($query->filters) ? $query->filters : [];
        $source = is_array($filters['source'] ?? null) ? $filters['source'] : [];
        $templateId = $source['rostender_template_id'] ?? null;

        return is_int($templateId) && $templateId > 0 ? $templateId : null;
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
