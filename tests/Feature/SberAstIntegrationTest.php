<?php

use App\Jobs\MatchTender;
use App\Jobs\PollSberAstFeed;
use App\Models\SourceFeed;
use App\Models\Tender;
use App\Services\SberAstHtmlParser;
use App\Services\SberAstPollingDispatcher;
use App\Services\SberAstSource;
use App\Services\TenderSourceImportService;
use App\Tenders\SberAstException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set([
        'tender.sber_ast.enabled' => true,
        'tender.sber_ast.registry_urls' => ['https://utp.sberbank-ast.ru/VIP/List/PurchaseList'],
        'tender.sber_ast.poll_interval_seconds' => 3600,
        'tender.sber_ast.request_timeout_seconds' => 15,
        'tender.sber_ast.user_agent' => 'TenderFinder tests',
    ]);
});

it('parses public purchase rows without supplier credentials', function () {
    $result = app(SberAstHtmlParser::class)->parse(sberAstRegistryHtml(), 'https://utp.sberbank-ast.ru/VIP/List/PurchaseList');

    expect($result->items)->toHaveCount(1);
    $item = $result->items[0];

    expect($item->externalId)->toBe('4235732')
        ->and($item->regNumber)->toBe('SBR043-2609230001')
        ->and($item->title)->toBe('Поставка компьютерного оборудования')
        ->and($item->budgetAmount)->toBe('1500000.50')
        ->and($item->currency)->toBe('RUB')
        ->and($item->deadlineAt?->format('Y-m-d H:i'))->toBe('2026-10-07 15:00')
        ->and($item->metadata['customer'])->toBe('ООО «Заказчик»')
        ->and($item->canonicalUrl)->toBe('https://utp.sberbank-ast.ru/VIP/NBT/PurchaseView/29/0/0/4235732');
});

it('requests only configured public registries with an explicit user agent', function () {
    Http::fake([
        'https://utp.sberbank-ast.ru/VIP/List/PurchaseList' => Http::response(sberAstRegistryHtml(), 200, ['Content-Type' => 'text/html']),
    ]);
    $feed = sberAstFeed();

    $result = app(SberAstSource::class)->fetch($feed);

    expect($result->items)->toHaveCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === $feed->canonical_url
        && $request->hasHeader('User-Agent', 'TenderFinder tests')
        && ! $request->hasHeader('Authorization'));

    $feed->canonical_url = 'https://example.test/unapproved';
    expect(fn () => app(SberAstSource::class)->fetch($feed))->toThrow(SberAstException::class, 'registry_not_configured');
});

it('imports Sberbank AST cards and suppresses notifications on the initial snapshot', function () {
    Queue::fake();
    Http::fake([
        'https://utp.sberbank-ast.ru/VIP/List/PurchaseList' => Http::response(sberAstRegistryHtml()),
    ]);
    $feed = sberAstFeed();

    (new PollSberAstFeed($feed->id))->handle(app(SberAstSource::class), app(TenderSourceImportService::class));

    $tender = Tender::query()->where('source', 'sber_ast')->sole();
    expect($tender->external_id)->toBe('4235732')
        ->and($tender->budget_amount)->toBe('1500000.50')
        ->and($tender->metadata['sber_ast']['status'])->toBe('Прием заявок')
        ->and($feed->fresh()->initialized_at)->not->toBeNull();
    Queue::assertPushed(MatchTender::class, fn (MatchTender $job): bool => $job->queueNotifications === false);
});

it('creates configured feeds and dispatches only a due registry', function () {
    Queue::fake();

    expect(app(SberAstPollingDispatcher::class)->dispatchOneDueFeed())->toBeTrue();

    $feed = SourceFeed::query()->where('source', 'sber_ast')->sole();
    expect($feed->canonical_url)->toBe('https://utp.sberbank-ast.ru/VIP/List/PurchaseList')
        ->and($feed->next_poll_at)->not->toBeNull();
    Queue::assertPushed(PollSberAstFeed::class, fn (PollSberAstFeed $job): bool => $job->feedId === $feed->id);

    expect(app(SberAstPollingDispatcher::class)->dispatchOneDueFeed())->toBeFalse();
});

function sberAstFeed(): SourceFeed
{
    $url = 'https://utp.sberbank-ast.ru/VIP/List/PurchaseList';

    return SourceFeed::query()->create([
        'source' => 'sber_ast',
        'canonical_url' => $url,
        'url_hash' => hash('sha256', 'sber_ast:'.$url),
        'status' => 'active',
        'poll_interval_seconds' => 3600,
    ]);
}

function sberAstRegistryHtml(): string
{
    return <<<'HTML'
<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><title>Реестр закупок</title></head>
<body>
<table>
    <thead><tr>
        <th>Номер извещения</th>
        <th>Наименование закупки</th>
        <th>Заказчик</th>
        <th>Начальная цена</th>
        <th>Окончание приема заявок</th>
        <th>Статус</th>
        <th>Действия</th>
    </tr></thead>
    <tbody><tr>
        <td>SBR043-2609230001</td>
        <td>Поставка компьютерного оборудования</td>
        <td>ООО «Заказчик»</td>
        <td>1 500 000,50 руб.</td>
        <td>07.10.2026 15:00</td>
        <td>Прием заявок</td>
        <td><a href="/VIP/NBT/PurchaseView/29/0/0/4235732">Просмотр</a></td>
    </tr></tbody>
</table>
</body>
</html>
HTML;
}
