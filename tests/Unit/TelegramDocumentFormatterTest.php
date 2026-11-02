<?php

use App\Services\TelegramDocumentFormatter;

function telegramVisibleText(string $html): string
{
    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

it('keeps legal paragraphs prices and table rows complete and highlights section headings', function (string $type) {
    $document = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/legal/2026-11-02-r2/'.$type.'.json'), true);
    $messages = (new TelegramDocumentFormatter)->document($document);
    $all = implode("\n\n", $messages);
    expect($all)->toStartWith('<b>📄 '.$document['title'].'</b>')
        ->not->toContain('Доступно в боте:', 'На сайте:', 'Оригинал Word:', '<h2>', '<h3>');
    foreach ($messages as $message) {
        expect(intdiv(strlen(mb_convert_encoding(telegramVisibleText($message), 'UTF-16LE', 'UTF-8')), 2))->toBeLessThanOrEqual(4096);
        $dom = new DOMDocument;
        expect($dom->loadXML('<root>'.$message.'</root>'))->toBeTrue();
    }
    foreach ($document['blocks'] as $block) {
        if ($block['type'] === 'table') {
            foreach (array_slice($block['rows'], 1) as $row) {
                $containing = array_filter($messages, fn ($m) => str_contains(telegramVisibleText($m), $row[0]));
                expect($containing)->not->toBeEmpty();
                expect(count(array_filter($containing, fn ($m) => count(array_filter($row, fn ($cell) => str_contains(telegramVisibleText($m), $cell))) === count($row))))->toBeGreaterThan(0);
            }
        } else {
            if (str_starts_with($block['text'], 'Доступно в боте:')) {
                continue;
            }
            expect(telegramVisibleText($all))->toContain($block['text']);
            if (preg_match('/^\d+\.\s+\p{Lu}/u', $block['text'])) {
                expect($all)->toContain('<b>'.TelegramDocumentFormatter::escape($block['text']).'</b>');
            }
        }
    }
})->with(['terms', 'privacy', 'consent', 'offer']);

it('splits long paragraphs at safe boundaries without dropping text or leaving headings alone', function () {
    $text = str_repeat('Полное предложение с условиями поставки и оплаты. ', 190).'Почта tenderfinder-client@yandex.ru.';
    $document = ['title' => 'Тест', 'blocks' => [
        ['type' => 'paragraph', 'text' => str_repeat('Первый абзац. ', 100)],
        ['type' => 'paragraph', 'text' => '4. ВОЗВРАТ СРЕДСТВ'],
        ['type' => 'paragraph', 'text' => $text],
    ]];
    $messages = (new TelegramDocumentFormatter)->document($document);
    expect(count($messages))->toBeGreaterThan(1);
    $all = implode("\n\n", $messages);
    expect(telegramVisibleText($all))->toContain('tenderfinder-client@yandex.ru');
    $normalize = fn ($value) => preg_replace('/\s+/u', ' ', trim($value));
    expect($normalize(telegramVisibleText($all)))->toBe($normalize('📄 Тест '.$document['blocks'][0]['text'].' 4. ВОЗВРАТ СРЕДСТВ '.$text));
    foreach ($messages as $message) {
        expect(telegramVisibleText($message))->not->toEndWith('4. ВОЗВРАТ СРЕДСТВ');
        expect(intdiv(strlen(mb_convert_encoding(telegramVisibleText($message), 'UTF-16LE', 'UTF-8')), 2))->toBeLessThanOrEqual(4096);
        expect((new DOMDocument)->loadXML('<root>'.$message.'</root>'))->toBeTrue();
    }
});

it('escapes HTML and counts emoji in UTF16 while retaining all input text', function () {
    $text = str_repeat('😀 <tag> & текст. ', 500);
    $messages = (new TelegramDocumentFormatter)->plain($text, 'Заголовок <script>');
    foreach ($messages as $message) {
        expect($message)->not->toContain('<script>', '<tag>');
        expect(intdiv(strlen(mb_convert_encoding(telegramVisibleText($message), 'UTF-16LE', 'UTF-8')), 2))->toBeLessThanOrEqual(4096);
        expect((new DOMDocument)->loadXML('<root>'.$message.'</root>'))->toBeTrue();
    }
    $normalize = fn ($value) => preg_replace('/\s+/u', ' ', trim($value));
    expect($normalize(telegramVisibleText(implode("\n\n", $messages))))->toBe($normalize('Заголовок <script> '.$text));
});
