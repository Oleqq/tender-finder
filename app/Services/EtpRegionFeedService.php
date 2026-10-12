<?php

namespace App\Services;

use App\Models\SourceFeed;

final class EtpRegionFeedService
{
    public const URL = 'https://torgi.etp-region.ru/searchServlet';

    public function configuredFeed(): ?SourceFeed
    {
        if (! config('tender.etp_region.enabled')) {
            SourceFeed::query()->where('source', 'etp_region')->update(['status' => 'paused']);

            return null;
        }

        return SourceFeed::query()->updateOrCreate(
            ['url_hash' => hash('sha256', 'etp_region:'.self::URL)],
            [
                'source' => 'etp_region',
                'canonical_url' => self::URL,
                'status' => 'active',
                'poll_interval_seconds' => max(900, (int) config('tender.etp_region.poll_interval_seconds', 3600)),
            ],
        );
    }
}
