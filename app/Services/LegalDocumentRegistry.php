<?php

namespace App\Services;

final class LegalDocumentRegistry
{
    public const TYPES = ['terms', 'privacy', 'consent', 'offer'];

    public const EDITION = '2026-11-02';

    /** @return array<string, mixed> */
    public function document(string $type): array
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        $document = json_decode((string) file_get_contents(resource_path('legal/'.self::EDITION.'/'.$type.'.json')), true, 512, JSON_THROW_ON_ERROR);
        $document['version'] = self::EDITION.'-'.$type.'-'.substr($document['sha256'], 0, 12);

        return $document;
    }

    public function originalPath(string $type): string
    {
        $this->document($type);

        return resource_path('legal/'.self::EDITION.'/'.$type.'.docx');
    }

    public function text(string $type): string
    {
        $document = $this->document($type);
        $parts = [$document['title']];
        foreach ($document['blocks'] as $block) {
            $parts[] = $block['type'] === 'table'
                ? implode("\n", array_map(fn (array $row): string => implode(' | ', $row), $block['rows']))
                : $block['text'];
        }

        return implode("\n\n", $parts);
    }
}
