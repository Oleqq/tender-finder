<?php

namespace App\Services;

use App\Models\SourceFeed;
use App\Tenders\EisRssUrlValidator;
use RuntimeException;

class SourceFeedService
{
    public function __construct(private readonly EisRssUrlValidator $urls) {}

    public function findOrCreate(string $url): SourceFeed
    {
        $canonicalUrl = $this->urls->canonicalFeedUrl($url);
        $hash = hash('sha256', $canonicalUrl);
        $existing = SourceFeed::query()->where('url_hash', $hash)->first();

        if ($existing !== null) {
            if ($existing->status === 'manual_preview') {
                $existing->forceFill([
                    'status' => 'active',
                    'poll_interval_seconds' => (int) config('tender.rss.poll_interval_seconds', 600),
                    'next_poll_at' => now(),
                ])->save();
            }

            return $existing;
        }

        if (SourceFeed::query()->where('status', 'active')->count() >= (int) config('tender.rss.max_active_feeds', 100)) {
            throw new RuntimeException('rss_feed_limit_reached');
        }

        /** @var SourceFeed $feed */
        $feed = SourceFeed::query()->create([
            'canonical_url' => $canonicalUrl,
            'url_hash' => $hash,
            'status' => 'active',
            'poll_interval_seconds' => (int) config('tender.rss.poll_interval_seconds', 600),
            'next_poll_at' => now(),
        ]);

        return $feed;
    }
}
