<?php

namespace App\Services;

use RuntimeException;
use XMLWriter;
use ZipArchive;

final class TenderExportService
{
    /**
     * @param  list<array<string, mixed>>  $tenders
     */
    public function csv(array $tenders, ?string $filterSummary): string
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('export_stream_failed');
        }

        fwrite($stream, "\xEF\xBB\xBF");

        foreach ($this->rows($tenders, $filterSummary) as $row) {
            fputcsv($stream, array_map($this->safeCell(...), $row), ';', '"', '');
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        if ($contents === false) {
            throw new RuntimeException('export_stream_failed');
        }

        return $contents;
    }

    /**
     * @param  list<array<string, mixed>>  $tenders
     */
    public function xlsx(array $tenders, ?string $filterSummary): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tender-finder-xlsx-');

        if ($path === false) {
            throw new RuntimeException('export_file_failed');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('export_file_failed');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->packageRelationships());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheet($this->rows($tenders, $filterSummary)));
        $zip->close();

        $contents = file_get_contents($path);
        @unlink($path);

        if ($contents === false) {
            throw new RuntimeException('export_file_failed');
        }

        return $contents;
    }

    /**
     * @param  list<array<string, mixed>>  $tenders
     * @return list<list<string>>
     */
    private function rows(array $tenders, ?string $filterSummary): array
    {
        $rows = [
            ['Экспорт Tender Finder', now()->toAtomString()],
            ['Активные фильтры', $filterSummary ?? 'Не указаны'],
            [],
            [
                'Название',
                'НМЦК',
                'Валюта',
                'Заказчик',
                'Закон',
                'Процедура',
                'Регион',
                'Место поставки',
                'Опубликовано',
                'Срок подачи',
                'Обеспечение заявки',
                'Обеспечение контракта',
                'Контактное лицо',
                'Телефон',
                'Email',
                'Номер ЕИС',
                'Статус',
                'Теги',
                'Заметка',
                'Следующее действие',
                'Ссылка',
            ],
        ];

        foreach ($tenders as $tender) {
            $tags = is_array($tender['tags'] ?? null)
                ? implode(', ', array_filter($tender['tags'], 'is_string'))
                : '';
            $rows[] = array_map(fn (mixed $value): string => $this->string($value), [
                $tender['title'] ?? null,
                $tender['budget_amount'] ?? null,
                $tender['currency'] ?? null,
                $tender['customer'] ?? null,
                filled($tender['procurement_law'] ?? null) ? $tender['procurement_law'].'-ФЗ' : null,
                $tender['category'] ?? null,
                $tender['region'] ?? null,
                $tender['delivery_place'] ?? null,
                $tender['published_at'] ?? null,
                $tender['deadline_at'] ?? null,
                $tender['application_security'] ?? null,
                $tender['contract_security'] ?? null,
                $tender['contact_name'] ?? null,
                $tender['contact_phone'] ?? null,
                $tender['contact_email'] ?? null,
                $tender['reg_number'] ?? null,
                $this->status((string) ($tender['status'] ?? 'new')),
                $tags,
                $tender['note'] ?? null,
                $tender['next_action_on'] ?? null,
                $tender['canonical_url'] ?? null,
            ]);
        }

        return $rows;
    }

    /** @param list<list<string>> $rows */
    private function worksheet(array $rows): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElement('worksheet');
        $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->startElement('sheetViews');
        $xml->startElement('sheetView');
        $xml->writeAttribute('workbookViewId', '0');
        $xml->startElement('pane');
        $xml->writeAttribute('ySplit', '4');
        $xml->writeAttribute('topLeftCell', 'A5');
        $xml->writeAttribute('activePane', 'bottomLeft');
        $xml->writeAttribute('state', 'frozen');
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->startElement('cols');

        foreach ([34, 15, 10, 28, 10, 24, 20, 36, 22, 22, 24, 24, 24, 18, 28, 24, 16, 24, 40, 20, 42] as $index => $width) {
            $xml->startElement('col');
            $xml->writeAttribute('min', (string) ($index + 1));
            $xml->writeAttribute('max', (string) ($index + 1));
            $xml->writeAttribute('width', (string) $width);
            $xml->writeAttribute('customWidth', '1');
            $xml->endElement();
        }

        $xml->endElement();
        $xml->startElement('sheetData');

        foreach ($rows as $rowIndex => $row) {
            $number = $rowIndex + 1;
            $xml->startElement('row');
            $xml->writeAttribute('r', (string) $number);

            foreach ($row as $columnIndex => $value) {
                $xml->startElement('c');
                $xml->writeAttribute('r', $this->column($columnIndex + 1).$number);
                $xml->writeAttribute('t', 'inlineStr');

                if ($number === 4) {
                    $xml->writeAttribute('s', '1');
                }

                $xml->startElement('is');
                $xml->startElement('t');
                $xml->writeAttribute('xml:space', 'preserve');
                $xml->text($this->safeCell($value));
                $xml->endElement();
                $xml->endElement();
                $xml->endElement();
            }

            $xml->endElement();
        }

        $xml->endElement();
        $xml->startElement('autoFilter');
        $xml->writeAttribute('ref', 'A4:U'.max(4, count($rows)));
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function column(int $number): string
    {
        $result = '';

        while ($number > 0) {
            $number--;
            $result = chr(65 + ($number % 26)).$result;
            $number = intdiv($number, 26);
        }

        return $result;
    }

    private function string(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function safeCell(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        return preg_match('/^[\t\r\n ]*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
    }

    private function status(string $status): string
    {
        return [
            'new' => 'Новый',
            'favorite' => 'Избранное',
            'potential' => 'Потенциальный',
            'dismissed' => 'Скрытый',
            'archived' => 'Убран',
        ][$status] ?? $status;
    }

    private function contentTypes(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>
XML;
    }

    private function packageRelationships(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>
XML;
    }

    private function workbook(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Закупки" sheetId="1" r:id="rId1"/></sheets></workbook>
XML;
    }

    private function workbookRelationships(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>
XML;
    }

    private function styles(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>
XML;
    }
}
