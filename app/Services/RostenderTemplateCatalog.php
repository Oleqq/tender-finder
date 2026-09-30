<?php

namespace App\Services;

use App\Models\SourceFeed;
use App\Tenders\RostenderApiException;
use App\Tenders\RostenderSearchTemplate;
use Illuminate\Support\Facades\Cache;

class RostenderTemplateCatalog
{
    /** @return list<RostenderSearchTemplate> */
    public function available(): array
    {
        if (! app(RostenderAccessGate::class)->allowsDataProcessing()) {
            return [];
        }

        // A known, already connected template remains selectable when the
        // supplier's daily quota is exhausted. This does not call the API.
        if (! app(RostenderQuotaGuard::class)->normalCapacityAvailable()) {
            return $this->connectedTemplates();
        }

        try {
            return Cache::remember('rostender:search-templates:v1', now()->addMinutes(30), fn (): array => app(RostenderApiClient::class)->templates());
        } catch (RostenderApiException) {
            return $this->connectedTemplates();
        }
    }

    /** @return list<RostenderSearchTemplate> */
    private function connectedTemplates(): array
    {
        return SourceFeed::query()
            ->where('source', 'rostender')
            ->where('status', 'active')
            ->whereNotNull('source_identifier')
            ->orderBy('source_identifier')
            ->get(['source_identifier'])
            ->map(fn (SourceFeed $feed): RostenderSearchTemplate => new RostenderSearchTemplate(
                (int) $feed->source_identifier,
                'Подключённый шаблон #'.$feed->source_identifier,
            ))
            ->all();
    }

    public function contains(int $templateId): bool
    {
        return collect($this->available())->contains(
            fn (RostenderSearchTemplate $template): bool => $template->id === $templateId,
        );
    }
}
