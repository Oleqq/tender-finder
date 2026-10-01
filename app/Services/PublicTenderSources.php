<?php

namespace App\Services;

use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\SourceFeedSearchQuery;
use Illuminate\Database\Eloquent\Collection;

/** Public feeds are shared; matching and user state remain personal. */
final class PublicTenderSources
{
    /** @return list<string> */
    public function enabled(): array
    {
        return array_values(array_filter(
            ['sber_ast', 'workspace_ru', 'b2b_center'],
            fn (string $source): bool => (bool) config("tender.{$source}.enabled"),
        ));
    }

    /** @return Collection<int, SourceFeed> */
    public function feeds(?SearchQuery $query = null): Collection
    {
        $searchFeeds = $query === null ? [] : SourceFeedSearchQuery::query()->where('search_query_id', $query->id)
            ->whereHas('feed', fn ($feeds) => $feeds->where('source', 'b2b_center'))->pluck('source_feed_id')->all();

        return SourceFeed::query()->whereIn('source', $this->enabled())
            ->where(fn ($feeds) => $feeds->where('source', '!=', 'b2b_center')
                ->orWhere(fn ($b2b) => $b2b->where('source', 'b2b_center')->where(function ($b2b) use ($searchFeeds): void {
                    if ($searchFeeds !== []) {
                        $b2b->whereIn('id', $searchFeeds);
                    } else {
                        $b2b->where('canonical_url', config('tender.b2b_center.catalog_url'));
                    }
                })))
            ->where(fn ($feeds) => $feeds->where('status', 'active')->orWhereIn('id', $searchFeeds))
            ->orderBy('id')->get();
    }

    public function synchronize(): void
    {
        if (config('tender.sber_ast.enabled')) {
            app(SberAstFeedService::class)->configuredFeeds();
        }
        if (config('tender.workspace_ru.enabled')) {
            app(WorkspaceRuFeedService::class)->configuredFeed();
        }
        if (config('tender.b2b_center.enabled')) {
            app(B2bCenterFeedService::class)->configuredFeed();
        }
    }

    public function dispatchDueChecks(?SearchQuery $query = null): bool
    {
        $queued = false;
        // A source's cooldown and shared lock apply to all users, including
        // repeated clicks. A failed source cannot consume another's quota.
        $sberCount = $this->feeds()->where('source', 'sber_ast')->count();
        for ($index = 0; $index < $sberCount; $index++) {
            $queued = app(SberAstPollingDispatcher::class)->dispatchOneDueFeed() || $queued;
        }
        $queued = app(WorkspaceRuPollingDispatcher::class)->dispatchDueFeed() || $queued;

        foreach ($this->feeds($query)->where('source', 'b2b_center') as $feed) {
            $queued = app(B2bCenterPollingDispatcher::class)->dispatchDueFeed($feed->id) || $queued;
        }

        return $queued;
    }
}
