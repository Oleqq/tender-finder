<?php

namespace App\Services;

use App\Models\SourceFeed;

final class EtpGpbFeedService
{
    public function configuredFeed(): ?SourceFeed
    {
        $url = config('tender.etp_gpb.feed_url');
        if (! config('tender.etp_gpb.enabled') || $url !== 'https://etpgpb.ru/procedures.rss') {
            SourceFeed::query()->where('source', 'etp_gpb')->update(['status' => 'paused']);

            return null;
        }

        return SourceFeed::query()->updateOrCreate(
            ['url_hash' => hash('sha256', 'etp_gpb:'.$url)],
            [
                'source' => 'etp_gpb',
                'canonical_url' => $url,
                'status' => 'active',
                'poll_interval_seconds' => max(300, (int) config('tender.etp_gpb.poll_interval_seconds', 3600)),
            ],
        );
    }
}
