<?php

namespace App\Console\Commands;

use App\Models\SourceFeed;
use App\Tenders\EisRssSource;
use App\Tenders\RssSourceException;
use Illuminate\Console\Command;
use Throwable;

class ProbeEisRss extends Command
{
    protected $signature = 'tenders:probe-eis-rss
        {url : RSS URL copied manually from EIS extended search}';

    protected $description = 'Safely fetch one allow-listed EIS RSS URL and report aggregate metadata without storing tenders.';

    public function handle(EisRssSource $source): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->components->error('The RSS preflight is restricted to local and test environments.');

            return self::FAILURE;
        }

        $startedAt = hrtime(true);

        try {
            $result = $source->fetch(new SourceFeed([
                'canonical_url' => (string) $this->argument('url'),
            ]));
        } catch (RssSourceException $exception) {
            $this->components->error("RSS preflight failed: {$exception->codeName}.");

            return self::FAILURE;
        } catch (Throwable) {
            $this->components->error('RSS preflight failed unexpectedly.');

            return self::FAILURE;
        }

        $items = $result->items;
        $latencyMilliseconds = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $this->components->info('RSS preflight succeeded. No records were written to the database.');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Response latency', "{$latencyMilliseconds} ms"],
                ['Items parsed', (string) count($items)],
                ['Items with registration number', (string) count(array_filter($items, fn ($item): bool => $item->regNumber !== null))],
                ['Items with publication date', (string) count(array_filter($items, fn ($item): bool => $item->publishedAt !== null))],
                ['Items with description', (string) count(array_filter($items, fn ($item): bool => $item->summary !== null))],
            ],
        );
        $this->line('Live polling remains disabled; this command does not print the URL, tender titles, links, IDs, or descriptions.');

        return self::SUCCESS;
    }
}
