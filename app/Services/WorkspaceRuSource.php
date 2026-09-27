<?php

namespace App\Services;

use App\Models\SourceFeed;
use App\Tenders\SourceFetchResult;
use App\Tenders\TenderSource;
use App\Tenders\WorkspaceRuException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class WorkspaceRuSource implements TenderSource
{
    public function __construct(private readonly WorkspaceRuRssParser $parser) {}

    public function fetch(SourceFeed $feed): SourceFetchResult
    {
        $configuredUrl = config('tender.workspace_ru.feed_url');

        if (! config('tender.workspace_ru.enabled') || $feed->source !== 'workspace_ru') {
            throw new WorkspaceRuException('source_disabled');
        }

        if (! is_string($configuredUrl) || $configuredUrl === '' || $feed->canonical_url !== $configuredUrl) {
            throw new WorkspaceRuException('feed_not_configured');
        }

        try {
            $response = Http::accept('application/rss+xml,application/xml,text/xml')
                ->withUserAgent((string) config('tender.workspace_ru.user_agent'))
                ->timeout(max(1, (int) config('tender.workspace_ru.request_timeout_seconds', 15)))
                ->get($configuredUrl);
        } catch (ConnectionException) {
            throw new WorkspaceRuException('connection_failed');
        }

        if (! $response->successful()) {
            throw new WorkspaceRuException('http_'.$response->status());
        }

        return $this->parser->parse($response->body());
    }
}
