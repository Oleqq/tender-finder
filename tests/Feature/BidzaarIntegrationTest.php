<?php

use App\Jobs\MatchTender;
use App\Jobs\PollBidzaarFeed;
use App\Models\SourceFeed;
use App\Models\Tender;
use App\Services\BidzaarFeedService;
use App\Services\BidzaarPollingDispatcher;
use App\Services\BidzaarResponseParser;
use App\Services\BidzaarSource;
use App\Services\TenderSourceImportService;
use App\Tenders\BidzaarException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set([
        'tender.bidzaar.enabled' => true,
        'tender.bidzaar.integrator_id' => '123e4567-e89b-42d3-a456-426614174000',
        'tender.bidzaar.poll_interval_seconds' => 900,
        'tender.bidzaar.request_timeout_seconds' => 15,
        'tender.bidzaar.user_agent' => 'TenderFinder tests',
    ]);
});

it('parses the documented purchase response and refuses untrusted procedure links', function () {
    $result = app(BidzaarResponseParser::class)->parse(bidzaarResponse());

    expect($result->items)->toHaveCount(1)
        ->and($result->items[0]->externalId)->toBe('339-632')
        ->and($result->items[0]->title)->toBe('Разработка корпоративного сайта')
        ->and($result->items[0]->budgetAmount)->toBe('100000.00');

    $bad = bidzaarResponse();
    $bad['items'][0]['link'] = 'https://example.com/app/process/light/1';
    expect(fn () => app(BidzaarResponseParser::class)->parse($bad))
        ->toThrow(BidzaarException::class, 'invalid_response');
    expect(fn () => app(BidzaarResponseParser::class)->parse(['pageSize' => 1000]))
        ->toThrow(BidzaarException::class, 'invalid_response');
});

it('requests only the official purchase API and imports the initial snapshot silently', function () {
    Queue::fake();
    $response = bidzaarResponse();
    $ended = $response['items'][0];
    $ended['number'] = '339-633';
    $ended['link'] = 'https://bidzaar.com/process/light/019ea718-2d48-754d-b479-b5ad0d7a5a69';
    $ended['status'] = 'ended';
    $response['items'][] = $ended;
    Http::fake(['bidzaar.com/api/aggregator_integrator/*' => Http::response($response)]);
    $feed = app(BidzaarFeedService::class)->configuredFeed();

    (new PollBidzaarFeed($feed->id))->handle(app(BidzaarSource::class), app(TenderSourceImportService::class));

    expect(Tender::query()->where('source', 'bidzaar')->count())->toBe(1)
        ->and($feed->fresh()->initialized_at)->not->toBeNull();
    Queue::assertPushed(MatchTender::class, fn (MatchTender $job): bool => $job->queueNotifications === false);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/procedures/purchases?')
        && str_contains($request->url(), 'PageSize=500')
        && str_contains($request->url(), 'PageNumber=0')
        && $request->hasHeader('User-Agent', 'TenderFinder tests')
        && ! $request->hasHeader('Authorization')
        && ! $request->hasHeader('Cookie'));
    expect($feed->canonical_url)->not->toContain(config('tender.bidzaar.integrator_id'));
});

it('records a source error instead of treating an HTML block page as an empty feed', function () {
    $feed = app(BidzaarFeedService::class)->configuredFeed();
    Http::fake(['bidzaar.com/api/aggregator_integrator/*' => Http::response('<html>Blocked</html>')]);

    (new PollBidzaarFeed($feed->id))->handle(app(BidzaarSource::class), app(TenderSourceImportService::class));

    expect($feed->fresh()->last_error_code)->toBe('invalid_response')
        ->and($feed->fresh()->initialized_at)->toBeNull()
        ->and(Tender::query()->where('source', 'bidzaar')->count())->toBe(0);
});

it('advances FromDate after a full page while keeping PageNumber zero', function () {
    $first = bidzaarResponse();
    $first['items'] = [];
    for ($index = 0; $index < 500; $index++) {
        $row = bidzaarResponse()['items'][0];
        $row['number'] = '339-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT);
        $row['lastModified'] = now()->subMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z');
        $first['items'][] = $row;
    }
    $second = bidzaarResponse();
    $second['items'][0]['lastModified'] = now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z');
    $second['items'] = [$first['items'][499], $second['items'][0]];

    Http::fake(['bidzaar.com/api/aggregator_integrator/*' => Http::sequence()
        ->push($first)->push($second)]);

    $result = app(BidzaarSource::class)->fetch(app(BidzaarFeedService::class)->configuredFeed());

    expect($result->items)->toHaveCount(501)
        ->and($result->itemsReturned)->toBe(502);
    Http::assertSentCount(2);
    $requests = Http::recorded()->map(fn (array $entry): Request => $entry[0])->values();
    expect($requests[0]->data()['FromDate'])->not->toBe($requests[1]->data()['FromDate'])
        ->and($requests[0]->data()['PageNumber'])->toBe(0)
        ->and($requests[1]->data()['PageNumber'])->toBe(0);
});

it('dispatches only when a valid integration id is configured and a poll is due', function () {
    Queue::fake();
    $dispatcher = app(BidzaarPollingDispatcher::class);

    expect($dispatcher->dispatchDueFeed())->toBeTrue()
        ->and($dispatcher->dispatchDueFeed())->toBeFalse();
    Queue::assertPushed(PollBidzaarFeed::class, 1);

    config()->set('tender.bidzaar.integrator_id', null);
    expect($dispatcher->dispatchDueFeed())->toBeFalse()
        ->and(SourceFeed::query()->where('source', 'bidzaar')->first()->status)->toBe('paused');
});

/** @return array<string, mixed> */
function bidzaarResponse(): array
{
    return [
        'pageSize' => 1000,
        'items' => [[
            'number' => '339-632',
            'name' => 'Разработка корпоративного сайта',
            'description' => 'Разработка сайта по техническому заданию',
            'publisherName' => 'ООО Заказчик',
            'publishDate' => '2026-10-11T09:00:00Z',
            'acceptanceEndDate' => now()->addDays(4)->utc()->format('Y-m-d\TH:i:s\Z'),
            'link' => 'https://bidzaar.com/process/light/019ea718-2d48-754d-b479-b5ad0d7a5a68',
            'currency' => 'RUB',
            'price' => 100000,
            'status' => 'proposal',
            'lastModified' => '2026-10-11T10:00:00Z',
        ]],
    ];
}
