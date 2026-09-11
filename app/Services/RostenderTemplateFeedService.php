<?php

namespace App\Services;

use App\Enums\QueryStatus;
use App\Models\RostenderFeedSearchQuery;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\User;
use App\Tenders\RostenderAccessDisabledException;
use RuntimeException;

class RostenderTemplateFeedService
{
    public function __construct(
        private readonly AccessService $access,
        private readonly RostenderAccessGate $gate,
    ) {}

    public function attach(SearchQuery $query, int $templateId): SourceFeed
    {
        $this->gate->assertDataProcessingAllowed();

        if ($query->status !== QueryStatus::Active) {
            throw new RuntimeException('monitoring_not_active');
        }

        $user = User::query()->findOrFail($query->user_id);
        $limit = $this->activeMonitoringLimit($user);
        $pollInterval = $this->pollIntervalFor($user);

        if ($limit < 1 || $pollInterval < 60) {
            throw new RostenderAccessDisabledException;
        }

        /** @var SourceFeed|null $existingFeed */
        $existingFeed = SourceFeed::query()
            ->where('source', 'rostender')
            ->where('source_identifier', $templateId)
            ->first();

        if ($existingFeed !== null && RostenderFeedSearchQuery::query()
            ->where('source_feed_id', $existingFeed->id)
            ->where('search_query_id', $query->id)
            ->exists()) {
            return $existingFeed;
        }

        $activeLinks = RostenderFeedSearchQuery::query()
            ->whereHas('searchQuery', fn ($builder) => $builder
                ->where('user_id', $user->id)
                ->where('status', QueryStatus::Active))
            ->where('search_query_id', '!=', $query->id)
            ->count();

        if ($activeLinks >= $limit) {
            throw new RuntimeException('rostender_monitoring_limit_reached');
        }

        $canonicalUrl = rtrim((string) config('tender.rostender.base_url'), '/').'/template/'.$templateId;
        /** @var SourceFeed $feed */
        $feed = SourceFeed::query()->firstOrCreate(
            ['source' => 'rostender', 'source_identifier' => $templateId],
            [
                'canonical_url' => $canonicalUrl,
                'url_hash' => hash('sha256', 'rostender:template:'.$templateId),
                'status' => 'active',
                'poll_interval_seconds' => $pollInterval,
                'next_poll_at' => now(),
            ],
        );

        if ($feed->poll_interval_seconds > $pollInterval) {
            $feed->forceFill(['poll_interval_seconds' => $pollInterval])->save();
        }

        RostenderFeedSearchQuery::query()->firstOrCreate([
            'source_feed_id' => $feed->id,
            'search_query_id' => $query->id,
        ]);

        $this->refreshFeedStatus($feed);

        return $feed;
    }

    public function synchronize(SearchQuery $query, ?int $templateId): void
    {
        if ($templateId === null) {
            $this->detach($query);

            return;
        }

        $this->attach($query, $templateId);

        $obsoleteFeeds = SourceFeed::query()
            ->where('source', 'rostender')
            ->where('source_identifier', '!=', $templateId)
            ->whereIn('id', RostenderFeedSearchQuery::query()
                ->where('search_query_id', $query->id)
                ->select('source_feed_id'))
            ->get();

        if ($obsoleteFeeds->isEmpty()) {
            return;
        }

        RostenderFeedSearchQuery::query()
            ->where('search_query_id', $query->id)
            ->whereIn('source_feed_id', $obsoleteFeeds->modelKeys())
            ->delete();

        $obsoleteFeeds->each(fn (SourceFeed $feed) => $this->refreshFeedStatus($feed));
    }

    public function detach(SearchQuery $query): void
    {
        $feeds = SourceFeed::query()
            ->where('source', 'rostender')
            ->whereIn('id', RostenderFeedSearchQuery::query()
                ->where('search_query_id', $query->id)
                ->select('source_feed_id'))
            ->get();

        RostenderFeedSearchQuery::query()
            ->where('search_query_id', $query->id)
            ->delete();

        $feeds->each(fn (SourceFeed $feed) => $this->refreshFeedStatus($feed));
    }

    public function refreshFor(SearchQuery $query): void
    {
        SourceFeed::query()
            ->where('source', 'rostender')
            ->whereIn('id', RostenderFeedSearchQuery::query()
                ->where('search_query_id', $query->id)
                ->select('source_feed_id'))
            ->get()
            ->each(fn (SourceFeed $feed) => $this->refreshFeedStatus($feed));
    }

    private function refreshFeedStatus(SourceFeed $feed): void
    {
        $hasActiveMonitoring = RostenderFeedSearchQuery::query()
            ->where('source_feed_id', $feed->id)
            ->whereHas('searchQuery', fn ($builder) => $builder->where('status', QueryStatus::Active))
            ->exists();

        $feed->forceFill($hasActiveMonitoring
            ? ['status' => 'active', 'next_poll_at' => $feed->next_poll_at ?? now()]
            : ['status' => 'paused', 'next_poll_at' => null])
            ->save();
    }

    private function activeMonitoringLimit(User $user): int
    {
        $plan = $this->access->snapshotFor($user)->planCode;

        return match ($plan) {
            PlanCatalog::BASIC_CODE => max(0, (int) config('tender.rostender.basic_active_monitor_limit', 0)),
            PlanCatalog::PRO_CODE => max(0, (int) config('tender.rostender.pro_active_monitor_limit', 0)),
            default => 0,
        };
    }

    private function pollIntervalFor(User $user): int
    {
        $plan = $this->access->snapshotFor($user)->planCode;

        return match ($plan) {
            PlanCatalog::BASIC_CODE => max(0, (int) config('tender.rostender.basic_poll_interval_seconds', 86400)),
            PlanCatalog::PRO_CODE => max(0, (int) config('tender.rostender.pro_poll_interval_seconds', 86400)),
            default => 0,
        };
    }
}
