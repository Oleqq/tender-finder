<?php

use App\Models\LocalMvpSearchSnapshot;
use App\Models\Tender;
use App\Models\User;
use App\Tenders\EisTenderEnrichmentParser;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('tender.local_mvp_operator.enabled', true);
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

it('parses signed EIS print-form fields and allowlisted public attachments', function () {
    $parser = app(EisTenderEnrichmentParser::class);
    $fields = $parser->printForm(eisPrintFormFixture());
    $attachments = $parser->attachments(eisDocumentsFixture());

    expect($fields['deadline_at']?->format('d.m.Y H:i'))->toBe('05.09.2026 10:30')
        ->and($fields['delivery_place'])->toBe('г. Москва, ул. Тестовая, д. 1')
        ->and($fields['contact_email'])->toBe('buyer@example.test')
        ->and($fields['contact_phone'])->toBe('+7 495 000-00-00')
        ->and($fields['procedure_method'])->toBe('Электронный аукцион')
        ->and($fields['application_security'])->toBe('Не требуется')
        ->and($fields['contract_security'])->toBe('125 000,00 ₽')
        ->and($attachments)->toHaveCount(2)
        ->and($attachments[0]['url'])->toBe('https://zakupki.gov.ru/44fz/filestore/public/1.0/download/priz/file.html?uid=SAFE1')
        ->and($attachments[1]['url'])->toBe('https://zakupki.gov.ru/223fz/filestore/public/1.0/download/file.html?uid=SAFE2');
});

it('enriches one accessible EIS tender only after an explicit operator request', function () {
    Http::fake([
        'https://zakupki.gov.ru/epz/order/notice/printForm/view.html*' => Http::response(eisPrintFormFixture(), 200, ['Content-Type' => 'text/html']),
        'https://zakupki.gov.ru/epz/order/notice/ea20/view/documents.html*' => Http::response(eisDocumentsFixture(), 200, ['Content-Type' => 'text/html']),
    ]);
    $this->get('/local/mvp-operator')->assertOk();
    $operator = User::query()->where('email', 'local-mvp-operator@tenderfinder.invalid')->firstOrFail();
    $tender = Tender::query()->create([
        'source' => 'eis_rss',
        'external_id' => '01234567890123456789',
        'reg_number' => '01234567890123456789',
        'canonical_url' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=01234567890123456789',
        'canonical_url_hash' => hash('sha256', 'enrichment-tender'),
        'title' => 'Поставка программного обеспечения',
        'currency' => 'RUB',
        'metadata' => ['customer' => 'Заказчик из RSS'],
    ]);
    LocalMvpSearchSnapshot::query()->create([
        'user_id' => $operator->id,
        'query' => 'программное обеспечение',
        'source' => 'eis_rss',
        'tender_ids' => [$tender->id],
    ]);

    $this->postJson("/local/mvp/tenders/{$tender->id}/enrich")
        ->assertOk()
        ->assertJsonPath('tender.delivery_place', 'г. Москва, ул. Тестовая, д. 1')
        ->assertJsonPath('tender.contact_email', 'buyer@example.test')
        ->assertJsonPath('tender.application_security', 'Не требуется')
        ->assertJsonPath('tender.attachments.0.label', 'Описание объекта закупки');

    $tender->refresh();
    expect($tender->deadline_at?->format('d.m.Y H:i'))->toBe('05.09.2026 10:30')
        ->and($tender->metadata['customer'])->toBe('Заказчик из RSS')
        ->and($tender->metadata['delivery_place'])->toBe('г. Москва, ул. Тестовая, д. 1')
        ->and($tender->metadata['attachments'])->toHaveCount(2)
        ->and($tender->metadata['enriched_at'])->not->toBeNull();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://zakupki.gov.ru/epz/order/notice/printForm/view.html?regNumber=01234567890123456789');
    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://zakupki.gov.ru/epz/order/notice/ea20/view/documents.html?regNumber=01234567890123456789');
});

function eisPrintFormFixture(): string
{
    return <<<'HTML'
<!doctype html><html><body><table>
<tr><td><p class="parameter">Способ определения поставщика (подрядчика, исполнителя)</p></td><td><p class="parameterValue">Электронный аукцион</p></td></tr>
<tr><td><p class="parameter">Дата и время окончания подачи заявок</p></td><td><p class="parameterValue">05.09.2026 10:30</p></td></tr>
<tr><td><p class="parameter">Место поставки товара, выполнения работы или оказания услуги</p></td><td><p class="parameterValue">г. Москва, ул. Тестовая, д. 1</p></td></tr>
<tr><td><p class="parameter">Ответственное должностное лицо</p></td><td><p class="parameterValue">Иванов Иван Иванович</p></td></tr>
<tr><td><p class="parameter">Адрес электронной почты</p></td><td><p class="parameterValue">buyer@example.test</p></td></tr>
<tr><td><p class="parameter">Номер контактного телефона</p></td><td><p class="parameterValue">+7 495 000-00-00</p></td></tr>
<tr><td><p class="parameter">Почтовый адрес</p></td><td><p class="parameterValue">101000, г. Москва</p></td></tr>
<tr><td><p class="parameter">Обеспечение заявок не требуется</p></td><td></td></tr>
<tr><td><p class="parameter">Размер обеспечения исполнения контракта</p></td><td><p class="parameterValue">125 000,00 ₽</p></td></tr>
</table></body></html>
HTML;
}

function eisDocumentsFixture(): string
{
    return <<<'HTML'
<!doctype html><html><body>
<a href="https://zakupki.gov.ru/44fz/filestore/public/1.0/download/priz/file.html?uid=SAFE1">Описание объекта закупки</a>
<a href="/223fz/filestore/public/1.0/download/file.html?uid=SAFE2">Проект договора</a>
<a href="https://evil.example.test/file.pdf">Недоверенный файл</a>
<a href="https://zakupki.gov.ru/epz/main/public/document/view.html?sectionId=1">Не файл закупки</a>
<a href="https://zakupki.gov.ru/44fz/filestore/public/%2e%2e/%2e%2e/epz/unsafe.html">Путь с обходом каталога</a>
</body></html>
HTML;
}
