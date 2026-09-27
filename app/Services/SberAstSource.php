<?php

namespace App\Services;

use App\Models\SourceFeed;
use App\Tenders\SberAstException;
use App\Tenders\SourceFetchResult;
use App\Tenders\TenderSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class SberAstSource implements TenderSource
{
    public function __construct(private readonly SberAstHtmlParser $parser) {}

    public function fetch(SourceFeed $feed): SourceFetchResult
    {
        if (! config('tender.sber_ast.enabled') || $feed->source !== 'sber_ast') {
            throw new SberAstException('source_disabled');
        }

        $url = $feed->canonical_url;
        $configuredUrls = config('tender.sber_ast.registry_urls', []);

        if (! is_array($configuredUrls) || ! in_array($url, $configuredUrls, true)) {
            throw new SberAstException('registry_not_configured');
        }

        try {
            $response = Http::accept('text/html,application/xhtml+xml')
                ->withUserAgent((string) config('tender.sber_ast.user_agent'))
                ->timeout(max(1, (int) config('tender.sber_ast.request_timeout_seconds', 15)))
                ->get($url);
        } catch (ConnectionException) {
            throw new SberAstException('connection_failed');
        }

        if (! $response->successful()) {
            throw new SberAstException('http_'.$response->status());
        }

        return $this->parser->parse($response->body(), $url);
    }
}
