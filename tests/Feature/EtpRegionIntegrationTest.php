<?php

use App\Jobs\MatchTender;
use App\Jobs\PollEtpRegionFeed;
use App\Models\Tender;
use App\Services\EtpRegionFeedService;
use App\Services\EtpRegionResponseParser;
use App\Services\EtpRegionSource;
use App\Services\TenderSourceImportService;
use App\Tenders\EtpRegionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set([
        'tender.etp_region.enabled' => true,
        'tender.etp_region.pages_per_poll' => 10,
        'tender.etp_region.poll_interval_seconds' => 3600,
        'tender.etp_region.request_timeout_seconds' => 15,
        'tender.etp_region.user_agent' => 'TenderFinder tests',
    ]);
});

it('parses a live search result and converts its Ekaterinburg deadline to UTC', function () {
    $result = app(EtpRegionResponseParser::class)->parse(regionResponse());

    expect($result->items)->toHaveCount(1)
        ->and($result->items[0]->externalId)->toBe('corebofs002080000q2ar0rv6pars2jc')
        ->and($result->items[0]->regNumber)->toBe('32616454312')
        ->and($result->items[0]->budgetAmount)->toBe('1391547.56')
        ->and($result->items[0]->deadlineAt->utc()->format('Y-m-d H:i'))->toBe('2026-10-19 03:00');

    $bad = regionResponse();
    $bad['list'][0]['lotLink'] = 'https://example.com/app/LotCard/page';
    expect(fn () => app(EtpRegionResponseParser::class)->parse($bad))
        ->toThrow(EtpRegionException::class, 'invalid_response');
});

it('imports only open purchases from the direct public search without first-run notifications', function () {
    Queue::fake();
    $response = regionResponse();
    $closed = $response['list'][0];
    $closed['uuid'] = 'closed-lot';
    $closed['state']['code'] = 'PurchaseLot_St_Winner_Found';
    $response['list'][] = $closed;
    $response['count'] = 2;
    Http::fake(['torgi.etp-region.ru/searchServlet*' => Http::response($response)]);

    $feed = app(EtpRegionFeedService::class)->configuredFeed();
    (new PollEtpRegionFeed($feed->id))->handle(app(EtpRegionSource::class), app(TenderSourceImportService::class));

    expect(Tender::query()->where('source', 'etp_region')->count())->toBe(1)
        ->and($feed->fresh()->initialized_at)->not->toBeNull();
    Queue::assertPushed(MatchTender::class, fn (MatchTender $job): bool => $job->queueNotifications === false);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), EtpRegionFeedService::URL.'?')
        && str_contains(urldecode($request->url()), '"BUYING"')
        && str_contains(urldecode($request->url()), '"min":0')
        && $request->hasHeader('User-Agent', 'TenderFinder tests'));
});

it('does not record an HTML error page as a successful empty search', function () {
    $feed = app(EtpRegionFeedService::class)->configuredFeed();
    Http::fake(['torgi.etp-region.ru/searchServlet*' => Http::response('<html>Unavailable</html>')]);

    (new PollEtpRegionFeed($feed->id))->handle(app(EtpRegionSource::class), app(TenderSourceImportService::class));

    expect($feed->fresh()->last_error_code)->toBe('invalid_response')
        ->and($feed->fresh()->initialized_at)->toBeNull();
});

it('reads the next page and stops after the fourteen day placement window', function () {
    $first = regionResponse();
    $first['list'] = [];
    for ($index = 0; $index < 100; $index++) {
        $row = regionResponse()['list'][0];
        $row['uuid'] = 'lot-'.(string) $index;
        $row['lotLink'] .= '-'.(string) $index;
        $first['list'][] = $row;
    }
    $first['count'] = 100;
    $second = regionResponse();
    $second['list'][0]['placementDate'] = '23.09.2026';

    Http::fake(['torgi.etp-region.ru/searchServlet*' => Http::sequence()
        ->push($first)->push($second)]);

    $result = app(EtpRegionSource::class)->fetch(app(EtpRegionFeedService::class)->configuredFeed());

    expect($result->items)->toHaveCount(100)
        ->and($result->itemsReturned)->toBe(101);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), '"min":100'));
});

/** @return array<string, mixed> */
function regionResponse(): array
{
    return ['count' => 1, 'totalCount' => 45344, 'list' => [[
        'identifier' => '32616454312',
        'uuid' => 'corebofs002080000q2ar0rv6pars2jc',
        'type' => 'BUYING_223',
        'title' => 'Строительство газопроводов в Новосибирской области',
        'placementDate' => '11.10.2026',
        'gdEndDate' => '19.10.2026 08:00&nbsp;ЕКТ (МСК+2)',
        'lotLink' => 'https://torgi.etp-region.ru/app/LotCard/page?LotCard.lotEntity=LT%3Acorebofs002080000q2ar0rv6pars2jc',
        'organizer' => ['title' => 'ООО Новосибирскоблгаз'],
        'price' => "<div class='aright'>1\u{00A0}391\u{00A0}547.56\u{00A0}руб.</div>",
        'placementType' => 'Запрос котировок',
        'regionCodes' => ['Новосибирская область'],
        'state' => ['code' => 'PurchaseLot_St_Dmnds_Started', 'title' => 'Идет прием заявок'],
    ]]];
}
