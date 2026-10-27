<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PricingPageController extends Controller
{
    public function __invoke(string $theme = 'light'): BinaryFileResponse
    {
        abort_unless(in_array($theme, ['light', 'dark'], true), 404);

        $filename = $theme === 'dark'
            ? 'subscription-offer-preview-dark.html'
            : 'subscription-offer-preview.html';

        return response()->file(base_path('docs/'.$filename), [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
