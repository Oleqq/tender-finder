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

it('preserves every legal paragraph and table cell in server rendered HTML', function (string $type) {
    config()->set('tender.legal.documents_published', true);
    $document = app(LegalDocumentRegistry::class)->document($type);
    $response = $this->get('/'.$type)->assertOk();
    foreach ($document['blocks'] as $block) {
        if ($block['type'] === 'table') {
            foreach ($block['rows'] as $row) {
                foreach ($row as $cell) {
                    if ($cell !== '') {
                        $response->assertSee($cell);
                    }
                }
            }
        } else {
            $response->assertSee($block['text']);
        }
    }
    $response->assertSee('Юридические документы')->assertSee('Скачать оригинал Word');
})->with(LegalDocumentRegistry::TYPES);

it('publishes the corrected privacy revision while preserving the other accepted versions', function () {
    config()->set('tender.legal.documents_published', true);
    $registry = app(LegalDocumentRegistry::class);
    expect($registry->document('privacy')['version'])->toStartWith('2026-11-02-r3-privacy-');
    expect(preg_match('/(?<!\d)50247944003(?!\d)/', $registry->text('privacy')))->toBe(0);
    expect($registry->text('privacy'))->toContain('502479440033');
    $zip = new ZipArchive;
    $zip->open($registry->originalPath('privacy'));
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    expect($xml)->toContain('502479440033');
    expect(preg_match('/(?<!\d)50247944003(?!\d)/', $xml))->toBe(0);
    foreach (['terms', 'offer', 'consent'] as $type) {
        expect($registry->document($type)['version'])->toStartWith('2026-11-02-r2-'.$type.'-');
    }
});
