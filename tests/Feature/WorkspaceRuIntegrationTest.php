<?php

use App\Jobs\MatchTender;
use App\Jobs\PollWorkspaceRuFeed;
use App\Models\SourceFeed;
use App\Models\Tender;
use App\Services\TenderSourceImportService;
use App\Services\WorkspaceRuPollingDispatcher;
use App\Services\WorkspaceRuRssParser;
use App\Services\WorkspaceRuSource;
use App\Tenders\WorkspaceRuException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set([
        'tender.workspace_ru.enabled' => true,
        'tender.workspace_ru.feed_url' => 'https://workspace.ru/tenders/rss/',
        'tender.workspace_ru.poll_interval_seconds' => 3600,
        'tender.workspace_ru.request_timeout_seconds' => 15,
        'tender.workspace_ru.user_agent' => 'TenderFinder tests',
    ]);
});

it('parses the official Workspace tender RSS fields', function () {
    $result = app(WorkspaceRuRssParser::class)->parse(workspaceRuRss());

    expect($result->items)->toHaveCount(2);
    $item = $result->items[0];

    expect($item->externalId)->toBe('18973')
        ->and($item->title)->toBe('SEO и GEO/AEO продвижение двух сайтов')
        ->and($item->publishedAt?->format('Y-m-d H:i:sP'))->toBe('2026-09-25 17:37:37+03:00')
        ->and($item->deadlineAt?->format('Y-m-d H:i'))->toBe('2026-10-01 23:59')
        ->and($item->budgetAmount)->toBe('100000.00')
        ->and($item->summary)->toContain('Требуемая услуга: seo под ключ')
        ->and($item->summary)->toContain('Продвижение двух корпоративных сайтов')
        ->and($item->metadata['workspace_ru']['organizer_type'])->toBe('юридическое лицо');

    expect($result->items[1]->budgetAmount)->toBe('800000.00')
        ->and($result->items[1]->metadata['workspace_ru']['budget_min'])->toBe('200000.00')
        ->and($result->items[1]->metadata['workspace_ru']['budget_max'])->toBe('800000.00');
});

it('rejects malformed RSS instead of recording an empty successful poll', function () {
    expect(fn () => app(WorkspaceRuRssParser::class)->parse('<html>broken'))
        ->toThrow(WorkspaceRuException::class, 'invalid_feed');
});

it('requests only the configured official RSS without account credentials', function () {
    Http::fake([
        'https://workspace.ru/tenders/rss/' => Http::response(workspaceRuRss(), 200, ['Content-Type' => 'text/xml;charset=UTF-8']),
    ]);
    $feed = workspaceRuFeed();

    $result = app(WorkspaceRuSource::class)->fetch($feed);

    expect($result->items)->toHaveCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === $feed->canonical_url
        && $request->hasHeader('User-Agent', 'TenderFinder tests')
        && ! $request->hasHeader('Authorization')
        && ! $request->hasHeader('Cookie'));
});

it('imports Workspace cards and suppresses notifications for the initial RSS snapshot', function () {
    Queue::fake();
    Http::fake([
        'https://workspace.ru/tenders/rss/' => Http::response(workspaceRuRss()),
    ]);
    $feed = workspaceRuFeed();

    (new PollWorkspaceRuFeed($feed->id))->handle(app(WorkspaceRuSource::class), app(TenderSourceImportService::class));

    expect(Tender::query()->where('source', 'workspace_ru')->count())->toBe(2)
        ->and($feed->fresh()->initialized_at)->not->toBeNull();
    $tender = Tender::query()->where('external_id', '18973')->sole();
    expect($tender->published_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-25 14:37:37')
        ->and($tender->deadline_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-01 20:59:59')
        ->and($tender->deadline_at?->setTimezone('Europe/Moscow')->format('Y-m-d H:i:s'))->toBe('2026-10-01 23:59:59');
    Queue::assertPushed(MatchTender::class, 2);
    Queue::assertPushed(MatchTender::class, fn (MatchTender $job): bool => $job->queueNotifications === false);
});

it('creates and dispatches the configured Workspace RSS feed when due', function () {
    Queue::fake();

    expect(app(WorkspaceRuPollingDispatcher::class)->dispatchDueFeed())->toBeTrue();

    $feed = SourceFeed::query()->where('source', 'workspace_ru')->sole();
    expect($feed->canonical_url)->toBe('https://workspace.ru/tenders/rss/')
        ->and($feed->next_poll_at)->not->toBeNull();
    Queue::assertPushed(PollWorkspaceRuFeed::class, fn (PollWorkspaceRuFeed $job): bool => $job->feedId === $feed->id);

    expect(app(WorkspaceRuPollingDispatcher::class)->dispatchDueFeed())->toBeFalse();
});

function workspaceRuFeed(): SourceFeed
{
    $url = 'https://workspace.ru/tenders/rss/';

    return SourceFeed::query()->create([
        'source' => 'workspace_ru',
        'canonical_url' => $url,
        'url_hash' => hash('sha256', 'workspace_ru:'.$url),
        'status' => 'active',
        'poll_interval_seconds' => 3600,
    ]);
}

function workspaceRuRss(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<rss version="2.0">
<channel>
<title>WORKSPACE. Тендеры</title>
<link>https://workspace.ru/tenders/rss/</link>
<item>
<title>SEO и GEO/AEO продвижение двух сайтов</title>
<link>https://workspace.ru/tenders/seo-i-geo-prodvizhenie-18973/</link>
<description><![CDATA[<b>Организатор</b>: юридическое лицо<br/><b>Требуемая услуга</b>: seo под ключ<br/><b>Дата публикации</b>: 25.09.2026<br/><b>Крайний срок приема заявок</b>: 01.10.2026<br/><b>Бюджет</b>: до 100 000 руб<br/><b>Описание проекта</b>: Продвижение двух корпоративных сайтов.<br/><br/><b>Требования к исполнителям</b>: компании, фрилансеры]]></description>
<pubDate>Fri, 25 Sep 2026 17:37:37 +0300</pubDate>
</item>
<item>
<title>Разработка портала</title>
<link>https://workspace.ru/tenders/razrabotka-portala-18974/</link>
<description><![CDATA[<b>Организатор</b>: юридическое лицо<br/><b>Требуемые услуги</b>: разработка сайтов, дизайн<br/><b>Дата публикации</b>: 26.09.2026<br/><b>Крайний срок приема заявок</b>: 05.10.2026<br/><b>Бюджет</b>: 200 000 - 800 000 руб<br/><b>Описание проекта</b>: Создание корпоративного портала.]]></description>
<pubDate>Sat, 26 Sep 2026 12:00:00 +0300</pubDate>
</item>
</channel>
</rss>
XML;
}

it('rejects a well-formed block page and malformed channel instead of an empty successful RSS poll', function () {
    foreach (['<html><body>Unavailable</body></html>', '<rss />', '<rss><channel><item><title>Broken</title></item></channel></rss>'] as $body) {
        expect(fn () => app(WorkspaceRuRssParser::class)->parse($body))
            ->toThrow(WorkspaceRuException::class, 'invalid_feed');
    }
});
