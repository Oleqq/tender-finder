<?php

use App\Services\LegalDocumentRegistry;

it('keeps public pages and originals behind the common publication switch', function () {
    config()->set('tender.legal.documents_published', false);
    foreach (LegalDocumentRegistry::TYPES as $type) {
        $this->get('/'.$type)->assertServiceUnavailable();
        $this->get('/legal/'.$type.'/download')->assertServiceUnavailable();
    }
    $this->get('/info')->assertServiceUnavailable();
});

it('serves complete documents and exact Word originals without login or JavaScript', function (string $type) {
    config()->set('tender.legal.documents_published', true);
    $registry = app(LegalDocumentRegistry::class);
    $document = $registry->document($type);
    $this->get('/'.$type)->assertOk()
        ->assertSee($document['title'])->assertSee($document['version'])
        ->assertSee('/legal/'.$type.'/download', false)
        ->assertDontSee('Закрытый local beta');
    $download = $this->get('/legal/'.$type.'/download')->assertOk()->assertDownload();
    expect($download->headers->get('Content-Disposition'))->toContain(rawurlencode($document['filename']));
    expect(hash_file('sha256', $registry->originalPath($type)))->toBe($document['sha256']);
    expect($registry->text($type))->toContain('tenderfinder-client@yandex.ru');
})->with(LegalDocumentRegistry::TYPES);

it('renders real prices seller contacts and delivery information on a public page', function () {
    config()->set('tender.legal.documents_published', true);
    $this->get('/info')->assertOk()->assertSee('502479440033')
        ->assertSee('tenderfinder-client@yandex.ru')->assertSee('3 769 ₽')
        ->assertSee('электронной форме')->assertSee('/terms', false)->assertSee('/consent', false);
    $this->get('/legal/order/download')->assertNotFound();
    $this->get('/legal/terms.json/download')->assertNotFound();
});
