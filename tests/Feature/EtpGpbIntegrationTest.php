<?php

use App\Jobs\MatchTender;
use App\Jobs\PollEtpGpbFeed;
use App\Models\SourceFeed;
use App\Models\Tender;
use App\Services\EtpGpbFeedService;
use App\Services\EtpGpbPollingDispatcher;
use App\Services\EtpGpbRssParser;
use App\Services\EtpGpbSource;
use App\Services\TenderSourceImportService;
use App\Tenders\EtpGpbException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set([
        'tender.etp_gpb.enabled' => true,
        'tender.etp_gpb.feed_url' => 'https://etpgpb.ru/procedures.rss',
        'tender.etp_gpb.poll_interval_seconds' => 3600,
        'tender.etp_gpb.request_timeout_seconds' => 15,
        'tender.etp_gpb.user_agent' => 'TenderFinder tests',
    ]);
});

it('parses public RSS procedures and rejects unrelated links and block pages', function () {
    $result = app(EtpGpbRssParser::class)->parse(etpGpbRss());

    expect($result->items)->toHaveCount(1)
        ->and($result->items[0]->title)->toBe('Разработка корпоративного сайта')
        ->and($result->items[0]->canonicalUrl)->toBe('https://etpgpb.ru/procedures/etp/1240053/')
        ->and($result->items[0]->publishedAt?->format('Y-m-d H:i:sP'))->toBe('2026-10-08 09:00:00+03:00');

    foreach (['<html>Check your browser</html>', '<rss />', '<rss><channel><item><title>Broken</title></item></channel></rss>'] as $body) {
        expect(fn () => app(EtpGpbRssParser::class)->parse($body))
            ->toThrow(EtpGpbException::class, 'invalid_feed');
    }
});

it('fetches only the official RSS without credentials', function () {
    Http::fake(['https://etpgpb.ru/procedures.rss' => Http::response(etpGpbRss())]);
    $feed = app(EtpGpbFeedService::class)->configuredFeed();

    expect(app(EtpGpbSource::class)->fetch($feed)->items)->toHaveCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === $feed->canonical_url
        && $request->hasHeader('User-Agent', 'TenderFinder tests')
        && ! $request->hasHeader('Authorization')
        && ! $request->hasHeader('Cookie'));
});

it('follows only the official RSS redirect', function () {
    $feed = app(EtpGpbFeedService::class)->configuredFeed();
    Http::fake([
        'https://etpgpb.ru/procedures.rss' => Http::response('', 301, ['Location' => 'https://new.etpgpb.ru/procedures.rss']),
        'https://new.etpgpb.ru/procedures.rss' => Http::response(etpGpbRss()),
    ]);
    expect(app(EtpGpbSource::class)->fetch($feed)->items)->toHaveCount(1);
});

it('rejects redirects outside the official RSS host', function () {
    $feed = app(EtpGpbFeedService::class)->configuredFeed();
    Http::fake([
        'https://etpgpb.ru/procedures.rss' => Http::response('', 301, ['Location' => 'https://example.com/rss']),
    ]);
    expect(fn () => app(EtpGpbSource::class)->fetch($feed))
        ->toThrow(EtpGpbException::class, 'unexpected_redirect');
});

it('imports the first snapshot without historic notifications', function () {
    Queue::fake();
    Http::fake(['https://etpgpb.ru/procedures.rss' => Http::response(etpGpbRss())]);
    $feed = app(EtpGpbFeedService::class)->configuredFeed();

    (new PollEtpGpbFeed($feed->id))->handle(app(EtpGpbSource::class), app(TenderSourceImportService::class));

    expect(Tender::query()->where('source', 'etp_gpb')->count())->toBe(1)
        ->and($feed->fresh()->initialized_at)->not->toBeNull();
    Queue::assertPushed(MatchTender::class, fn (MatchTender $job): bool => $job->queueNotifications === false);
});

it('dispatches the feed only when enabled and due', function () {
    Queue::fake();
    $dispatcher = app(EtpGpbPollingDispatcher::class);

    expect($dispatcher->dispatchDueFeed())->toBeTrue()
        ->and($dispatcher->dispatchDueFeed())->toBeFalse();
    expect(SourceFeed::query()->where('source', 'etp_gpb')->count())->toBe(1);
    Queue::assertPushed(PollEtpGpbFeed::class, 1);

    config()->set('tender.etp_gpb.enabled', false);
    expect($dispatcher->dispatchDueFeed())->toBeFalse();
});

function etpGpbRss(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<rss version="2.0"><channel><title>ЭТП ГПБ — процедуры</title>
<item><title>Разработка корпоративного сайта</title>
<link>https://etpgpb.ru/procedures/etp/1240053/</link>
<description><![CDATA[Открытый конкурс для разработки сайта]]></description>
<pubDate>Thu, 08 Oct 2026 09:00:00 +0300</pubDate></item>
<item><title>Посторонняя ссылка</title><link>https://example.com/procedures/1/</link></item>
</channel></rss>
XML;
}
