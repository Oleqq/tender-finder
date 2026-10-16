<?php

namespace App\Services;

use App\Models\SourceFeed;
use Illuminate\Support\Str;

final class BidzaarFeedService
{
    public function configuredFeed(): ?SourceFeed
    {
        $id = (string) config('tender.bidzaar.integrator_id');
        if (! config('tender.bidzaar.enabled') || ! Str::isUuid($id)) {
            SourceFeed::query()->where('source', 'bidzaar')->update(['status' => 'paused']);

            return null;
        }

        // The integration ID grants API access and must not be stored in the feed URL.
        $url = 'https://bidzaar.com/api/aggregator_integrator/procedures/purchases';
        SourceFeed::query()->where('source', 'bidzaar')->where('canonical_url', '!=', $url)
            ->update(['status' => 'paused']);

        return SourceFeed::query()->updateOrCreate(
            ['url_hash' => hash('sha256', 'bidzaar:'.$url)],
            [
                'source' => 'bidzaar',
                'canonical_url' => $url,
                'status' => 'active',
                'poll_interval_seconds' => max(300, (int) config('tender.bidzaar.poll_interval_seconds', 900)),
            ],
        );
    }
}
