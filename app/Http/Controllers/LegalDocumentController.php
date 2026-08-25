<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class LegalDocumentController extends Controller
{
    public function offer(): Response
    {
        return $this->show('offer', 'Черновик оферты Tender Finder');
    }

    public function privacy(): Response
    {
        return $this->show('privacy', 'Черновик политики конфиденциальности Tender Finder');
    }

    private function show(string $type, string $title): Response
    {
        abort_unless((bool) config('tender.legal.documents_published', false), 503, 'Юридический документ пока не опубликован.');

        return Inertia::render('LegalDocument', [
            'document' => [
                'type' => $type,
                'title' => $title,
            ],
        ]);
    }
}
