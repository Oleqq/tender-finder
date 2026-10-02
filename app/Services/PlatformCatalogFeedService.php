<?php

namespace App\Services;

use App\Models\SourceFeed;

final class PlatformCatalogFeedService
{
    public const URLS = [
        'roseltorg' => 'https://www.b2b-center.ru/search/etps/ao-eetp/',
        'rts_tender' => 'https://www.b2b-center.ru/search/etps/rts-tender/',
    ];

    public function configuredFeed(string $source): ?SourceFeed
    {
        if (! isset(self::URLS[$source]) || ! config("tender.platform_catalog.{$source}.enabled")) {
            SourceFeed::query()->where('source', $source)->update(['status' => 'paused']);

            return null;
        }

        $url = self::URLS[$source];
        SourceFeed::query()->where('source', $source)->where('canonical_url', '!=', $url)
            ->update(['status' => 'paused']);

        return SourceFeed::query()->updateOrCreate(
            ['url_hash' => hash('sha256', $source.':'.$url)],
            [
                'source' => $source,
                'canonical_url' => $url,
                'status' => 'active',
                'poll_interval_seconds' => max(300, (int) config('tender.platform_catalog.poll_interval_seconds', 3600)),
            ],
        );
    }
}
