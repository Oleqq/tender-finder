<?php

namespace App\Services;

use App\Models\SourceFeed;

final class B2bCenterFeedService
{
    public function configuredFeed(): ?SourceFeed
    {
        $url = config('tender.b2b_center.catalog_url');

        if (! is_string($url) || ! $this->isOfficialCatalogUrl($url)) {
            SourceFeed::query()->where('source', 'b2b_center')->update(['status' => 'paused']);

            return null;
        }

        SourceFeed::query()
            ->where('source', 'b2b_center')
            ->where('canonical_url', '!=', $url)
            ->where('canonical_url', 'not like', $url.'?%')
            ->update(['status' => 'paused']);

        return SourceFeed::query()->updateOrCreate(
            ['url_hash' => hash('sha256', 'b2b_center:'.$url)],
            [
                'source' => 'b2b_center',
                'canonical_url' => $url,
                'status' => 'active',
                'poll_interval_seconds' => max(300, (int) config('tender.b2b_center.poll_interval_seconds', 3600)),
            ],
        );
    }

    public function isOfficialCatalogUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && ($parts['scheme'] ?? null) === 'https'
            && ($host === 'b2b-center.ru' || str_ends_with($host, '.b2b-center.ru'))
            && ($parts['path'] ?? null) === '/market/'
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && ! isset($parts['port'])
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }
}
