<?php

namespace App\Http\Controllers;

use App\Services\LegalDocumentRegistry;
use App\Services\SubscriptionCatalog;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LegalDocumentController extends Controller
{
    public function show(string $document, LegalDocumentRegistry $documents): Response
    {
        $this->ensurePublished();

        return response()->view('legal', ['document' => $documents->document($document), 'company' => config('company')]);
    }

    public function info(): Response
    {
        $this->ensurePublished();

        return response()->view('legal', ['document' => null, 'company' => config('company'), 'offers' => SubscriptionCatalog::OFFERS]);
    }

    public function download(string $document, LegalDocumentRegistry $documents): BinaryFileResponse
    {
        $this->ensurePublished();
        $record = $documents->document($document);

        return response()->download($documents->originalPath($document), $record['filename'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function ensurePublished(): void
    {
        abort_unless((bool) config('tender.legal.documents_published', false), 503, 'Юридический документ пока не опубликован.');
    }
}
