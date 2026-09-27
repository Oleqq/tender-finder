<?php

namespace App\Services;

use App\Models\SourceFeed;
use Illuminate\Support\Collection;

final class SberAstFeedService
{
    /** @return Collection<int, SourceFeed> */
    public function configuredFeeds(): Collection
    {
        $urls = config('tender.sber_ast.registry_urls', []);

        if (! is_array($urls)) {
            return collect();
        }

        $configured = collect($urls)
            ->filter(fn (mixed $url): bool => is_string($url) && $this->isPublicRegistryUrl($url))
            ->unique()
            ->values();

        SourceFeed::query()
            ->where('source', 'sber_ast')
            ->when(
                $configured->isNotEmpty(),
                fn ($query) => $query->whereNotIn('canonical_url', $configured->all()),
            )
            ->update(['status' => 'paused']);

        return $configured
            ->map(function (string $url): SourceFeed {
                $hash = hash('sha256', 'sber_ast:'.$url);

                return SourceFeed::query()->updateOrCreate(
                    ['url_hash' => $hash],
                    [
                        'source' => 'sber_ast',
                        'canonical_url' => $url,
                        'status' => 'active',
                        'poll_interval_seconds' => max(300, (int) config('tender.sber_ast.poll_interval_seconds', 3600)),
                    ],
                );
            })
            ->values();
    }

    private function isPublicRegistryUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && ($parts['scheme'] ?? null) === 'https'
            && ($host === 'sberbank-ast.ru' || str_ends_with($host, '.sberbank-ast.ru'));
    }
}
