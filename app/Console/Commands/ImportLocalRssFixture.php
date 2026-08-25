<?php

namespace App\Console\Commands;

use App\Models\SourceFeed;
use App\Services\RssFixtureImportService;
use App\Tenders\EisRssSource;
use Illuminate\Console\Command;

class ImportLocalRssFixture extends Command
{
    protected $signature = 'tenders:import-fixture {fixture : initial or next}';

    protected $description = 'Import a synthetic EIS RSS fixture into a local or test database only.';

    public function handle(EisRssSource $source, RssFixtureImportService $importer): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->components->error('Synthetic RSS fixtures are restricted to local and test environments.');

            return self::FAILURE;
        }

        $fixture = (string) $this->argument('fixture');

        if (! in_array($fixture, ['initial', 'next'], true)) {
            $this->components->error('Use one of the prepared fixtures: initial or next.');

            return self::INVALID;
        }

        $feed = SourceFeed::query()->firstOrCreate(
            ['url_hash' => hash('sha256', 'local-synthetic-eis-rss')],
            [
                'canonical_url' => 'https://zakupki.gov.ru/epz/order/extendedsearch/rss.html?searchString=local-synthetic',
                'status' => 'active',
                'poll_interval_seconds' => 600,
            ],
        );

        $path = base_path("tests/Fixtures/eis-rss-{$fixture}.xml");
        $xml = file_get_contents($path);

        if ($xml === false) {
            $this->components->error('The bundled fixture could not be read.');

            return self::FAILURE;
        }

        $run = $importer->import($feed, $source->parse($xml));

        $this->components->info("Synthetic poll finished: {$run->items_created} new item(s), {$run->items_seen} item(s) seen.");
        $this->line('This command never fetches EIS over the network and does not enable live polling.');

        return self::SUCCESS;
    }
}
