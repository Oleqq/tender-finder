<?php

namespace App\Services;

final class TelegramDocumentFormatter
{
    // Leave room below Telegram's 4096 UTF-16-unit limit, including emoji.
    private const LIMIT = 3600;

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed> $document
     * @return list<string>
     */
    public function document(array $document): array
    {
        $blocks = [['text' => '📄 '.$document['title'], 'style' => 'b', 'heading' => true]];
        foreach ($document['blocks'] as $block) {
            if ($block['type'] === 'table') {
                $rows = $block['rows'];
                $headers = array_shift($rows) ?? [];
                // Labelled vertical rows keep every column readable on a phone.
                foreach ($rows as $row) {
                    $lines = [];
                    foreach ($row as $index => $value) {
                        $label = $headers[$index] ?? 'Значение';
                        $label = match ($label) {
                            'Лимиты доступа (одновременные мониторинги)' => 'Активные мониторинги',
                            '1 мес.' => '1 месяц', '3 мес.' => '3 месяца', '12 мес.' => '12 месяцев',
                            default => $label,
                        };
                        $lines[] = $label.' — '.$value;
                    }
                    $blocks[] = ['text' => implode("\n", $lines), 'style' => '', 'heading' => false, 'row' => true];
                }

                continue;
            }
            $text = $block['text'];
            if (preg_match('/^\s*(?:Доступно\s+в\s+боте|На\s+сайте|Оригинал\s+Word)\s*:/iu', $text)) {
                continue;
            }
            $heading = preg_match('/^\d+\.\s+\p{Lu}/u', $text) === 1;
            $blocks[] = ['text' => $text, 'style' => $heading ? 'b' : (str_starts_with($text, 'Дата последнего обновления:') ? 'i' : ''), 'heading' => $heading];
        }

        return $this->pack($blocks);
    }

    /** @return list<string> */
    public function plain(string $text, string $title): array
    {
        $blocks = [['text' => $title, 'style' => 'b', 'heading' => true]];
        foreach (preg_split('/\n\s*\n/u', $text) ?: [] as $paragraph) {
            $blocks[] = ['text' => $paragraph, 'style' => '', 'heading' => false];
        }

        return $this->pack($blocks);
    }

    /** @param list<array{text: string, style: string, heading: bool, row?: bool}> $blocks
     * @return list<string>
     */
    private function pack(array $blocks): array
    {
        $units = [];
        $pending = [];
        foreach ($blocks as $block) {
            if ($block['heading']) {
                $pending[] = $block;

                continue;
            }
            $parts = $this->split($block['text']);
            foreach ($parts as $index => $part) {
                $unit = $index === 0 ? $pending : [];
                $pending = [];
                $unit[] = [...$block, 'text' => $part];
                $units[] = $unit;
            }
        }
        if ($pending !== []) {
            $units[] = $pending;
        }

        $messages = [];
        $current = '';
        foreach ($units as $unit) {
            $html = implode("\n\n", array_map($this->render(...), $unit));
            // A large paragraph may leave too little room for its heading.
            if ($this->length($html) > self::LIMIT) {
                $heading = array_slice($unit, 0, -1);
                $body = $unit[array_key_last($unit)];
                $prefix = implode("\n\n", array_map($this->render(...), $heading));
                $budget = self::LIMIT - $this->length($prefix) - 2;
                foreach ($this->split($body['text'], max(64, $budget)) as $i => $part) {
                    $fragment = ($i === 0 && $prefix !== '' ? $prefix."\n\n" : '').$this->render([...$body, 'text' => $part]);
                    $this->append($messages, $current, $fragment);
                }
            } else {
                $this->append($messages, $current, $html);
            }
        }
        if ($current !== '') {
            $messages[] = $current;
        }

        return $messages;
    }

    /** @param list<string> $messages */
    private function append(array &$messages, string &$current, string $block): void
    {
        if ($current !== '' && $this->length($current."\n\n".$block) > self::LIMIT) {
            $messages[] = $current;
            $current = '';
        }
        $current .= ($current === '' ? '' : "\n\n").$block;
    }

    /** @param array{text: string, style: string, heading: bool, row?: bool} $block */
    private function render(array $block): string
    {
        if ($block['row'] ?? false) {
            $lines = explode("\n", $block['text']);
            $title = array_shift($lines);

            return '<b>'.self::escape($title).'</b>'.($lines === [] ? '' : "\n".self::escape(implode("\n", $lines)));
        }
        $escaped = self::escape($block['text']);

        return $block['style'] === '' ? $escaped : '<'.$block['style'].'>'.$escaped.'</'.$block['style'].'>';
    }

    private function length(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** @return list<string> */
    private function split(string $text, int $limit = self::LIMIT): array
    {
        if ($this->length(self::escape($text)) <= $limit) {
            return [$text];
        }
        $parts = [];
        $remaining = $text;
        while ($this->length(self::escape($remaining)) > $limit) {
            // A codepoint is at most two UTF-16 units. Grow only inside budget.
            $size = min(mb_strlen($remaining), $limit);
            $prefix = mb_substr($remaining, 0, $size);
            while ($this->length(self::escape($prefix)) > $limit) {
                $prefix = mb_substr($prefix, 0, --$size);
            }
            $boundary = null;
            if (preg_match_all('/[.!?…](?:[»”"\']*)\s+/u', $prefix, $matches, PREG_OFFSET_CAPTURE)) {
                $match = $matches[0][array_key_last($matches[0])];
                $boundary = mb_strlen(substr($prefix, 0, $match[1] + strlen($match[0])));
            }
            if ($boundary === null || $boundary < intdiv($size, 3)) {
                if (preg_match_all('/\s+/u', $prefix, $matches, PREG_OFFSET_CAPTURE)) {
                    $match = $matches[0][array_key_last($matches[0])];
                    $boundary = mb_strlen(substr($prefix, 0, $match[1] + strlen($match[0])));
                } else {
                    // An unbroken oversized token has no safe word boundary.
                    $boundary = $size;
                }
            }
            $parts[] = rtrim(mb_substr($remaining, 0, $boundary));
            $remaining = ltrim(mb_substr($remaining, $boundary));
        }
        if ($remaining !== '') {
            $parts[] = $remaining;
        }

        return $parts;
    }
}
