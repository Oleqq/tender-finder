<?php

namespace App\Services;

use App\Tenders\RostenderApiException;
use App\Tenders\RostenderTemplateListItem;
use App\Tenders\RostenderTemplatePage;
use App\Tenders\RostenderTenderItem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class RostenderApiClient
{
    public function __construct(
        private readonly RostenderAccessGate $access,
        private readonly RostenderQuotaGuard $quota,
    ) {}

    public function template(int $templateId, int $page = 1): RostenderTemplatePage
    {
        $payload = $this->get('template/'.$templateId, [
            'page' => max(1, $page),
            'sort' => 'new-first',
        ]);
        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw new RostenderApiException('invalid_payload');
        }

        $items = [];

        foreach ($data as $item) {
            if (! is_array($item) || ! isset($item['id']) || ! is_numeric($item['id']) || (int) $item['id'] < 1) {
                continue;
            }

            $items[] = new RostenderTemplateListItem((int) $item['id']);
        }

        $meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];

        return new RostenderTemplatePage(
            $items,
            max(0, (int) ($meta['totalCount'] ?? count($items))),
            max(1, (int) ($meta['pageCount'] ?? 1)),
        );
    }

    public function tender(int $tenderId): RostenderTenderItem
    {
        $payload = $this->get((string) $tenderId);
        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw new RostenderApiException('invalid_payload');
        }

        return RostenderTenderItem::fromDetail($data);
    }

    /** @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $this->access->assertDataProcessingAllowed();
        $key = config('tender.rostender.api_key');

        if (! is_string($key) || $key === '') {
            throw new RostenderApiException('api_key_missing');
        }

        $reservation = $this->quota->reserve();

        try {
            $response = $this->request($key)->get($path, $query);
        } catch (ConnectionException) {
            $reservation->settle(false);
            throw new RostenderApiException('connection_failed');
        }

        $successful = $response->successful();
        $reservation->settle($successful);
        $this->observeQuota($response);

        if (! $successful) {
            throw new RostenderApiException($this->errorCode($response));
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['success'] ?? false) !== true) {
            throw new RostenderApiException('invalid_payload');
        }

        return $payload;
    }

    private function request(string $key): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('tender.rostender.base_url'), '/'))
            ->acceptJson()
            ->withHeaders(['X-API-KEY' => $key])
            ->timeout(max(1, (int) config('tender.rostender.request_timeout_seconds', 15)));
    }

    private function observeQuota(Response $response): void
    {
        $remaining = $response->header('X-RateLimit-Remaining');

        if (is_numeric($remaining)) {
            $this->quota->observeRemaining((int) $remaining);
        }
    }

    private function errorCode(Response $response): string
    {
        return match ($response->status()) {
            400 => 'invalid_request',
            401 => 'authentication_failed',
            403 => 'remote_quota_or_access_denied',
            404 => 'not_found',
            default => 'http_'.$response->status(),
        };
    }
}
