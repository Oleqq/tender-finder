<?php

namespace App\Services;

use App\Models\SourceFeed;
use App\Tenders\EtpGpbException;
use App\Tenders\SourceFetchResult;
use App\Tenders\TenderSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class EtpGpbSource implements TenderSource
{
    public function __construct(private readonly EtpGpbRssParser $parser) {}

    public function fetch(SourceFeed $feed): SourceFetchResult
    {
        $url = config('tender.etp_gpb.feed_url');
        if (! config('tender.etp_gpb.enabled') || $feed->source !== 'etp_gpb'
            || ! is_string($url) || $feed->canonical_url !== $url) {
            throw new EtpGpbException('source_disabled');
        }

        $response = $this->request($url);
        if ($response->redirect()) {
            $location = $response->header('Location');
            if ($location !== 'https://new.etpgpb.ru/procedures.rss') {
                throw new EtpGpbException('unexpected_redirect');
            }
            $response = $this->request($location);
        }

        if (! $response->successful()) {
            throw new EtpGpbException('http_'.$response->status());
        }

        return $this->parser->parse($response->body());
    }

    private function request(string $url): Response
    {
        try {
            return Http::accept('application/rss+xml,application/xml,text/xml')
                ->withUserAgent((string) config('tender.etp_gpb.user_agent'))
                ->timeout(max(1, (int) config('tender.etp_gpb.request_timeout_seconds', 15)))
                ->withoutRedirecting()
                ->get($url);
        } catch (ConnectionException) {
            throw new EtpGpbException('connection_failed');
        }
    }
}
