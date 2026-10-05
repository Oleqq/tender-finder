<?php

namespace App\Services;

use App\Tenders\B2bCenterException;
use App\Tenders\B2bCenterTenderItem;
use Carbon\CarbonImmutable;
use Throwable;

final class B2bCenterSearchApiParser
{
    /** @param array<string, mixed> $data
     * @return array{items: list<B2bCenterTenderItem>, page_count: int}
     */
    public function parse(array $data): array
    {
        $trades = $data['trades'] ?? null;
        $pageCount = $data['page_count'] ?? null;
        $tabs = $data['tabs'] ?? null;
        if (($data['current_tab'] ?? null) !== 'actual' || ! is_array($trades)
            || ! is_int($pageCount) || $pageCount < 0 || ! is_array($tabs)) {
            throw new B2bCenterException('catalog_layout_changed');
        }

        $actualCount = null;
        foreach ($tabs as $tab) {
            if (is_array($tab) && ($tab['type'] ?? null) === 'actual' && is_int($tab['count'] ?? null)) {
                $actualCount = $tab['count'];
                break;
            }
        }
        if ($actualCount === null || ($trades === [] && $actualCount > 0)
            || ($trades !== [] && $pageCount === 0)) {
            throw new B2bCenterException('catalog_layout_changed');
        }

        $items = [];
        foreach ($trades as $trade) {
            if (! is_array($trade) || ! is_int($trade['trade_id'] ?? null) || $trade['trade_id'] <= 0
                || ! is_string($trade['url'] ?? null) || ! is_string($trade['description'] ?? null)) {
                continue;
            }

            $id = (string) $trade['trade_id'];
            $path = (string) parse_url($trade['url'], PHP_URL_PATH);
            if (! str_starts_with($path, '/') || preg_match('~^/(?:market|app/market-next)/[a-z0-9-]+/tender-'.$id.'/?$~i', $path) !== 1) {
                continue;
            }
            $url = 'https://www.b2b-center.ru'.rtrim($path, '/').'/';
            $title = TenderTitle::display(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($trade['description']), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
            if ($title === '') {
                continue;
            }
            $customer = is_string($trade['org_name_short'] ?? null)
                ? trim(html_entity_decode(strip_tags($trade['org_name_short']), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null;
            $region = is_string($trade['region'] ?? null) && trim($trade['region']) !== '' ? trim($trade['region']) : null;
            $published = $this->date($trade['date_published'] ?? null);
            $deadline = $this->date($trade['date_actual'] ?? null);
            $price = $this->price($trade['price'] ?? null);

            $items[$id] = new B2bCenterTenderItem(
                externalId: $id,
                regNumber: $id,
                canonicalUrl: $url,
                urlHash: hash('sha256', $url),
                title: $title,
                summary: null,
                publishedAt: $published,
                contentHash: hash('sha256', implode('|', [$id, $title, $customer ?? '', $price ?? '', $deadline?->toAtomString() ?? ''])),
                region: $region,
                budgetAmount: $price,
                deadlineAt: $deadline,
                metadata: ['customer' => $customer, 'b2b_center' => ['organizer' => $customer]],
            );
        }
        if ($trades !== [] && $items === []) {
            throw new B2bCenterException('catalog_layout_changed');
        }

        return ['items' => array_values($items), 'page_count' => $pageCount];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('d.m.Y H:i', $value, 'Europe/Moscow');
        } catch (Throwable) {
            return null;
        }
    }

    private function price(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/^\s*([\d\s.,]+)\s*(?:₽|руб)/u', $text, $match) !== 1) {
            return null;
        }
        $number = str_replace(',', '.', preg_replace('/\s+/u', '', $match[1]));

        return is_numeric($number) && (float) $number > 0 ? number_format((float) $number, 2, '.', '') : null;
    }
}
