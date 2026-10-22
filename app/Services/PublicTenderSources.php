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
            ['sber_ast', 'workspace_ru', 'etp_gpb', 'bidzaar', 'etp_region', 'b2b_center', 'roseltorg', 'rts_tender', 'sber_ast_catalog'],
            fn (string $source): bool => (bool) config(in_array($source, array_keys(PlatformCatalogFeedService::URLS), true)
                ? "tender.platform_catalog.{$source}.enabled" : "tender.{$source}.enabled"),
        ));
    }

    /** @return Collection<int, SourceFeed> */
    public function feeds(?SearchQuery $query = null): Collection
    {
        $searchFeeds = $query === null ? [] : SourceFeedSearchQuery::query()->where('search_query_id', $query->id)
            ->whereHas('feed', fn ($feeds) => $feeds->where('source', 'b2b_center'))->pluck('source_feed_id')->all();

        $sources = $this->enabled();
        $selected = $query === null ? null : TenderSources::selected($query);
        if ($selected !== null) {
            $sources = array_values(array_intersect($sources, $selected));
        }

        return SourceFeed::query()->whereIn('source', $sources)
            ->where(fn ($feeds) => $feeds->where('source', '!=', 'b2b_center')
                ->orWhere(fn ($b2b) => $b2b->where('source', 'b2b_center')->where(function ($b2b) use ($query, $searchFeeds): void {
                    if ($query !== null) {
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
        app(EtpGpbFeedService::class)->configuredFeed();
        app(BidzaarFeedService::class)->configuredFeed();
        app(EtpRegionFeedService::class)->configuredFeed();
        if (config('tender.b2b_center.enabled')) {
            app(B2bCenterFeedService::class)->configuredFeed();
        }
        foreach (array_keys(PlatformCatalogFeedService::URLS) as $source) {
            app(PlatformCatalogFeedService::class)->configuredFeed($source);
        }
    }

    public function dispatchDueChecks(?SearchQuery $query = null): bool
    {
        $queued = false;
        $sources = $this->feeds($query)->pluck('source')->unique()->all();
        // A source's cooldown and shared lock apply to all users, including
        // repeated clicks. A failed source cannot consume another's quota.
        $sberCount = $this->feeds($query)->where('source', 'sber_ast')->count();
        for ($index = 0; $index < $sberCount; $index++) {
            $queued = app(SberAstPollingDispatcher::class)->dispatchOneDueFeed() || $queued;
        }
        foreach (['workspace_ru' => WorkspaceRuPollingDispatcher::class, 'etp_gpb' => EtpGpbPollingDispatcher::class,
            'bidzaar' => BidzaarPollingDispatcher::class, 'etp_region' => EtpRegionPollingDispatcher::class] as $source => $dispatcher) {
            if (in_array($source, $sources, true)) {
                $queued = app($dispatcher)->dispatchDueFeed() || $queued;
            }
        }

        foreach ($this->feeds($query)->where('source', 'b2b_center') as $feed) {
            $queued = app(B2bCenterPollingDispatcher::class)->dispatchDueFeed($feed->id) || $queued;
        }

        foreach (array_keys(PlatformCatalogFeedService::URLS) as $source) {
            if (in_array($source, $sources, true)) {
                $queued = app(PlatformCatalogPollingDispatcher::class)->dispatchDueFeed($source) || $queued;
            }
        }

        return $queued;
    }
}
