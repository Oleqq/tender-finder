<?php

namespace App\Console\Commands;

use App\Services\TenderGuruPreviewImportService;
use App\Tenders\TenderGuruPreviewException;
use Illuminate\Console\Command;
use Throwable;

class ImportTenderGuruPreview extends Command
{
    protected $signature = 'tenders:import-tenderguru-preview
        {query : One search phrase sent to TenderGuru public preview}';

    protected $description = 'Import one TenderGuru public preview response locally, without an API key, polling, or notifications.';

    public function handle(
        TenderGuruPreviewImportService $importer,
    ): int {
        if (! app()->environment('local', 'testing')) {
            $this->components->error('TenderGuru preview import is restricted to local and test environments.');

            return self::FAILURE;
        }

        $query = (string) $this->argument('query');

        try {
            $result = $importer->import($query);
        } catch (TenderGuruPreviewException $exception) {
            $this->components->error("TenderGuru preview import failed: {$exception->codeName}.");

            return self::FAILURE;
        } catch (Throwable) {
            $this->components->error('TenderGuru preview import failed unexpectedly.');

            return self::FAILURE;
        }

        $this->components->info("TenderGuru preview import finished: {$result->itemsCreated} new item(s), {$result->itemsSeen} item(s) seen, {$result->matchesCreated} new local match(es).");
        $this->line('No API key, credentials, polling, or Telegram notifications were used. The command does not print the search phrase or tender data.');

        return self::SUCCESS;
    }
}
