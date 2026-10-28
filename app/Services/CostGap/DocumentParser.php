<?php

namespace App\Services\CostGap;

use DOMDocument;
use DOMXPath;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use ZipArchive;

/** Executed in an isolated, memory/time-limited PHP worker. No external requests. */
final class DocumentParser
{
    public const MAX_CHARS = 40000;

    /** @return array{segments: list<array{ref: string, text: string}>, warnings: list<string>} */
    public function parse(string $path, string $extension): array
    {
        return match ($extension) {
            'txt' => ['segments' => $this->segments($this->textFile($path), 'строка'), 'warnings' => []],
            'docx' => $this->docx($path),
            'pdf' => $this->pdf($path),
            default => throw new DocumentParseException('Поддерживаются PDF с текстом, DOCX и TXT.'),
        };
    }

    /** @return list<array{ref: string, text: string}> */
    public function segments(string $text, string $prefix = 'строка'): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (! mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0E-\x1F]/', $text)) {
            throw new DocumentParseException('Нужен обычный текст UTF-8 без двоичных данных.');
        }
        if (mb_strlen($text) > self::MAX_CHARS) {
            throw new DocumentParseException('В одном источнике допускается до 40 000 знаков. Выберите нужные разделы.');
        }
        $segments = [];
        foreach (explode("\n", $text) as $line => $value) {
            $chunks = preg_split('/(?<=[.!?;])\s+/u', trim($value)) ?: [];
            $part = 0;
            foreach ($chunks as $chunk) {
                foreach (mb_str_split($chunk, 1500) as $piece) {
                    if (trim($piece) !== '') {
                        $segments[] = ['ref' => $prefix.' '.($line + 1).', фрагмент '.(++$part), 'text' => trim($piece)];
                    }
                }
            }
            if (count($segments) > 1000) {
                throw new DocumentParseException('Слишком много фрагментов. Загрузите нужный раздел отдельно.');
            }
        }
        if ($segments === []) {
            throw new DocumentParseException('В источнике не найден текст. Для скана вставьте распознанную выдержку вручную.');
        }

        return $segments;
    }

    private function textFile(string $path): string
    {
        $text = file_get_contents($path);
        if ($text === false) {
            throw new DocumentParseException('Не удалось прочитать текст.');
        }
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            return substr($text, 3);
        }
        if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            return mb_convert_encoding($text, 'UTF-8', 'UTF-16');
        }
        if (! mb_check_encoding($text, 'UTF-8') && ! str_contains($text, "\0")) {
            return mb_convert_encoding($text, 'UTF-8', 'Windows-1251');
        }

        return $text;
    }

    /** @return array{segments: list<array{ref: string, text: string}>, warnings: list<string>} */
    private function docx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new DocumentParseException('DOCX повреждён или защищён паролем.');
        }
        try {
            $total = 0;
            if ($zip->numFiles > 2000) {
                throw new DocumentParseException('Документ слишком сложный. Вставьте выдержку текстом.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if ($entry === false) {
                    throw new DocumentParseException('Повреждённый DOCX.');
                }
                $total += $entry['size'];
                if ($total > 32 * 1024 * 1024) {
                    throw new DocumentParseException('Распакованный DOCX превышает 32 МБ. Вставьте нужную выдержку.');
                }
            }
            $xml = $zip->getFromName('word/document.xml');
            if ($xml === false || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
                throw new DocumentParseException('Неподдерживаемая структура DOCX.');
            }
            libxml_use_internal_errors(true);
            $dom = new DOMDocument;
            if (! $dom->loadXML($xml, LIBXML_NONET)) {
                throw new DocumentParseException('Не удалось прочитать DOCX.');
            }
            $xpath = new DOMXPath($dom);
            $paragraphs = $xpath->query('//*[local-name()="p"]');
            $segments = [];
            $length = 0;
            foreach ($paragraphs ?: [] as $i => $paragraph) {
                $text = '';
                foreach ($xpath->query('.//*[local-name()="t" or local-name()="tab" or local-name()="br"]', $paragraph) ?: [] as $node) {
                    $text .= $node->localName === 't' ? $node->textContent : ' ';
                }
                if (trim($text) === '') {
                    continue;
                }
                $length += mb_strlen($text);
                if ($length > self::MAX_CHARS) {
                    throw new DocumentParseException('В DOCX больше 40 000 знаков. Загрузите нужные разделы отдельно.');
                }
                foreach ($this->segments($text, 'абзац '.($i + 1).', строка') as $segment) {
                    $segments[] = $segment;
                }
                if (count($segments) > 1000) {
                    throw new DocumentParseException('Слишком много фрагментов DOCX.');
                }
            }
            if ($segments === []) {
                throw new DocumentParseException('В DOCX не найден текст. Вставьте распознанную выдержку.');
            }

            return ['segments' => $segments, 'warnings' => ['Прочитан основной текст и таблицы DOCX. Колонтитулы, примечания и вложения не проверены.']];
        } finally {
            $zip->close();
        }
    }

    /** @return array{segments: list<array{ref: string, text: string}>, warnings: list<string>} */
    private function pdf(string $path): array
    {
        if (! str_starts_with((string) file_get_contents($path, false, null, 0, 5), '%PDF-')) {
            throw new DocumentParseException('Файл не является PDF.');
        }
        $config = new Config;
        $config->setDecodeMemoryLimit(8 * 1024 * 1024);
        $document = (new Parser(['ignore_filter_decoding_errors' => false, 'ignore_missing_filter_decoders' => false], $config))->parseFile($path);
        foreach ($document->getObjects() as $object) {
            if (strlen((string) $object->getContent()) >= 8 * 1024 * 1024) {
                throw new DocumentParseException('PDF превышает лимит распаковки. Загрузите нужный раздел или вставьте текст.');
            }
        }
        $pages = $document->getPages();
        if (count($pages) > 80) {
            throw new DocumentParseException('В PDF больше 80 страниц. Выберите нужные разделы.');
        }
        $segments = [];
        $empty = [];
        $length = 0;
        foreach ($pages as $i => $page) {
            $text = trim($page->getText());
            if ($text === '') {
                $empty[] = $i + 1;

                continue;
            }
            $length += mb_strlen($text);
            if ($length > self::MAX_CHARS) {
                throw new DocumentParseException('В PDF больше 40 000 знаков. Загрузите нужные разделы отдельно.');
            }
            foreach ($this->segments($text, 'с. '.($i + 1).', строка') as $segment) {
                $segments[] = $segment;
            }
            if (count($segments) > 1000) {
                throw new DocumentParseException('Слишком много фрагментов PDF.');
            }
        }
        if ($segments === []) {
            throw new DocumentParseException('В PDF нет извлекаемого текста. Сканы не распознаются: вставьте нужный текст вручную.');
        }

        return ['segments' => $segments, 'warnings' => ['Прочитан доступный текстовый слой PDF. Изображения, сканы и вложения не распознаются; сверьте извлечение с оригиналом.',
            ...($empty === [] ? [] : ['Не извлечён текст со страниц: '.implode(', ', $empty).'. Их содержание не проверено.'])]];
    }
}
