<?php

namespace App\Services;

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

        try {
            return Cache::remember('rostender:search-templates:v1', now()->addMinutes(30), fn (): array => app(RostenderApiClient::class)->templates());
        } catch (RostenderApiException) {
            return [];
        }
    }

    public function contains(int $templateId): bool
    {
        return collect($this->available())->contains(
            fn (RostenderSearchTemplate $template): bool => $template->id === $templateId,
        );
    }
}
