<?php

namespace App\Services\PriceCatalog;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;
use ZipArchive;

final class SpreadsheetImporter
{
    public const MAX_ROWS = 500;

    private const HEADERS = [
        'name' => ['название', 'наименование', 'наименование товара', 'товар', 'услуга', 'компетенция', 'name'],
        'characteristics' => ['характеристики', 'описание', 'characteristics', 'description'],
        'price' => ['цена', 'цена руб', 'цена руб.', 'price'],
        'quantity' => ['количество', 'доступное количество', 'остаток', 'quantity'],
        'unit' => ['единица', 'единица измерения', 'ед изм', 'ед. изм.', 'unit'],
        'region' => ['регион', 'region'],
    ];

    /** @return list<array<string, string|null>> */
    public function read(UploadedFile $file): array
    {
        $this->inspectArchive($file->getPathname());
        $reader = new Reader;
        $items = [];
        try {
            $reader->open($file->getPathname());
            foreach ($reader->getSheetIterator() as $sheet) {
                $columns = null;
                foreach ($sheet->getRowIterator() as $line => $row) {
                    if (count($items) >= self::MAX_ROWS) {
                        $this->fail('В файле допускается не больше 500 позиций. Разделите прайс.');
                    }
                    if ($row->getNumCells() > 20) {
                        $this->fail('Используйте шаблон: в таблице допускается до 20 столбцов.');
                    }
                    $cells = [];
                    foreach ($row->getCells() as $cell) {
                        if ($cell instanceof FormulaCell) {
                            $this->fail('Строка '.$line.': замените формулы готовыми значениями. Формулы не вычисляются.');
                        }
                        $value = $cell->getValue();
                        if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_float($value)) {
                            $this->fail('Строка '.$line.': используйте текст и числа, без дат и логических значений.');
                        }
                        $cells[] = $value === null ? '' : trim((string) $value);
                    }
                    if (implode('', $cells) === '') {
                        continue;
                    }
                    if ($columns === null) {
                        $columns = $this->columns($cells);

                        continue;
                    }
                    $item = [];
                    foreach (self::HEADERS as $key => $aliases) {
                        $value = isset($columns[$key]) ? ($cells[$columns[$key]] ?? '') : '';
                        $item[$key] = $value === '' ? null : $value;
                    }
                    $validator = validator($item, self::rules());
                    if ($validator->fails()) {
                        $this->fail('Строка '.$line.': '.$validator->errors()->first());
                    }
                    $item['price'] = self::decimal($item['price'], 2, 'Строка '.$line.': цена');
                    $item['quantity'] = self::decimal($item['quantity'], 3, 'Строка '.$line.': количество');
                    $items[] = $item;
                }
                // The template and UI explicitly use only the first worksheet.
                break;
            }
            if ($items === []) {
                $this->fail('На первом листе нет позиций. Скачайте шаблон и заполните строки под заголовком.');
            }

            return $items;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail('Не удалось прочитать Excel. Сохраните файл как .xlsx без пароля и попробуйте снова.');
        } finally {
            $reader->close();
        }
    }

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:300'],
            'characteristics' => ['nullable', 'string', 'max:1500'],
            'price' => ['nullable', 'string', 'max:40'],
            'quantity' => ['nullable', 'string', 'max:40'],
            'unit' => ['nullable', 'string', 'max:40'],
            'region' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function decimal(?string $value, int $scale, string $label): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $number = str_replace(["\u{00a0}", "\u{202f}", ' ', ','], ['', '', '', '.'], trim($value));
        if (! preg_match('/^\d+(?:\.\d+)?$/D', $number)) {
            throw ValidationException::withMessages(['file' => $label.': требуется неотрицательное число.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $number, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        if (strlen($whole) > 15 - $scale || strlen($fraction) > $scale) {
            throw ValidationException::withMessages(['file' => $label.': число слишком большое или содержит слишком много знаков после запятой.']);
        }

        return $whole.'.'.str_pad($fraction, $scale, '0');
    }

    /** @param list<string> $headers
     * @return array<string, int>
     */
    private function columns(array $headers): array
    {
        $result = [];
        foreach ($headers as $index => $header) {
            $header = mb_strtolower(trim($header));
            foreach (self::HEADERS as $field => $aliases) {
                if (in_array($header, $aliases, true)) {
                    if (isset($result[$field])) {
                        $this->fail('В таблице повторяется столбец «'.$header.'».');
                    }
                    $result[$field] = $index;
                }
            }
        }
        if (! isset($result['name'])) {
            $this->fail('В первой строке нужен столбец «Название» или «Наименование». Остальные поля можно оставить пустыми.');
        }

        return $result;
    }

    private function inspectArchive(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('Загрузите Excel в формате .xlsx. Старые .xls и файлы с паролем не поддерживаются.');
        }
        try {
            if ($zip->numFiles > 2000 || $zip->locateName('xl/workbook.xml') === false) {
                $this->fail('Неподдерживаемая структура Excel. Используйте шаблон.');
            }
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if ($entry === false) {
                    $this->fail('Повреждённый файл Excel.');
                }
                $size += $entry['size'];
                if ($size > 32 * 1024 * 1024 || preg_match('~(^/|(^|[/\\\\])\.\.([/\\\\]|$))~', $entry['name'])) {
                    $this->fail('Файл слишком сложный. Скопируйте значения в шаблон.');
                }
                if (str_ends_with($entry['name'], '.xml') || str_ends_with($entry['name'], '.rels')) {
                    $xml = $zip->getFromIndex($i);
                    if ($xml === false || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
                        $this->fail('Неподдерживаемая XML-структура Excel.');
                    }
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
