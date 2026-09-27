<?php

namespace App\Services;

use App\Models\SourceFeed;

final class WorkspaceRuFeedService
{
    public function configuredFeed(): ?SourceFeed
    {
        $url = config('tender.workspace_ru.feed_url');

        if (! is_string($url) || ! $this->isOfficialFeedUrl($url)) {
            SourceFeed::query()->where('source', 'workspace_ru')->update(['status' => 'paused']);

            return null;
        }

        SourceFeed::query()
            ->where('source', 'workspace_ru')
            ->where('canonical_url', '!=', $url)
            ->update(['status' => 'paused']);

        return SourceFeed::query()->updateOrCreate(
            ['url_hash' => hash('sha256', 'workspace_ru:'.$url)],
            [
                'source' => 'workspace_ru',
                'canonical_url' => $url,
                'status' => 'active',
                'poll_interval_seconds' => max(300, (int) config('tender.workspace_ru.poll_interval_seconds', 3600)),
            ],
        );
    }

    private function isOfficialFeedUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && ($parts['scheme'] ?? null) === 'https'
            && ($host === 'workspace.ru' || str_ends_with($host, '.workspace.ru'));
    }
}
